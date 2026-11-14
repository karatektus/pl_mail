<?php

declare(strict_types=1);

namespace App\Domain\DTO\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageFlag;
use App\Domain\Enum\Mail\SendFailure;
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
        /** Why this draft's last send finally failed, or null — the row's warning (#125). */
        public ?SendFailure $sendFailure = null,
        /**
         * Whether the message carries Trash. A conversation can be partly in
         * the bin, and its row in each list shows one side of it (BinView).
         */
        public bool $trashed = false,
        /** Unread — what MessageThread::$unreadCount counts. */
        public bool $unread = false,
        public bool $hasAttachments = false,
        /** Carries \Answered: somebody replied to it from this mailbox (#608). */
        public bool $answered = false,
        /** When the row last changed — for a draft written here, its last save. */
        public ?DateTimeImmutable $updatedAt = null,
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
            $message->sendFailure,
            self::wearsTrash($message),
            null === $message->seenAt,
            true === $message->hasAttachments,
            $message->hasFlag(MessageFlag::ANSWERED),
            $message->updatedAt,
        );
    }

    /** Arrival, else sending, else creation — the order the thread view uses. */
    public function effectiveAt(): DateTimeImmutable
    {
        return $this->receivedAt ?? $this->sentAt ?? $this->createdAt;
    }

    /**
     * When a draft was last written: the provider's date for one synced from
     * elsewhere (a re-save there is a new message with a new date), our last
     * save for one written here, which has no receivedAt. effectiveAt() falls
     * back to createdAt instead and would date every draft by its first save.
     * MessageThreadRepository::sortList() orders Drafts by the same rule.
     */
    public function writtenAt(): DateTimeImmutable
    {
        return $this->receivedAt ?? $this->updatedAt ?? $this->createdAt;
    }

    private static function wearsTrash(Message $message): bool
    {
        foreach ($message->labels as $label) {
            if (LabelRole::Trash === $label->role) {
                return true;
            }
        }

        return false;
    }
}
