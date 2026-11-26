<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\Mail\MessageThread;
use App\Service\Mail\ThreadParticipants;
use App\Service\Mail\ThreadPinService;
use App\Service\Mail\ThreadRows;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `thread_participants(thread)` — the names for a conversation row's who
 * column, already localised — and `thread_row(thread)`, the rest of what that
 * row reads from the thread's messages (App\Service\Mail\ThreadRows). And
 * `email_pinned(message)` and `conversation_pinned(thread)` for the thumbtacks.
 */
final class ThreadParticipantsExtension extends AbstractExtension
{
    public function __construct(
        private readonly ThreadParticipants  $participants,
        private readonly TranslatorInterface $translator,
        private readonly ThreadRows          $rows,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('thread_participants', $this->forThread(...)),
            new TwigFunction('thread_row', $this->rows->for(...)),
            // Whether an email row shows pinned (#615): its own pin, or being
            // the newest received email of a pinned conversation.
            new TwigFunction('email_pinned', ThreadPinService::emailPinned(...)),
            // And a conversation: its own pin, or one on any of its emails.
            new TwigFunction('conversation_pinned', ThreadPinService::conversationPinned(...)),
        ];
    }

    /**
     * @return list<string>
     */
    public function forThread(MessageThread $thread): array
    {
        // The row's fields, not $thread->messages: on a list page those were
        // never hydrated, and walking them would load every body on the page.
        return $this->participants->forMessages(
            $this->rows->for($thread)->messages,
            $thread->account,
            $this->translator->trans('thread_row.me'),
        );
    }
}
