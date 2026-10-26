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
     * @param list<ThreadRowMessage> $messages  oldest first
     * @param string                 $snippet   MessageSnippet of the latest message
     * @param bool                   $encrypted whether the latest message is encrypted:
     *                                          the row's lock, in front of the subject
     * @param bool                   $narrowed  whether $messages leaves out the other
     *                                          side of a partly deleted conversation
     *                                          (App\Service\Mail\BinView)
     */
    public function __construct(
        public array $messages,
        public string $snippet,
        public bool $encrypted = false,
        public bool $narrowed = false,
    ) {
        $this->latest = [] === $messages ? null : $messages[count($messages) - 1];

        $draft          = null;
        $unread         = false;
        $hasAttachments = false;
        $lastAt         = null;

        foreach ($messages as $message) {
            if ($message->isDraft) {
                $draft = $message;
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
    }
}
