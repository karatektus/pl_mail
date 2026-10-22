<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Message;

/**
 * Send one address book's pending edits to its CardDAV server, and nothing
 * else — no pull.
 *
 * Dispatched by ContactSyncTracker after every edit to a synced contact. It is
 * SyncAddressBookMessage's push half on its own, because the two belong on
 * different queues: this is plMail writing outward and goes on `export`, the
 * read that follows is the sweep's and stays on `ingest`. See messenger.yaml.
 */
final readonly class PushAddressBookMessage
{
    public function __construct(
        public int $addressBookId,
    ) {}
}
