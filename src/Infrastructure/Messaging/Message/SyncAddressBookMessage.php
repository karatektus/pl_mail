<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Message;

/**
 * Bring one mirrored address book and its CardDAV server back into agreement.
 *
 * Dispatched by the sweep (`app:contacts:sync --stale`), by subscribing, and by
 * every edit made on the contacts page — the edit is saved locally and marked
 * pending, and this is what carries it to the server without holding the HTTP
 * request open while it goes.
 *
 * One book per message for the reason SyncCalendarMessage gives: books fail
 * independently, and a shared one whose permission was revoked must not stop
 * the user's own from syncing.
 */
final readonly class SyncAddressBookMessage
{
    public function __construct(
        public int $addressBookId,
    ) {}
}
