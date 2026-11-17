<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Enum\Mail\ListLevel;
use App\Entity\User\User;
use App\Repository\Mail\Message\MessageListRepository;
use App\Repository\Mail\MessageThread\MessageThreadRepository;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Which lists a person is shown: conversations, or one row per email (#615).
 *
 * The two repositories answer the same questions under the same method names
 * — both build App\Repository\Mail\MessageThread\MailListQuery, one at each
 * level — so a list action asks this for the person's and calls the method it
 * always called. The switch is the person's "Conversation view", flipped from
 * the list toolbar or Settings, and read on every request, so flipping it
 * changes the very next list drawn.
 */
final readonly class MailLists
{
    public function __construct(
        private MessageThreadRepository $conversations,
        private MessageListRepository   $emails,
    ) {
    }

    /** Anybody who is not a plMail user — an API token's — gets conversations, the default. */
    public function for(UserInterface $user): MessageThreadRepository|MessageListRepository
    {
        return $user instanceof User && ListLevel::Email === ListLevel::of($user) ? $this->emails : $this->conversations;
    }
}
