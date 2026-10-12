<?php

declare(strict_types=1);

namespace App\Twig;

use App\Domain\DTO\Mail\SenderLabel;
use App\Entity\Mail\MessageThread;
use App\Service\Mail\ThreadParticipants;
use App\Service\Mail\ThreadRows;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `thread_participants(thread)` — the names for a conversation row's who
 * column, already localised — and `thread_row(thread)`, the rest of what that
 * row reads from the thread's messages (App\Service\Mail\ThreadRows).
 *
 * `thread_senders(thread)` is the same cast with the domain each one writes
 * from, and `sender_label(name, address)` the same for a row that is a single
 * message. The row prints those, so that a name anybody can type never stands
 * alone in the list; see SenderLabel.
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
            new TwigFunction('thread_senders', $this->sendersOf(...)),
            new TwigFunction('sender_label', SenderLabel::of(...)),
        ];
    }

    /**
     * @return list<SenderLabel>
     */
    public function sendersOf(MessageThread $thread): array
    {
        return $this->participants->labelsForMessages(
            $this->rows->for($thread)->messages,
            $thread->account,
            $this->translator->trans('thread_row.me'),
        );
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
