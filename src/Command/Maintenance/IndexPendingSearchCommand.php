<?php

declare(strict_types=1);

namespace App\Command\Maintenance;

use App\Service\Search\SearchIndexRebuilder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * One short pass of the search index rebuild — see SearchIndexRebuilder.
 *
 * Scheduled every minute, and permanent rather than a one-time upgrade task,
 * for two reasons. A rebuild after an update can take an hour on a large
 * mailbox, and UpgradeTaskInterface wants a task that finishes on its own and
 * counts a killed run as a failed attempt; and once it is done, this costs one
 * probe of a partial index that is empty, so there is nothing to retire.
 *
 * TWENTY SECONDS A PASS, because it shares the maintenance worker with
 * `app:mail:sync --due`, which is due every minute as well: a pass can hold
 * that up by its budget and no more. That is still a few thousand messages a
 * minute.
 */
#[AsCommand(
    name: 'app:search:index-pending',
    description: 'Index messages still waiting for the search index, newest first, for a bounded time.',
)]
final class IndexPendingSearchCommand extends Command
{
    private const float DEFAULT_SECONDS = 20.0;

    public function __construct(
        private readonly SearchIndexRebuilder $rebuilder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('seconds', null, InputOption::VALUE_REQUIRED, 'How long one pass may run', (string) self::DEFAULT_SECONDS);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io      = new SymfonyStyle($input, $output);
        $seconds = max(1.0, (float) $input->getOption('seconds'));

        $done = $this->rebuilder->rebuild($seconds);

        if (0 === $done['indexed']) {
            return Command::SUCCESS;
        }

        $io->success(sprintf(
            'Indexed %d message(s), %d of them sent as HTML only; %d still waiting.',
            $done['indexed'],
            $done['filled'],
            $this->rebuilder->pending(),
        ));

        return Command::SUCCESS;
    }
}
