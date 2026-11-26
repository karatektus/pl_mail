<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Infrastructure\Messaging\Message\RecategoriseMessagesMessage;
use App\Repository\Mail\Contact\ContactRepository;
use App\Repository\Mail\Message\MessageRuleTabRepository;
use App\Repository\User\User\UserRepository;
use App\Service\Mail\InboxCategoryManager;
use App\Service\Mail\MessageCategorizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Sorts again the mail a deleted or changed filter let go of (#762): each
 * message through MessageCategorizer, the way a re-sort does, then its
 * conversation's tab from its messages, the way removing a tab does.
 *
 * Idempotent like both of those: the release already cleared the filter's
 * column, so a redelivered envelope writes the same answers over the same rows.
 */
#[AsMessageHandler]
final readonly class RecategoriseMessagesHandler
{
    public function __construct(
        private UserRepository           $users,
        private ContactRepository        $contacts,
        private MessageRuleTabRepository $messages,
        private MessageCategorizer       $categorizer,
        private InboxCategoryManager     $categories,
        private EntityManagerInterface   $em,
    ) {}

    public function __invoke(RecategoriseMessagesMessage $message): void
    {
        $user = $this->users->find($message->userId);

        if (null === $user) {
            return;
        }

        $sorting        = $user->categorySorting;
        $correspondents = $this->contacts->findCorrespondentEmails($user);
        $threadIds      = [];

        foreach ($this->messages->findForResort($message->messageIds) as $row) {
            // Another person's message cannot be named here — the ids come from
            // the filter's own release — but the owner is checked anyway, as
            // everywhere else a worker acts on ids it was handed.
            if ($row->account->usr?->id !== $user->id) {
                continue;
            }

            $row->category = $this->categorizer->categorize($row, $correspondents, $sorting);

            if (null !== $row->thread?->id) {
                $threadIds[$row->thread->id] = $row->thread->id;
            }
        }

        $this->em->flush();

        // The conversations follow their messages, most-recent-wins, and the
        // apps are told about the ones that moved.
        $this->categories->resort(array_values($threadIds));
        $this->em->clear();
    }
}
