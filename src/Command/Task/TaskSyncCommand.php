<?php

declare(strict_types=1);

namespace App\Command\Task;

use App\Infrastructure\Messaging\Message\SyncTaskListMessage;
use App\Repository\Task\TaskListRepository;
use App\Service\Demo\DemoMode;
use DateTimeImmutable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsCommand(
    name: 'app:tasks:sync',
    description: 'Dispatch a two-way sync for connected task lists — all of them, one, or only those due',
)]
final class TaskSyncCommand extends Command
{
    private const string STALE_AFTER = '-14 minutes';

    public function __construct(
        private readonly TaskListRepository  $lists,
        private readonly MessageBusInterface $bus,
        private readonly DemoMode            $demoMode,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('list-id', InputArgument::OPTIONAL, 'Sync a single task list by ID; omit for all connected ones');
        $this->addOption('stale', null, InputOption::VALUE_NONE, 'Only lists not synced recently — what the scheduler runs');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // A demo has no provider to talk to; CalendarSyncCommand says why this
        // has to refuse rather than fail on a timer.
        if (true === $this->demoMode->isEnabled()) {
            $io->note('Demo mode is on — task sync is disabled.');

            return Command::SUCCESS;
        }

        $listId = $input->getArgument('list-id');

        if (null !== $listId) {
            $list = $this->lists->find((int) $listId);

            if (null === $list || false === $list->isSynced()) {
                $io->error(sprintf('Task list %s is not connected to a remote.', $listId));

                return Command::FAILURE;
            }

            $lists = [$list];
        } else {
            // A far-future cutoff means everything: one query, one definition of
            // what a syncable list is.
            $lists = $this->lists->findDueForSync(
                new DateTimeImmutable(true === $input->getOption('stale') ? self::STALE_AFTER : '+1 year'),
            );
        }

        foreach ($lists as $list) {
            $this->bus->dispatch(new SyncTaskListMessage((int) $list->id));
            $io->text(sprintf('→ dispatched sync for %s (#%d)', $list->name, (int) $list->id));
        }

        $io->success(sprintf('Dispatched %d task list sync(s).', count($lists)));

        return Command::SUCCESS;
    }
}
