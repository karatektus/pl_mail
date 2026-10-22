<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Message;

/**
 * Delete one card on a CardDAV server, after its contact was deleted here.
 *
 * Carries the card's address rather than the contact's id, because the contact
 * is gone by the time this runs: a merge or a purge removes the row in the
 * same flush that dispatches this. See ContactSyncTracker.
 */
final readonly class DeleteRemoteContactMessage
{
    public function __construct(
        public int $addressBookId,
        public string $href,
        public ?string $etag,
    ) {}
}
