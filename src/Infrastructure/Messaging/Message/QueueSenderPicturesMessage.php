<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Message;

/**
 * Look up pictures for everyone who has written to one user lately (#639).
 *
 * Dispatched when the user switches a picture source on and, for every user,
 * when an administrator allows lookups — otherwise only senders who write
 * AFTER that moment would ever get a picture, and the inbox already on screen
 * would stay initials for weeks. Also what `app:sender-pictures:backfill`
 * dispatches.
 *
 * Queued rather than done in the request, because "everyone who has written
 * lately" is a GROUP BY over the user's mail and a few hundred claims.
 */
readonly class QueueSenderPicturesMessage
{
    public function __construct(
        public int $userId,
    ) {}
}
