<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Message;

/**
 * One sender picture lookup to do (#639). Dispatched by SenderPictureQueue
 * for a lookup it just claimed, and by `app:sender-pictures:refresh` for a
 * stale one; the handler runs SenderPictureResolver and, when a picture was
 * found, tells $userIds' open pages about it.
 *
 * `kind` as the enum's value and `userIds` as ids: a message carries scalars,
 * never entities. `userIds` is empty for a refresh — nobody is waiting on a
 * re-check, and a picture that changed reaches pages on their next render.
 */
readonly class ResolveSenderPictureMessage
{
    /**
     * @param list<int> $userIds
     */
    public function __construct(
        public string $kind,
        public string $subject,
        public array  $userIds = [],
    ) {}
}
