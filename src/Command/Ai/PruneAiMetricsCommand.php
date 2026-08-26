<?php

declare(strict_types=1);

namespace App\Command\Ai;

use App\Repository\Ai\AiCallMetricRepository;
use DateTimeImmutable;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Retention for ai_call_metric.
 *
 * WHY IT IS NOT PART OF app:monitoring:prune
 * ──────────────────────────────────────────
 * That command's own docblock argues for one sweep over several, and the
 * argument is a good one — so this needs a reason rather than a preference.
 * The reason is the shape of the table. Logs, push deliveries and heartbeats
 * grow with the deployment; this one grows with a FEATURE, in bursts, and is
 * empty on the installations that have not switched the AI on. A backfill over
 * a hundred thousand messages writes a hundred thousand rows in an afternoon
 * and then nothing for a month, so the window worth keeping is a decision made
 * by whoever tuned the model host, not by whoever sized the disk — and it wants
 * to be changeable without touching the retention of anything else.
 *
 * NEVER INSIDE A WEB REQUEST
 * ──────────────────────────
 * A DELETE with no bound in front of somebody reading their mail is how a
 * panel that draws a chart ends up owning a lock on the table the workers are
 * writing to. It runs from the scheduler, nightly, on the maintenance worker —
 * see MaintenanceSchedule — or by hand when a disk is filling.
 *
 * WHAT IS LOST WHEN IT RUNS
 * ─────────────────────────
 * Only counts and durations. The table holds no prompt, no completion, no
 * message id and no address — see AiCallRecorder — so pruning it forgets how
 * fast the box was last month and nothing else.
 */
#[AsCommand(
    name: 'app:ai:prune-metrics',
    description: 'Prune recorded model-call timings older than the retention window',
)]
final class PruneAiMetricsCommand extends Command
{
    /**
     * Days kept by default.
     *
     * Thirty, because the question this table answers is "has it got slower
     * since we changed something", and the something is usually a model swap
     * or a driver update a few weeks back. A fortnight would have already
     * forgotten the before.
     */
    private const string DEFAULT_DAYS = '30';

    public function __construct(
        private readonly AiCallMetricRepository $metrics,
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

        // Clamped at one day rather than refused: `--days=0` means "empty it",
        // which is a reasonable thing to want and an unreasonable thing to do
        // by accident on a nightly schedule that read a typo.
        $days = max(1, (int) $input->getOption('days'));

        $pruned = $this->metrics->pruneOlderThan(new DateTimeImmutable(sprintf('-%d days', $days)));

        $io->success(sprintf('Pruned %d recorded model calls (older than %dd).', $pruned, $days));

        return Command::SUCCESS;
    }
}
