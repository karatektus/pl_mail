<?php

declare(strict_types=1);

namespace App\Domain\Enum;

/**
 * Per-address state on an account. Mutually exclusive, so an invalid
 * combination (a disabled primary) is unrepresentable.
 *
 *  - Primary  : display address + default From. Exactly one per account.
 *  - Active   : a valid From option and counts as "ours" for matching.
 *  - Inactive : excluded from From, and excluded from ownership matching —
 *               so an address Microsoft reports but that is actually served by
 *               a different account (and may not even be connected here) stops
 *               being claimed. Also dropped from reply recipients.
 */
enum EmailAliasStatus: string
{
    case Primary  = 'primary';
    case Active   = 'active';
    case Inactive = 'inactive';

    /** Appears in the From dropdown. */
    public function isSendable(): bool
    {
        return EmailAliasStatus::Inactive !== $this;
    }

    /** Counts as this account's own address for ownership + reply self-exclusion. */
    public function countsForOwnership(): bool
    {
        return EmailAliasStatus::Inactive !== $this;
    }
}
