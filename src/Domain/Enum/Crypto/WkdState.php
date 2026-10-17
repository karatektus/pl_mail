<?php

declare(strict_types=1);

namespace App\Domain\Enum\Crypto;

/**
 * Whether plMail's Web Key Directory hands out a person's key for one of
 * their addresses, and if not, why not. See WkdDirectory.
 *
 * Published: it does. SwitchedOff: the person said not to. NoKey: there is no
 * active, unexpired key for the address to publish. NotSignInAddress: the
 * address is one of the person's, but not the one they sign in with — the
 * only address on this installation that an administrator, not the person,
 * put there, and so the only one plMail can vouch for.
 */
enum WkdState: string
{
    case Published        = 'published';
    case SwitchedOff      = 'switched_off';
    case NoKey            = 'no_key';
    case NotSignInAddress = 'not_sign_in_address';

    public function isPublished(): bool
    {
        return self::Published === $this;
    }

    /**
     * Whether the person can switch publishing on or off here. Not for an
     * address plMail would never publish: a switch that changes nothing is a
     * switch that lies.
     */
    public function isSwitchable(): bool
    {
        return match ($this) {
            self::Published, self::SwitchedOff, self::NoKey => true,
            self::NotSignInAddress                          => false,
        };
    }
}
