<?php

declare(strict_types=1);

namespace App\Domain\DTO\Calendar;

/**
 * What the remote assigned after accepting a create or an update.
 *
 * Two strings, and both are load-bearing. The id is the only way a locally
 * created event is ever found again — without it the next pull sees a stranger
 * and writes a second copy of the meeting the user just made. The etag is what
 * makes the pull after a push cheap and, more importantly, correct: the engine
 * compares it against what comes back and skips the row, so a push immediately
 * followed by a pull does not re-apply the remote's echo of the local edit over
 * a change the user made in between.
 *
 * A DTO rather than a bare id string because the etag has to come back with it.
 * Returning the id and asking the caller to re-read the event for its version
 * is a second round trip per write, and a race: something else can edit the
 * event between the write and the read, and the etag stored would then belong
 * to a revision this side never saw.
 *
 * $etag is nullable for the providers that mint no version marker on write.
 * Storing null there rather than a placeholder is deliberate — it makes the
 * next pull treat that event as changed and re-read it, which is the safe
 * answer when the remote will not say what version it holds.
 */
final readonly class RemoteWriteResult
{
    public function __construct(
        public string  $remoteId,
        public ?string $etag = null,
    ) {
    }
}
