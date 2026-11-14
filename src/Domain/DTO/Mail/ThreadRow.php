<?php

declare(strict_types=1);

namespace App\Domain\DTO\Mail;

/**
 * What one conversation row needs from the thread's messages, already reduced.
 *
 * `latest` and `draft` are the two answers the template used to compute with
 * `|last` and `|filter(m => m.isDraft)|last` over the whole collection; they are
 * computed the same way here, over the same order (receivedAt, id — the
 * association's #[ORM\OrderBy]), so the row cannot tell the difference.
 */
final readonly class ThreadRow
{
    public ?ThreadRowMessage $latest;
    public ?ThreadRowMessage $draft;

    /**
     * What the row says about the messages it shows, for a row that shows
     * only some ($narrowed). The thread's own counters cover every message,
     * deleted ones too, and are what an ordinary row reads.
     */
    public int $count;
    public bool $unread;
    public bool $hasAttachments;
    public ?\DateTimeImmutable $lastAt;

    /**
     * Whether the conversation has been answered from here: the newest mail
     * that is not a draft is either one you sent or one marked \Answered.
     * The row's reply arrow (#608). The newest rather than any, because an
     * arrow on a conversation whose latest mail is still waiting for you would
     * say the opposite of what is true.
     */
    public bool $replied;

    /**
     * When the newest draft on the row was last written, or null without one.
     * The date the Drafts list shows: lastAt is the conversation's, which a
     * draft does not move, so a reply written just now read "last week".
     */
    public ?\DateTimeImmutable $draftAt;

    /**
     * @param list<ThreadRowMessage> $messages  oldest first
     * @param string                 $snippet   MessageSnippet of the latest message
     * @param bool                   $encrypted whether the latest message is encrypted:
     *                                          the row's lock, in front of the subject
     * @param bool                   $narrowed  whether $messages leaves out the other
     *                                          side of a partly deleted conversation
     *                                          (App\Service\Mail\BinView)
     * @param list<AttachmentChip>   $attachments the files on those messages, for a
     *                                          row that names them (#608); empty
     *                                          when the row only needs to know
     *                                          there are some — see ThreadRows
     */
    public function __construct(
        public array $messages,
        public string $snippet,
        public bool $encrypted = false,
        public bool $narrowed = false,
        public array $attachments = [],
    ) {
        $this->latest = [] === $messages ? null : $messages[count($messages) - 1];

        $draft          = null;
        $unread         = false;
        $hasAttachments = false;
        $lastAt         = null;
        $draftAt        = null;

        foreach ($messages as $message) {
            if ($message->isDraft) {
                $draft = $message;

                if (null === $draftAt || $message->writtenAt() > $draftAt) {
                    $draftAt = $message->writtenAt();
                }
            }

            $unread         = $unread || $message->unread;
            $hasAttachments = $hasAttachments || $message->hasAttachments;

            if (null === $lastAt || $message->effectiveAt() > $lastAt) {
                $lastAt = $message->effectiveAt();
            }
        }

        $this->draft          = $draft;
        $this->count          = count($messages);
        $this->unread         = $unread;
        $this->hasAttachments = $hasAttachments;
        $this->lastAt         = $lastAt;
        $this->replied        = self::repliedTo($messages);
        $this->draftAt        = $draftAt;
    }

    /** @param list<ThreadRowMessage> $messages */
    private static function repliedTo(array $messages): bool
    {
        foreach (array_reverse($messages) as $message) {
            if (true === $message->isDraft) {
                continue;
            }

            return $message->filedAsSent || $message->answered;
        }

        return false;
    }
}
