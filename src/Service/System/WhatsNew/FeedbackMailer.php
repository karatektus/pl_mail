<?php

declare(strict_types=1);

namespace App\Service\System\WhatsNew;

use App\Entity\Mail\Account;
use App\Service\Imap\MessageSendService;
use App\Service\Mail\MailSenderRegistry;
use App\Service\System\AppVersion;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Sends "Send feedback" from the What's new dialog, as the user (#165).
 *
 * ── Through their own account ────────────────────────────────────────────────
 * plMail is a mail client and has no outbound relay of its own (see MAILER_DSN
 * in the configuration reference), so feedback is a mail the person sends,
 * from an account they chose, through the same MailSenderRegistry their other
 * mail leaves by. That is what makes it honest — their address is on it, a
 * copy is in their Sent folder, and a reply arrives like any other mail — and
 * what keeps it working behind a firewall where nothing but their mail
 * provider is reachable.
 *
 * ── In English, whatever the interface speaks ────────────────────────────────
 * The subject and the footer are fixed English, not translations. They are
 * read by whoever collects the feedback, not by the person writing it, and the
 * person's own words go in as they wrote them.
 *
 * ── The Sent copy ────────────────────────────────────────────────────────────
 * Provider APIs file their own. For SMTP it is appended over IMAP, and a
 * failure there costs the copy and nothing else: the mail has left by then,
 * and reporting "not sent" for mail that was sent invites a second one.
 */
final readonly class FeedbackMailer
{
    /** The largest screenshot accepted, decoded. A full-window PNG is well under. */
    public const int SCREENSHOT_MAX_BYTES = 6 * 1024 * 1024;

    public function __construct(
        private MailSenderRegistry $senders,
        private MessageSendService $sendService,
        private AppVersion         $appVersion,
        private LoggerInterface    $logger,
        #[Autowire('%app.feedback_to%')]
        private string             $recipient,
    ) {
    }

    /** Where it goes, for the line under the form that says so. */
    public function recipient(): string
    {
        return trim($this->recipient);
    }

    /**
     * @param ?string $rating     'better', 'same' or 'worse', or null for none given
     * @param ?string $screenshot raw PNG or JPEG bytes
     *
     * @return bool whether the mail left
     */
    public function send(Account $account, ?string $rating, string $message, ?string $screenshot = null): bool
    {
        $email = $this->build($account, $rating, $message, $screenshot);

        try {
            $sender = $this->senders->resolve($account);

            if (false === $sender->send($email, $account)) {
                return false;
            }
        } catch (Throwable $failure) {
            $this->logger->error('FeedbackMailer: feedback could not be sent', [
                'account'   => $account->id,
                'exception' => $failure,
            ]);

            return false;
        }

        if (false === $sender->filesSentCopy()) {
            try {
                $this->sendService->appendToSentFolder($email, $account);
            } catch (Throwable $failure) {
                $this->logger->warning('FeedbackMailer: feedback was sent, filing the Sent copy failed', [
                    'account'   => $account->id,
                    'exception' => $failure,
                ]);
            }
        }

        return true;
    }

    public function build(Account $account, ?string $rating, string $message, ?string $screenshot = null): Email
    {
        $from    = (string) ($account->displayAddress ?? '');
        $version = $this->appVersion->label();
        $commit  = $this->appVersion->commit();
        $label   = match ($rating) {
            'better' => 'Better',
            'same'   => 'Same as before',
            'worse'  => 'Worse',
            default  => null,
        };

        $footer = "--\n"
            . (null !== $label ? 'Since the update, plMail is: ' . $label . "\n" : '')
            . 'plMail ' . $version . (null !== $commit ? ' (' . $commit . ')' : '') . "\n";

        $email = new Email()
            ->from(new Address($from, $account->senderNameFor($from) ?? ''))
            ->to($this->recipient())
            ->subject(sprintf('plMail %s feedback%s', $version, null !== $label ? ': ' . $label : ''))
            ->text(rtrim($message) . "\n\n" . $footer);

        if (null !== $screenshot) {
            $type = str_starts_with($screenshot, "\x89PNG") ? 'image/png' : 'image/jpeg';

            $email->attach($screenshot, 'image/png' === $type ? 'screenshot.png' : 'screenshot.jpg', $type);
        }

        return $email;
    }

    /**
     * The bytes of a `data:` URL the browser captured, when it is a PNG or a
     * JPEG of a sane size; null for anything else. Checked on the bytes, not
     * on what the URL claims to be.
     */
    public static function decodeScreenshot(string $dataUrl): ?string
    {
        if (1 !== preg_match('#^data:image/(?:png|jpeg);base64,([A-Za-z0-9+/=]+)$#', trim($dataUrl), $m)) {
            return null;
        }

        if (strlen($m[1]) > (int) ceil(self::SCREENSHOT_MAX_BYTES / 3) * 4) {
            return null;
        }

        $bytes = base64_decode($m[1], true);

        if (false === $bytes || strlen($bytes) > self::SCREENSHOT_MAX_BYTES) {
            return null;
        }

        return str_starts_with($bytes, "\x89PNG\r\n\x1a\n") || str_starts_with($bytes, "\xFF\xD8\xFF") ? $bytes : null;
    }
}
