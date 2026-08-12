<?php

declare(strict_types=1);

namespace App\Domain\Enum\Mail;

/**
 * What this mailbox does when a sender asks to be told the mail was read.
 *
 * `Never` is the default and the only safe one to assume. A read receipt is a
 * confirmation that a specific address is live, is monitored, and was reading
 * at a specific minute — which is exactly what a sender fishing for that
 * information wants, and they get it by setting one header. So a user who has
 * never opened this setting emits nothing, and turning it on is a deliberate
 * act rather than something they discover having done.
 *
 * The three states are ordered by how much they give away, and
 * ReadReceiptPolicy only ever moves a decision *down* this list (Always → Ask),
 * never up: a mismatched Disposition-Notification-To is downgraded, and nothing
 * anywhere promotes a mode the user did not choose.
 */
enum ReadReceiptMode: string
{
    case Never = 'never';
    case Ask = 'ask';
    case Always = 'always';

    /**
     * The RFC 8098 disposition-mode this choice produces.
     *
     * `Ask` is manual-action/MDN-sent-manually because a human pressed the
     * button; `Always` is automatic-action/MDN-sent-automatically because the
     * software decided. The distinction is not cosmetic — it is what tells the
     * receiving end whether a person actually looked at the message or whether
     * a rule fired, and misreporting it makes the receipt a lie.
     */
    public function dispositionMode(): string
    {
        return match ($this) {
            self::Always => 'automatic-action/MDN-sent-automatically',
            // Never never produces an MDN at all; the arm exists so the match
            // is total and a future caller cannot get a silent null.
            self::Ask, self::Never => 'manual-action/MDN-sent-manually',
        };
    }

    /**
     * Whether this mode emits a receipt without asking the user first.
     */
    public function isAutomatic(): bool
    {
        return self::Always === $this;
    }

    public static function fromSetting(mixed $stored): self
    {
        if (false === is_string($stored)) {
            return self::Never;
        }

        return self::tryFrom($stored) ?? self::Never;
    }
}
