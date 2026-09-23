<?php

declare(strict_types=1);

namespace App\Domain\DTO\Mail;

use App\Entity\Mail\Message;
use DateTimeImmutable;

/**
 * The part of one message a conversation ROW reads — and nothing else.
 *
 * A list row walks every message of its thread (ThreadParticipants wants the
 * whole cast), but of each one it wants a sender, the recipients, a few dates
 * and the draft flag. Hydrating Message for that dragged every body, header
 * blob and search vector of every message on the page into PHP; a fifty-row
 * inbox of long threads was megabytes of mail nobody on the page could see.
 * See App\Service\Mail\ThreadRows.
 *
 * The property names are Message's own, deliberately: ThreadParticipants and
 * the row template read either one without knowing which they were given.
 */
final readonly class ThreadRowMessage
{
    /**
     * @param array<int, array{name?: ?string, address?: ?string}>|null $toAddresses
     */
    public function __construct(
        public ?int $id,
        public ?string $fromAddress,
        public ?string $fromName,
        public ?array $toAddresses,
        public bool $isDraft,
        public ?DateTimeImmutable $receivedAt,
        public ?DateTimeImmutable $sentAt,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $submissionSendAt,
    ) {
    }

    /** For a thread whose messages are already in memory — the thread view, a sync. */
    public static function of(Message $message): self
    {
        return new self(
            $message->id,
            $message->fromAddress,
            $message->fromName,
            $message->toAddresses,
            $message->isDraft(),
            $message->receivedAt,
            $message->sentAt,
            $message->createdAt,
            $message->submissionSendAt,
        );
    }
}
