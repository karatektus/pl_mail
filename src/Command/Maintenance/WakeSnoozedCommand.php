<?php

declare(strict_types=1);

namespace App\Command\Maintenance;

use App\Repository\Mail\MessageThreadRepository;
use App\Service\Mail\ThreadSnoozeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Returns snoozed conversations to the Inbox once their time is up.
 *
 * This is the half of snooze that makes it a snooze rather than a filing
 * cabinet: without it a snoozed thread leaves the inbox and never comes back.
 * It is scheduled every minute — the unit a user picks a wake time in — and is
 * cheap when there is nothing due, which is almost always.
 *
 * Idempotent, and safe to run concurrently with itself: waking an already-woken
 * thread only re-asserts the Inbox label. That matters because the scheduler
 * replays a missed run when a worker comes back up.
 */
#[AsCommand(
    name: 'app:mail:wake-snoozed',
    description: 'Return snoozed conversations to the inbox once their wake time has passed.',
)]
final class WakeSnoozedCommand extends Command
{
    /**
     * Per run, so one enormous backlog cannot hold the worker for minutes.
     * Whatever is left is picked up by the next run a minute later.
     */
    private const int BATCH = 200;

    public function __construct(
        private readonly MessageThreadRepository $threadRepository,
        private readonly ThreadSnoozeService $snoozeService,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $due = $this->threadRepository->findDueSnoozed(new \DateTimeImmutable(), self::BATCH);

        if ([] === $due) {
            return Command::SUCCESS;
        }

        $woken = 0;

        foreach ($due as $thread) {
            try {
                $this->snoozeService->wake($thread);
                ++$woken;
            } catch (\Throwable $error) {
                // One thread that cannot be woken — a deleted account, a
                // provider that rejected the label change — must not strand
                // every other thread behind it. The snooze stays set, so the
                // next run tries again.
                $io->warning(sprintf(
                    'Could not wake thread %d: %s',
                    (int) $thread->getId(),
                    $error->getMessage(),
                ));

                $this->entityManager->clear();
            }
        }

        $io->success(sprintf('Woke %d conversation(s).', $woken));

        return Command::SUCCESS;
    }
}
