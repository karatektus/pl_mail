<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Helper\MessageIdHelper;
use App\Entity\Mail\Message;
use App\Repository\Mail\MessageRepository;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * The bad-news return leg: a delivery status notification comes back, and the
 * message it is about learns it never arrived.
 *
 * Structurally this is ReadReceiptCorrelator's twin — same multipart/report
 * envelope, same Original-Message-ID lookup, same "stamp the sent row" payoff
 * — and it is written as a separate service anyway, because the two differ on
 * every judgement that matters:
 *
 *   · A read receipt is noise once correlated and gets filed off the Inbox.
 *     A BOUNCE IS NOT FILED. Its body is the SMTP transcript, and that is
 *     frequently the only place the actual reason exists in readable form. It
 *     stays exactly where it landed, unread, for a person to open.
 *
 *   · An MDN is only ever one thing. A DSN is four — delivered, relayed,
 *     delayed, failed — and three of them are not bounces. `delayed` in
 *     particular is a message STILL IN FLIGHT, and stamping it as undelivered
 *     would tell the user their mail failed at the exact moment it is being
 *     retried. Only `Action: failed` is a bounce here.
 *
 * WHAT IS DELIBERATELY NOT DONE
 * ─────────────────────────────
 * No classification. The status code is stored as the reporting MTA wrote it
 * and never reduced to a "permanent" flag, no address is disabled, nothing is
 * removed from a contact list, and no resend is attempted. plMail records what
 * a server said about one delivery attempt; deciding an address is dead on
 * that basis is a conclusion this codebase has been wrong about before.
 */
final readonly class BounceCorrelator
{
    /**
     * The recipient-level actions a DSN can report, of which exactly one means
     * the mail is not going to arrive.
     */
    private const string FAILED = 'failed';

    public function __construct(
        private MessageRepository $messages,
        private HeaderNormalizer  $headers,
        private LoggerInterface   $logger,
    ) {
    }

    /**
     * Whether this message is a delivery status notification at all.
     *
     * The Content-Type is the reliable arm. The body arm is narrower than the
     * MDN correlator's on purpose: `Final-Recipient` alone appears in enough
     * quoted and forwarded mail to be a poor signal, so a field block only
     * counts when it carries BOTH a recipient and an action — the pair that
     * only a real per-recipient DSN block has.
     */
    public function isDeliveryStatusNotification(Message $message): bool
    {
        $contentType = $this->headers->first($message->headers, 'content-type');

        if (null !== $contentType) {
            $lowered = strtolower($contentType);

            if (
                true === str_contains($lowered, 'report-type=delivery-status')
                || true === str_contains($lowered, 'report-type="delivery-status"')
                || true === str_contains($lowered, 'message/delivery-status')
            ) {
                return true;
            }
        }

        return null !== $this->field($message, 'Final-Recipient')
            && null !== $this->field($message, 'Action');
    }

    /**
     * Match a bounce to the message it is about and record the failure.
     *
     * @return Message|null the sent message that was stamped, or null when the
     *                      report named nothing we hold, or reported anything
     *                      other than a hard failure
     */
    public function correlate(Message $dsn): ?Message
    {
        $action = $this->field($dsn, 'Action');

        if (null === $action || false === str_starts_with(strtolower($action), self::FAILED)) {
            // Delivered, relayed, delayed, or a report whose field block did
            // not survive ingestion. None of those is a bounce, and a delayed
            // notice rendered as one is actively misleading.
            return null;
        }

        $originalId = $this->extractOriginalId($dsn);

        if (null === $originalId) {
            return null;
        }

        $original = $this->messages->findOneForAccountByMessageId($dsn->account, $originalId);

        if (null === $original) {
            return null;
        }

        // First bounce wins, for the same reason the first read does: a message
        // to several recipients can come back several times, and each later
        // report would otherwise overwrite whose address failed with whoever
        // bounced most recently.
        if (null !== $original->bouncedAt) {
            return $original;
        }

        $original->bouncedAt         = $dsn->receivedAt ?? new DateTimeImmutable();
        $original->bounceStatus      = $this->trimTo($this->field($dsn, 'Status'), 16);
        $original->bounceRecipient   = $this->trimTo($this->address($this->field($dsn, 'Final-Recipient')), 320);
        $original->bounceDiagnostic  = $this->trimTo($this->field($dsn, 'Diagnostic-Code'), 2000);

        $this->logger->info('BounceCorrelator: bounce matched', [
            'dsnId'      => $dsn->id,
            'originalId' => $original->id,
            'status'     => $original->bounceStatus,
        ]);

        return $original;
    }

    /**
     * One field out of the DSN's per-recipient block, wherever the block ended
     * up.
     *
     * Both bodies are searched for the same reason the MDN correlator searches
     * both: the field block reaches us as plain text from some providers, as
     * part of the HTML alternative from others. Folded continuation lines are
     * joined, because Diagnostic-Code is long and is wrapped by almost every
     * MTA that emits it.
     */
    private function field(Message $message, string $name): ?string
    {
        $pattern = '/^' . preg_quote($name, '/') . ':[ \t]*(.*(?:\r?\n[ \t]+.*)*)$/mi';

        foreach ([$message->bodyText, $message->bodyHtml] as $body) {
            if (null === $body || '' === $body) {
                continue;
            }

            if (1 === preg_match($pattern, $body, $matches)) {
                $value = trim(preg_replace('/\r?\n[ \t]+/', ' ', $matches[1]) ?? $matches[1]);

                if ('' !== $value) {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * The address out of an `rfc822; someone@example.com` field value.
     *
     * The address type prefix is mandatory in the RFC and absent in the wild
     * often enough to be worth tolerating, so everything up to and including
     * the first semicolon is dropped only when there is one.
     */
    private function address(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $semicolon = strpos($value, ';');

        if (false !== $semicolon) {
            $value = substr($value, $semicolon + 1);
        }

        return trim($value, " \t<>") ?: null;
    }

    /**
     * The Message-ID of the mail that bounced.
     *
     * Original-Message-ID first — the field RFC 3461 provides for exactly this
     * — then any Message-ID found in the BODY. The fallback is sound because a
     * DSN's own Message-ID lives in its header bag, never its body: a
     * Message-ID appearing in the text can only have come from the returned
     * copy of the original that MTAs attach as message/rfc822 or
     * text/rfc822-headers, which is precisely the message we are looking for.
     */
    private function extractOriginalId(Message $dsn): ?string
    {
        foreach (['Original-Message-ID', 'Message-ID'] as $field) {
            $raw = $this->field($dsn, $field);

            if (null === $raw) {
                continue;
            }

            if (1 === preg_match('/<?([^>\r\n\s]+)>?/', $raw, $matches)) {
                $normalised = MessageIdHelper::normalise($matches[1]);

                if ('' !== $normalised) {
                    return $normalised;
                }
            }
        }

        return null;
    }

    private function trimTo(?string $value, int $length): ?string
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return mb_substr($value, 0, $length);
    }
}
