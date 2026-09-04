<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Infrastructure\Messaging\Message\ResortMailboxMessage;
use App\Repository\Mail\AccountRepository;
use App\Repository\Mail\ContactRepository;
use App\Repository\Mail\MessageRepository;
use App\Repository\Mail\MessageThreadRepository;
use App\Repository\User\UserRepository;
use App\Service\Mail\MessageCategorizer;
use App\Service\Monitoring\ProcessHeartbeatService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Re-files one person's mail after they changed what sorts it.
 *
 * The same work `app:backfill category` does for every mailbox, for one — and
 * deliberately the same shape, because the shape is what makes it affordable:
 * keyset pagination in batches, a flush and a clear per batch so the identity
 * map never holds the mailbox, then ONE statement per account to resolve each
 * thread's category from its messages.
 *
 * NOTHING HERE TALKS TO ANOTHER MACHINE. MessageCategorizer reads only stored
 * data — headers, provider labels, sender, the model's stored verdict — so a
 * re-sort is a scan and a column write. That is the reason this can be a job
 * somebody's settings page dispatches rather than a command they have to ask an
 * administrator to run.
 *
 * IT IS ALSO WHY IT IS SAFE TO RUN TWICE. The categoriser is a pure function of
 * persisted state, so a retried or duplicated envelope writes the same answers
 * over the same rows. There is no counter to double and no message to send.
 */
#[AsMessageHandler]
final readonly class ResortMailboxHandler
{
    /** Same as CategoryBackfillTask's, and for the same reason. */
    private const int BATCH_SIZE = 500;

    public function __construct(
        private UserRepository          $users,
        private AccountRepository       $accounts,
        private MessageRepository       $messages,
        private MessageThreadRepository $threads,
        private ContactRepository       $contacts,
        private MessageCategorizer      $categorizer,
        private EntityManagerInterface  $em,
        private ProcessHeartbeatService $heartbeats,
        private LoggerInterface         $logger,
    ) {
    }

    public function __invoke(ResortMailboxMessage $message): void
    {
        $user = $this->users->find($message->userId);

        if (null === $user) {
            return;
        }

        $sorting        = $user->categorySorting;
        $correspondents = $this->contacts->findCorrespondentEmails($user);
        $filed          = 0;

        foreach ($this->accounts->findBy(['usr' => $user, 'isActive' => true]) as $account) {
            $accountId = (int) $account->id;
            $lastId    = 0;

            while (true) {
                // `true` for includeCategorized: every message, not only the
                // ones without a category. A re-sort that skipped the mail that
                // already has one would re-sort nothing at all.
                $batch = $this->messages->findPendingCategorization($accountId, true, $lastId, self::BATCH_SIZE);

                if ([] === $batch) {
                    break;
                }

                foreach ($batch as $row) {
                    $lastId        = (int) $row->id;
                    $row->category = $this->categorizer->categorize($row, $correspondents, $sorting);
                    ++$filed;
                }

                $this->heartbeats->beatWhileBusy();
                $this->em->flush();

                // Cleared per batch, so the identity map never holds the
                // mailbox. $account and $user are detached by this too, which
                // is why $sorting and $correspondents are read once above
                // rather than off the entity inside the loop.
                $this->em->clear();
            }

            // After clear(), so this is pure DBAL: one statement that resolves
            // each thread's category from its messages, most-recent-wins.
            $fresh = $this->accounts->find($accountId);

            if (null !== $fresh) {
                $this->threads->recomputeCategoriesForAccount($fresh);
            }
        }

        $this->logger->info('Mailbox re-sorted after a sorting change', [
            'user'     => $message->userId,
            'messages' => $filed,
            'source'   => $sorting->source,
            'override' => $sorting->overrideProvider,
        ]);
    }
}
