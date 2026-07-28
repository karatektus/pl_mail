<?php

declare(strict_types=1);

namespace App\Twig;

use App\Entity\MessageThread;
use App\Service\Mail\ThreadParticipants;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `thread_participants(thread)` — the names for a conversation row's who
 * column, already localised.
 */
final class ThreadParticipantsExtension extends AbstractExtension
{
    public function __construct(
        private readonly ThreadParticipants  $participants,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('thread_participants', $this->forThread(...)),
        ];
    }

    /**
     * @return list<string>
     */
    public function forThread(MessageThread $thread): array
    {
        return $this->participants->forThread($thread, $this->translator->trans('thread_row.me'));
    }
}
