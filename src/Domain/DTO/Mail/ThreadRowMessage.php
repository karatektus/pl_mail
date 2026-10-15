<?php

declare(strict_types=1);

namespace App\Domain\DTO\Mail;

use App\Domain\Helper\OwnAddressHelper;
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
        /**
         * Whether the message is filed under Sent or Drafts — which only the
         * account's own mail is. It is how a From at a catch-all domain is
         * told apart: the user's own when this is true, a colleague with a
         * mailbox of their own when it is not. See OwnAddressHelper.
         */
        public bool $filedAsSent = false,
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
            // Asked only for a From at a catch-all domain. For every other
            // message the answer is never read, and asking would walk the
            // labels of each message on the row — a lazy load apiece.
            true === ($message->account ?? null)?->catchesAddress($message->fromAddress)
                && true === OwnAddressHelper::sentByAccount($message),
        );
    }
}
