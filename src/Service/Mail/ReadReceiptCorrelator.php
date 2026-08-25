<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageFlag;
use App\Domain\Helper\MessageIdHelper;
use App\Entity\Mail\Message;
use App\Repository\Label\LabelRepository;
use App\Repository\Mail\MessageRepository;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * The return leg: an MDN comes back, and the message it is about learns it was
 * read.
 *
 * Without this half the feature is a header. A user ticks "request a read
 * receipt", the receipt arrives days later as a message titled "Read: your
 * subject" whose body is a paragraph of MIME jargon and an attachment called
 * `MDNPart1.txt`, and the sent message it refers to says nothing at all. The
 * answer is delivered and unreadable, which is the same as not delivering it.
 *
 * So: recognise the report, pull Original-Message-ID out of it, find our sent
 * message by that id, stamp it, and get the report itself out of the way.
 *
 * WHAT "OUT OF THE WAY" MEANS
 * ───────────────────────────
 * Marked read and taken off the Inbox label — not deleted. Deleting mail the
 * user was actually sent is not this service's call to make, and a receipt is
 * occasionally the thing someone needs to produce as evidence. Read-and-filed
 * keeps it findable in Archive and search while keeping it out of the unread
 * count and off the inbox list, which is the whole complaint.
 *
 * NO PROPAGATION, deliberately. Marking the MDN read here writes seenAt and
 * the local flag but queues no provider job, so no outbound flag op fires for
 * a message the user never touched — and the next inbound flag pass finding
 * the server still saying unread is free to correct us, because
 * flagsTouchedAt is not set. The row is tidied locally; the server's opinion
 * of it still wins.
 */
