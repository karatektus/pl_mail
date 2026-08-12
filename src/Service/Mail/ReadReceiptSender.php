<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\DTO\Mail\ReadReceiptDecision;
use App\Domain\Enum\Mail\ReadReceiptMode;
use App\Domain\Helper\MessageIdHelper;
use App\Entity\Mail\Message;
use App\Service\Mail\Mime\DispositionNotificationPart;
use App\Service\Mail\Mime\DispositionReportPart;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\TextPart;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Builds and sends the MDN itself.
 *
 * An MDN is a two-part `multipart/report`: prose for a person, and a block of
 * fields for software. Both halves are mandatory — the field block is the
 * receipt, and the prose is what the sender's client shows if it has no idea
 * what an MDN is, which many still do not. A report with only the field block
 * arrives as an empty message with an attachment.
 *
 * Sent through MailSenderRegistry rather than through a mailer of its own, so
 * it leaves by whatever transport the account already uses — Gmail API, Graph,
 * or SMTP. A receipt sent over a different path than the account's own mail
 * would fail SPF for the domain, and an MDN landing in spam is worse than no
 * MDN: it still confirms the read to anyone who looks in the spam folder.
 *
 * NOT filed to Sent, deliberately. The user did not write this, and a
 * conversation that gains a machine-generated message every time it is opened
 * is a conversation nobody can read. That also means send failures are logged
 * and swallowed here: there is no row to mark failed, and the read that
 * triggered this has already happened and must not be undone by it.
 */
final readonly class ReadReceiptSender
{
    public function __construct(
        private MailSenderRegistry     $senderRegistry,
        private TranslatorInterface    $translator,
        private EntityManagerInterface $em,
        private LoggerInterface        $logger,
    ) {
    }

    /**
     * Send the receipt this decision authorises.
     *
     * Re-checks isSendable() rather than trusting the caller. Every caller
     * already checks, which is exactly why this checks too: the cost of one
     * redundant condition is nothing, and the cost of a future caller that
     * forgets is mail sent on a user's behalf that they configured against.
     *
     * @return bool whether an MDN actually left
     */
    public function send(Message $message, ReadReceiptDecision $decision): bool
    {
        if (false === $decision->isSendable()) {
            return false;
        }

        $account = $message->account;

        $notifyTo       = (string) $decision->notifyTo;
        $finalRecipient = (string) $decision->finalRecipient;

        try {
            $email = $this->build($message, $decision, $notifyTo, $finalRecipient);

            $sent = $this->senderRegistry->resolve($account)->send($email, $account);
        } catch (\Throwable $e) {
            $this->logger->error('ReadReceiptSender: MDN could not be sent', [
                'messageId' => $message->id,
                'error'     => $e->getMessage(),
            ]);

            return false;
        }

        if (false === $sent) {
            $this->logger->warning('ReadReceiptSender: transport refused the MDN', [
                'messageId' => $message->id,
            ]);

            return false;
        }

        // The receipt has been answered; the request must not be answerable
        // twice. Clearing the flag is what stops a second manual confirmation,
        // a re-read after marking unread, or a replayed job from sending the
        // same confirmation again — an MDN is not idempotent at the far end,
        // where each copy is another line saying the mail was read.
        $message->readReceiptRequested = false;
        $this->em->flush();

        $this->logger->info('ReadReceiptSender: MDN sent', [
            'messageId' => $message->id,
            'mode'      => $decision->mode->value,
        ]);

        return true;
    }

    /**
     * The report itself.
     *
     * The `Content-Type: multipart/report; report-type=disposition-notification`
     * comes from DispositionReportPart; setBody() is used rather than
     * ->text()/->html() because Email::generateBody() can only produce
     * text/html/mixed/related shapes and there is no way to reach a report
     * through it.
     *
     * Auto-Submitted: auto-replied is on it because this message is generated
     * by software answering other mail, which is the exact definition RFC 3834
     * gives — and a conforming MTA at the other end uses it to avoid answering
     * this with an auto-reply of its own. Without it, an MDN and a vacation
     * responder can bounce off each other indefinitely.
     */
    public function build(
        Message             $message,
        ReadReceiptDecision $decision,
        string              $notifyTo,
        string              $finalRecipient,
    ): Email {
        // The column stores the canonical bracket-less form (see
        // MessageSendService::stampMessageId); every header form of a
        // Message-ID wears the angle brackets, so they go back on here.
        $bare       = MessageIdHelper::normalise((string) $message->messageId);
        $originalId = '' !== $bare ? '<' . $bare . '>' : '';

        $subject = $this->translator->trans('message.read_receipt.mdn.subject', [
            '%subject%' => $message->subject ?? '',
        ]);

        $human = $this->translator->trans('message.read_receipt.mdn.body', [
            '%recipient%' => $finalRecipient,
            '%subject%'   => $message->subject ?? '',
            '%date%'      => (new \DateTimeImmutable())->format('r'),
        ]);

        $email = new Email()
            ->from(new Address($finalRecipient))
            ->to(new Address($notifyTo))
            ->subject($subject);

        $headers = $email->getHeaders();
        $headers->addTextHeader('Auto-Submitted', 'auto-replied');

        // Threads the receipt onto the conversation it is about, for clients
        // that show it inline rather than as a separate message.
        if ('' !== $originalId) {
            $headers->addTextHeader('In-Reply-To', $originalId);
            $headers->addTextHeader('References', $originalId);
        }

        $email->setBody(new DispositionReportPart(
            new TextPart($human, 'utf-8', 'plain'),
            new DispositionNotificationPart(
                $this->fields($decision, $finalRecipient, $originalId),
            ),
        ));

        return $email;
    }

    /**
     * The machine-readable field block (RFC 8098 §3.1).
     *
     * Final-Recipient carries the `rfc822;` address-type prefix because the
     * field is defined as type-then-address and a bare address is not parseable
     * — this is the field most often written wrong, and getting it wrong makes
     * the receipt unattributable.
     *
     * Disposition is `<action-mode>/<sending-mode>; displayed`. The action mode
     * says whether a human or the software decided, and it is taken from the
     * setting that actually fired rather than fixed: reporting a manual
     * confirmation as automatic (or the reverse) is a lie about whether a
     * person saw the message, which is the only thing the receipt is for.
     *
     * `displayed` and not `read`: RFC 8098 has no `read` disposition, and what
     * is being asserted is precisely the weaker claim — the message was
     * rendered, which is not proof anybody took it in.
     */
    private function fields(ReadReceiptDecision $decision, string $finalRecipient, string $originalId): string
    {
        $mode = ReadReceiptMode::Never === $decision->mode
            ? ReadReceiptMode::Ask->dispositionMode()
            : $decision->mode->dispositionMode();

        $lines = [
            'Reporting-UA: plMail; plMail',
            'Final-Recipient: rfc822;' . $finalRecipient,
        ];

        if ('' !== $originalId) {
            $lines[] = 'Original-Message-ID: ' . $originalId;
        }

        $lines[] = 'Disposition: ' . $mode . '; displayed';

        return implode("\r\n", $lines) . "\r\n";
    }
}
