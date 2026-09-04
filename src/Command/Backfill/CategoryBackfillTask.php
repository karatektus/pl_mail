<?php

declare(strict_types=1);

namespace App\Command\Backfill;

use App\Entity\Mail\Account;
use App\Repository\Mail\AccountRepository;
use App\Repository\Mail\ContactRepository;
use App\Repository\Mail\MessageRepository;
use App\Repository\Mail\MessageThreadRepository;
use App\Service\Mail\MessageCategorizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Recomputes Message::category (and each thread's resolved category) from
 * persisted data — no mail server contact at all. This is why the category
 * signals (gmailLabelIds, raw headers) live on the row: change
 * MessageCategorizer, re-run this, see the result. No resync.
 *
 * Prompts whether to re-run messages that already have a category. Answering
 * no processes only category IS NULL rows (cheap, post-sync top-up); yes
 * re-runs everything, which is what you want after editing the classifier.
 *
 * Keyset pagination by id so each batch can flush + clear without a
 * server-side cursor, matching SafeHtmlBackfillTask. The cursor itself is
 * MessageRepository::findPendingCategorization().
 */
final readonly class CategoryBackfillTask implements BackfillTaskInterface
{
    private const int BATCH_SIZE = 500;

    public function __construct(
        private AccountRepository       $accountRepository,
        private MessageRepository       $messageRepository,
        private MessageThreadRepository $threadRepository,
        private ContactRepository       $contactRepository,
        private MessageCategorizer      $categorizer,
        private EntityManagerInterface  $em,
    ) {}

    public function getName(): string
    {
        return 'category';
    }

    public function getDescription(): string
    {
        return 'Recompute message and thread inbox categories from stored headers/labels.';
    }

    public function run(SymfonyStyle $io): int
    {
        $force = $io->confirm('Re-run messages that already have a category?', false);

        $accounts = $this->accountRepository->findBy(['isActive' => true]);

        if (count($accounts) === 0) {
            $io->warning('No active accounts.');

            return Command::SUCCESS;
        }

        foreach ($accounts as $account) {
            $this->runForAccount($io, $account, $force);
        }

        return Command::SUCCESS;
    }

    private function runForAccount(SymfonyStyle $io, Account $account, bool $force): void
    {
        $io->section(sprintf('Account #%d (%s)', $account->id, $account->email));

        $total = $this->messageRepository->countPendingCategorization((int) $account->id, $force);

        if (0 === $total) {
            $io->text('Nothing to categorise.');

            return;
        }

        $correspondents = $this->contactRepository->findCorrespondentEmails($account->usr);
        $accountId      = (int) $account->id;

        $io->progressStart($total);

        $lastId    = 0;
        $processed = 0;

        while (true) {
            $messages = $this->messageRepository->findPendingCategorization(
                $accountId,
                $force,
                $lastId,
                self::BATCH_SIZE,
            );

            if (count($messages) === 0) {
                break;
            }

            foreach ($messages as $message) {
                $lastId = (int) $message->id;

                $message->category = $this->categorizer->categorize($message, $correspondents, $account->usr?->categorySorting);

                ++$processed;
                $io->progressAdvance();
            }

            $this->em->flush();
            $this->em->clear();
        }

        $io->progressFinish();

        // Resolve each thread most-recent-wins from its messages, in one
        // statement. Runs after clear(), so it is pure DBAL.
        $threads = $this->threadRepository->recomputeCategoriesForAccount($account);

        $io->success(sprintf('Categorised %d message(s), resolved %d thread(s).', $processed, $threads));
    }
}