final readonly class ReadReceiptCorrelator
{
    public function __construct(
        private MessageRepository $messages,
        private LabelRepository   $labels,
        private HeaderNormalizer  $headers,
        private LoggerInterface   $logger,
    ) {
    }

    /**
     * Whether this message is a disposition notification.
     *
     * Read off the stored Content-Type, which every provider builder puts in
     * the normalised header bag. `report-type=disposition-notification` is the
     * only reliable signal: multipart/report alone also covers bounces, and
     * the subject line ("Read:", "Gelesen:", "Lu :") is per-locale guesswork.
     *
     * The second arm catches senders that emit the field block as the whole
     * body rather than wrapping it in a report. That is not conforming, and it
     * is common enough that refusing to read it would strand real receipts.
     *
     * BOTH ARMS REFUSE A BOUNCE FIRST, and that is not a formality. A DSN
     * carries Original-Message-ID in exactly the same field block, so without
     * the guard a bounce satisfies the second arm, gets correlated here, and
     * stamps `Read at …` on a message that was never delivered — the worst
     * available answer, since it is not merely missing information but the
     * precise opposite of the truth. `Disposition:` and `Action:` are the two
     * field blocks' mutually exclusive discriminators; that is what is used.
     */
    public function isDispositionNotification(Message $message): bool
    {
        $contentType = $this->headers->first($message->headers, 'content-type');

        if (null !== $contentType) {
            $lowered = strtolower($contentType);

            if (
                true === str_contains($lowered, 'report-type=delivery-status')
                || true === str_contains($lowered, 'report-type="delivery-status"')
                || true === str_contains($lowered, 'message/delivery-status')
            ) {
                return false;
            }

            if (
                true === str_contains($lowered, 'report-type=disposition-notification')
                || true === str_contains($lowered, 'report-type="disposition-notification"')
            ) {
                return true;
            }

            if (true === str_contains($lowered, 'message/disposition-notification')) {
                return true;
            }
        }

        if (true === $this->looksLikeDeliveryStatus($message)) {
            return false;
        }

        return null !== $this->originalIdInBody($message);
    }

    /**
     * Match a receipt to the message it is about and record the read.
     *
     * @return Message|null the sent message that was stamped, or null if the
     *                      receipt named nothing we hold
     */
    public function correlate(Message $mdn): ?Message
    {
        $originalId = $this->extractOriginalId($mdn);

        if (null === $originalId) {
            // A report with no Original-Message-ID is legal — the field is
            // optional — and useless to us: there is nothing to attach it to.
            // Left in the mailbox as an ordinary message rather than filed
            // away, because an unattributable receipt is exactly the thing a
            // person may want to read for themselves.
            return null;
        }

        $original = $this->messages->findOneForAccountByMessageId($mdn->account, $originalId);

        if (null === $original) {
            return null;
        }

        // First read wins. A recipient with three devices can send three
        // receipts for one message, and each later one would otherwise walk
        // the timestamp forward to whenever the mail was last displayed —
        // turning "read at 09:02" into a field that keeps changing. What was
        // asked for is when they first saw it.
        if (null === $original->readReceiptAt) {
            $original->readReceiptAt = $mdn->receivedAt ?? new DateTimeImmutable();
        }

        $this->suppress($mdn);

        $this->logger->info('ReadReceiptCorrelator: receipt matched', [
            'mdnId'      => $mdn->id,
            'originalId' => $original->id,
        ]);

        return $original;
    }

    /**
     * Take the report out of the inbox without taking it out of the mailbox.
     */
    private function suppress(Message $mdn): void
    {
        if (null === $mdn->seenAt) {
            $mdn->seenAt = new DateTimeImmutable();
            $mdn->addFlag(MessageFlag::SEEN);

            $thread = $mdn->thread;

            if (null !== $thread && $thread->unreadCount > 0) {
                // The thread's counter was incremented for this message when it
                // arrived; it is not unread any more and the badge has to agree.
                --$thread->unreadCount;
            }
        }

        $user = $mdn->account->usr;

        if (null === $user) {
            return;
        }

        $inbox = $this->labels->findOneByRoleForUser(LabelRole::Inbox, $user);

        if (null !== $inbox) {
            $mdn->removeLabel($inbox);
        }
    }

    /**
     * A DSN field block, recognised by the one field an MDN never has.
     *
     * `Action:` is per-recipient delivery status — failed, delayed, delivered,
     * relayed — and has no counterpart in a disposition notification, whose
     * equivalent field is `Disposition:`. Matching at the start of a line is
     * what keeps the word "action" in ordinary prose from counting.
     */
    private function looksLikeDeliveryStatus(Message $message): bool
    {
        foreach ([$message->bodyText, $message->bodyHtml] as $body) {
            if (null === $body || '' === $body) {
                continue;
            }

            if (1 === preg_match('/^Action:[ \t]*\S/mi', $body)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The Original-Message-ID field, if the report's field block reached us as
     * body text at all.
     *
     * Strictly the body: this doubles as the second detection signal, and an
     * In-Reply-To fallback in here would make every ordinary reply in the
     * mailbox look like a disposition notification.
     *
     * Both bodies are searched because the field block of an MDN reaches us
     * differently per provider — sometimes as the plain body, sometimes folded
     * into the HTML alternative, sometimes as a part that never becomes either.
     */
    private function originalIdInBody(Message $message): ?string
    {
        foreach ([$message->bodyText, $message->bodyHtml] as $body) {
            if (null === $body || '' === $body) {
                continue;
            }

            if (1 === preg_match('/Original-Message-ID:\s*<?([^>\r\n\s]+)>?/i', $body, $matches)) {
                $normalised = MessageIdHelper::normalise($matches[1]);

                if ('' !== $normalised) {
                    return $normalised;
                }
            }
        }

        return null;
    }

    /**
     * The Message-ID the receipt is about, canonicalised the way the column
     * stores it.
     *
     * The field block first, then the report's own In-Reply-To as a genuine
     * fallback: an MDN is supposed to reference the message it answers, and
     * when the field block did not survive ingestion that reference is the only
     * thing left pointing at the right row. Safe to consult here — by the time
     * this runs, isDispositionNotification() has already established that this
     * is a report and not a reply.
     */
    private function extractOriginalId(Message $message): ?string
    {
        $fromBody = $this->originalIdInBody($message);

        if (null !== $fromBody) {
            return $fromBody;
        }

        $inReplyTo = $message->inReplyTo;

        if (true === is_array($inReplyTo)) {
            foreach ($inReplyTo as $candidate) {
                $normalised = MessageIdHelper::normalise((string) $candidate);

                if ('' !== $normalised) {
                    return $normalised;
                }
            }
        }

        return null;
    }
}
