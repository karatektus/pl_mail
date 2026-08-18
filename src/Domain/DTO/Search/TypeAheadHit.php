<?php

declare(strict_types=1);

namespace App\Domain\DTO\Search;

/**
 * One row of the list that appears under the search box while you type.
 *
 * A conversation, not a message: the row opens the thread, and two replies in
 * the same conversation matching the same word would otherwise spend two of
 * the ten places on one answer. The fields are the ones the row draws and
 * nothing else — deliberately not the MessageThread entity, because hydrating
 * ten threads and their accounts costs more than the query that found them.
 */
final readonly class TypeAheadHit
{
    public function __construct(
        public int                 $threadId,
        public ?string             $subject,
        public ?string             $fromName,
        public ?string             $fromAddress,
        public ?\DateTimeImmutable $receivedAt,
    ) {
    }

    /** Whoever it is from, however little of that the message told us. */
    public function sender(): string
    {
        return $this->fromName ?: ($this->fromAddress ?? '');
    }
}
