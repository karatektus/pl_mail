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
     * @param list<ThreadRowMessage> $messages oldest first
     * @param string                 $snippet  MessageSnippet of the latest message
     */
    public function __construct(
        public array $messages,
        public string $snippet,
    ) {
        $this->latest = [] === $messages ? null : $messages[count($messages) - 1];

        $draft = null;
        foreach ($messages as $message) {
            if ($message->isDraft) {
                $draft = $message;
            }
        }
        $this->draft = $draft;
    }
}
