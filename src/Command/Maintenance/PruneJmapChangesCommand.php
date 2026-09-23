<?php

declare(strict_types=1);

namespace App\Command\Maintenance;

use App\Jmap\State\ChangeLogRepository;
use DateTimeImmutable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Retention for jmap_change_log.
 *
 * The log is written on every sync — a row per message, plus one per touched
 * thread — and nothing ever removed any of it, so on a busy install it was the
 * fastest-growing table there is, holding history no client would ever ask
 * for. A client asks for changes since the state it last saw; one that has
 * been away for longer than the window gets cannotCalculateChanges and
 * resyncs, which is the answer the protocol has for exactly this.
 *
 * What survives a prune, and why the state never goes backwards, is
 * ChangeLogRepository::pruneOlderThan()'s to explain.
 */
#[AsCommand(
    name: 'app:jmap:prune-changes',
    description: 'Prune JMAP change-log rows older than the retention window',
)]
final class PruneJmapChangesCommand extends Command
{
    /**
     * Days kept by default.
     *
     * Sixty, which is longer than any phone stays switched off and any laptop
     * stays closed in the ordinary run of things, so a resync is the exception
     * a returning traveller meets rather than something a weekly user does.
     */
    private const string DEFAULT_DAYS = '60';

    public function __construct(
        private readonly ChangeLogRepository $changes,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('days', null, InputOption::VALUE_REQUIRED, 'Retention in days', self::DEFAULT_DAYS);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Clamped at one day, as app:ai:prune-metrics does: a typo on a nightly
        // schedule must not send every client into a full resync at once.
        $days = max(1, (int) $input->getOption('days'));

        $pruned = $this->changes->pruneOlderThan(new DateTimeImmutable(sprintf('-%d days', $days)));

        $io->success(sprintf('Pruned %d JMAP change-log rows (older than %dd).', $pruned, $days));

        return Command::SUCCESS;
    }
}
