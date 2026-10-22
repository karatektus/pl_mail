<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Message;

/**
 * Bring one mirrored address book and its CardDAV server back into agreement.
 *
 * Dispatched by the sweep (`app:contacts:sync --stale`), by subscribing, by the
 * sync-now button, and after a push that let the server win a conflict. An
 * edit on the contacts page does not dispatch this: it is carried out by
 * PushAddressBookMessage, on another queue, and this run sends whatever is
 * still pending before it reads anything.
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
