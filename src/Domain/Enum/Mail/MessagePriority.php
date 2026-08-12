<?php

declare(strict_types=1);

namespace App\Domain\Enum\Mail;

/**
 * How urgent the sender says a message is.
 *
 * There is no single header for this — there are two competing conventions and
 * every client reads a different one, so a message that sets only one of them
 * is "normal" to half the world. `X-Priority` is the older Outlook/Eudora
 * numeric scale (1 highest, 5 lowest); `Importance` (RFC 4021) is the word
 * form and is what Gmail, Graph and modern IMAP clients actually surface.
 * Both are written on the way out, which is why the mapping lives here rather
 * than at the call site: they must never disagree.
 *
 * Normal is stored explicitly rather than as NULL. NULL means "the user never
 * expressed a priority" and emits no headers at all — which is not the same
 * statement as "this is ordinary", and a recipient's client treats an absent
 * header and an explicit Normal identically anyway. Keeping them apart costs
 * nothing and lets the composer show the difference between untouched and
 * deliberately reset.
 */
enum MessagePriority: string
{
    case High = 'high';
    case Normal = 'normal';
    case Low = 'low';

    /**
     * The numeric `X-Priority` value. The scale runs 1 (highest) to 5 (lowest)
     * and clients that read it only ever look at the leading digit.
     */
    public function xPriority(): string
    {
        return match ($this) {
            self::High => '1',
            self::Normal => '3',
            self::Low => '5',
        };
    }

    /**
     * The RFC 4021 `Importance` value. Capitalised the way the RFC's examples
     * write it; the field is case-insensitive but some older clients are not.
     */
    public function importance(): string
    {
        return match ($this) {
            self::High => 'High',
            self::Normal => 'Normal',
            self::Low => 'Low',
        };
    }

    /**
     * Read a priority back off an inbound header bag.
     *
     * `Importance` wins over `X-Priority` when both are present: it is the
     * standardised field, and a sender that writes both is writing the numeric
     * one for compatibility with clients that predate the standard.
     */
    public static function fromHeaders(?string $importance, ?string $xPriority): ?self
    {
        if (null !== $importance) {
            $word = strtolower(trim($importance));

            $found = self::tryFrom($word);

            if (null !== $found) {
                return $found;
            }

            // Graph and some gateways say "normal"/"high"/"low"; Outlook has
            // also been seen emitting "Urgent" and "Non-Urgent".
            if ('urgent' === $word) {
                return self::High;
            }

            if ('non-urgent' === $word) {
                return self::Low;
            }
        }

        if (null !== $xPriority) {
            // "1 (Highest)" is a legal value — only the leading digit counts.
            $digit = substr(ltrim($xPriority), 0, 1);

            return match ($digit) {
                '1', '2' => self::High,
                '3' => self::Normal,
                '4', '5' => self::Low,
                default => null,
            };
        }

        return null;
    }
}
