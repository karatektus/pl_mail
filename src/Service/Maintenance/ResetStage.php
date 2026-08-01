<?php

declare(strict_types=1);

namespace App\Service\Maintenance;

/**
 * The reset, as a browser offers it: a ladder rather than a flag set.
 *
 * `app:reset` takes independent switches, which is right for a command line —
 * you can ask for contacts without mailboxes if that is genuinely what you
 * want. A row of independent checkboxes is the wrong shape for a destructive
 * control in a browser, because the combination that gets clicked is the one
 * nobody thought about. So the panel offers six steps, each destroying strictly
 * more than the one above it, and every step says what it takes. Each maps onto
 * flags the command already has; none of them can produce a combination the
 * command could not.
 *
 * Monitoring data is not on the ladder. The admin panel already has its own
 * buttons for that — "Clear" in the log browser, "Prune stale heartbeats" in
 * Maintenance — and wiping the log and the heartbeat table from inside the
 * panel that renders them makes the app look broken at exactly the moment the
 * operator is watching it to see whether the reset worked. `--keep-monitoring`
 * stays on the command for the headless case; here it is simply always kept,
 * right up until the full reset takes every table anyway.
 */
enum ResetStage: string
{
    /** Messages, threads, parts and the queue. Accounts keep syncing. */
    case SyncedMail = 'synced-mail';

    /** The above plus folders and labels. */
    case Structure = 'structure';

    /** The above plus harvested contacts. */
    case Contacts = 'contacts';

    /** The above plus the accounts themselves and their stored passwords. */
    case Accounts = 'accounts';

    /** Every table, every user, and the files on disk. */
    case Full = 'full';

    /** The above plus the generated secrets. Requires a restart afterwards. */
    case FullWithSecrets = 'full-secrets';

    /**
     * The flags `app:reset` would need for this rung, or null for the two full
     * resets — those are a different operation, not a wider scope.
     */
    public function scope(): ?ResetScope
    {
        return match ($this) {
            self::SyncedMail => new ResetScope(),
            self::Structure  => new ResetScope(mailboxes: true),
            self::Contacts   => new ResetScope(mailboxes: true, contacts: true),
            // accounts implies mailboxes inside ResetScope; contacts is carried
            // explicitly because the ladder is cumulative and this rung is below
            // the contacts one.
            self::Accounts   => new ResetScope(contacts: true, accounts: true),
            self::Full,
            self::FullWithSecrets => null,
        };
    }

    public function isFull(): bool
    {
        return null === $this->scope();
    }

    public function rotatesSecrets(): bool
    {
        return self::FullWithSecrets === $this;
    }

    /**
     * Whether clicking is not enough.
     *
     * The top four are undone by a resync — annoying, measured in hours, not
     * lost. The bottom two delete the operator, their accounts' stored
     * passwords and the files on disk, and nothing brings those back. Those two
     * ask for the instance name to be typed out, which a misdirected click
     * cannot produce.
     */
    public function needsTypedConfirmation(): bool
    {
        return $this->isFull();
    }

    /**
     * @return list<self>
     */
    public static function ordinary(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $stage): bool => false === $stage->isFull()));
    }

    /**
     * @return list<self>
     */
    public static function unrecoverable(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $stage): bool => $stage->isFull()));
    }

    public function translationKey(): string
    {
        return 'admin.reset.stage.' . str_replace('-', '_', $this->value);
    }

    /** The CSRF token id for this stage's form, and nothing else's. */
    public function csrfTokenId(): string
    {
        return 'admin_reset_' . $this->value;
    }
}
