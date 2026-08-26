<?php

declare(strict_types=1);

namespace App\Command\Maintenance;

use App\Domain\Enum\Job\JobState;
use App\Entity\Job\BackgroundJob;
use App\Repository\Job\BackgroundJobRepository;
use App\Service\Job\JobNotifier;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Fails background jobs whose worker is never coming back.
 *
 * THE BUG THIS CLOSES
 *
 * A user reported three "Marking as read" jobs pinned in the topbar indicator
 * at 1400/1770, 600/1180 and 100/1275. They had finished — or rather stopped —
 * long before, and there was no way on earth to be rid of them. Each was a row
 * left in `running` by a worker that died mid-flight: a container restarted
 * during a deploy, an OOM kill, a fatal that never reached
 * RunBulkStatusHandler's failure path. Nothing else was ever going to touch
 * those rows, because the only code that writes them is the handler that is no
 * longer running.
 *
 * BackgroundJob::$lastProgressAt made the state readable and
 * BackgroundJobRepository::findVisibleForUser stopped SHOWING such a job. This
 * is the other half: without it those rows would merely become invisible, which
 * is a quieter version of the same lie — the user asked for five thousand
 * conversations to be marked read, about fourteen hundred of them were, and
 * nothing would ever have said so.
 *
 * WHY FAILED AND NOT DONE, AND NOT DELETED
 *
 * Failed is the truth. The work stopped part-way, the mailbox is in a state
 * nobody asked for, and JobState exists to distinguish exactly that from a
 * clean finish. Marking it done would tell the user their mail was handled;
 * deleting the row would tell them nothing at all, which is what has been
 * happening. Failed also puts the row into the failure window
 * findVisibleForUser already implements, so the indicator shows it once, for
 * five minutes, and then clears itself — a thing that appears, says what
 * happened, and goes away, rather than another permanent fixture.
 *
 * WHY A COMMAND AND NOT A SWEEP ON READ
 *
 * The tempting shortcut is to retire stale jobs while rendering the indicator,
 * since that is the code that notices them. It would put a write and a Mercure
 * publish inside a GET that every page load makes, for every user, to answer
 * "nothing is happening" almost every time — and it would run that write
 * concurrently in as many web processes as there are open tabs. See
 * App\Twig\BackgroundJobsExtension for how hard that path has already been
 * fought over. This runs on the maintenance worker, once, on a schedule; the
 * read path stays a read.
 *
 * Idempotent by construction: finish() takes the job out of Queued/Running, so
 * the query that found it cannot find it again. Safe to run by hand next to the
 * scheduled run, and safe to run twice.
 */
#[AsCommand(
    name: 'app:jobs:reap',
    description: 'Fail background jobs that stopped reporting progress, so the indicator stops claiming they are running',
)]
final class ReapStrandedJobsCommand extends Command
{
    /**
     * What the user is told, in their own language.
     *
     * A short category rather than a message about workers and containers: the
     * person reading it started a bulk action and needs to know it did not
     * finish, not how plMail is deployed.
     */
    private const string REASON_KEY = 'jobs.failure.abandoned';

    /**
     * Per run. Generous — a table with two hundred stranded jobs in it has a
     * problem this command is not the answer to — but bounded all the same, so
     * one pathological backlog cannot hold the maintenance worker while
     * snoozes and calendar alerts queue up behind it.
     */
    private const int BATCH = 200;

    /**
     * The SAPIs this is allowed to run under.
     *
     * Denied rather than listing the ones that serve HTTP, the way
     * App\Service\Monitoring\WebProcessRestart spells the same list: a runtime
     * nobody here has heard of should default to "not this process", never to
     * "probably fine, write to every user's jobs".
     */
    private const array CONSOLE_SAPIS = ['cli', 'phpdbg', 'embed'];

    public function __construct(
        private readonly BackgroundJobRepository $jobs,
        private readonly JobNotifier             $notifier,
        private readonly TranslatorInterface     $translator,
        private readonly EntityManagerInterface  $em,
        private readonly LoggerInterface         $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            // Seconds, matching BackgroundJobRepository, which measures the
            // failure window in seconds beside it. Two units in one feature is
            // how somebody eventually reaps after fifteen SECONDS.
            ->addOption(
                'stale-seconds',
                null,
                InputOption::VALUE_REQUIRED,
                'How long an active job may go without progress before it counts as abandoned',
                (string) BackgroundJobRepository::DEFAULT_STALE_SECONDS,
            )
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what would be failed without failing it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (false === in_array(PHP_SAPI, self::CONSOLE_SAPIS, true)) {
            // Refused rather than degraded. This writes to jobs belonging to
            // every user on the install and publishes for each one; a web
            // process that reached here has been wired up wrongly, and doing
            // the work anyway would hide that until it happened once per
            // request. See the class docblock.
            $io->error(sprintf('app:jobs:reap runs on a worker, not in a web request (SAPI: %s).', PHP_SAPI));

            return Command::FAILURE;
        }

        $seconds = (int) $input->getOption('stale-seconds');

        if (60 > $seconds) {
            // A minute is already shorter than a legitimate chunk over a slow
            // IMAP host. Below that this stops being a reaper and becomes a
            // race against the work it is meant to be watching.
            $io->error('--stale-seconds must be at least 60; anything shorter fails work that is merely between chunks.');

            return Command::INVALID;
        }

        $dryRun   = true === $input->getOption('dry-run');
        $before   = new DateTimeImmutable(sprintf('-%d seconds', $seconds));
        $stranded = $this->jobs->findStranded($before, self::BATCH);

        if ([] === $stranded) {
            $io->success('No stranded background jobs.');

            return Command::SUCCESS;
        }

        if (true === $dryRun) {
            foreach ($stranded as $job) {
                $io->text(sprintf('would fail %s', $this->describe($job)));
            }

            $io->success(sprintf('Would fail %d stranded job(s) (no progress for %ds).', count($stranded), $seconds));

            return Command::SUCCESS;
        }

        foreach ($stranded as $job) {
            $this->logger->warning('ReapStrandedJobsCommand: failing a job whose worker stopped reporting', [
                'jobId'          => $job->id,
                'kind'           => $job->kind->value,
                'state'          => $job->state->value,
                'processed'      => $job->processed,
                'total'          => $job->total,
                'lastProgressAt' => $job->lastProgressAt->format(DATE_ATOM),
            ]);

            $job->finish(JobState::Failed, $this->reasonFor($job));
        }

        // ONE flush for the batch. Each finish() writes three scalars on a row
        // this command already holds, so there is no per-job failure mode to
        // isolate — a flush that cannot write one of them cannot write any of
        // them, and a half-swept table would be worse than an unswept one.
        $this->em->flush();

        // Published only after the write has landed. JobNotifier carries no
        // state — it says "look again" — so a page nudged before the commit
        // would re-read the row it already had and stay wrong until something
        // else happened to it.
        foreach ($stranded as $job) {
            $this->notifier->changed($job);
        }

        $io->success(sprintf('Failed %d stranded job(s) (no progress for %ds).', count($stranded), $seconds));

        return Command::SUCCESS;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * The stored reason, translated into the OWNER's language rather than the
     * console's.
     *
     * Translated here and stored as text, not stored as a key: failureReason
     * holds a provider's own words for every other failure and the indicator
     * renders it raw, so making this one a key would leave the template holding
     * a column that is sometimes a key and sometimes prose, with no way to tell
     * which. The cost is that a user who later switches language keeps this one
     * sentence in the old one — for five minutes, until the failure window
     * closes over it. App\Service\Calendar\CalendarProvisioner stores a
     * user-locale string the same way and for the same reason.
     */
    private function reasonFor(BackgroundJob $job): string
    {
        return $this->translator->trans(self::REASON_KEY, [], null, $job->usr->locale);
    }

    private function describe(BackgroundJob $job): string
    {
        return sprintf(
            'job %d (%s, %s, %d/%d), last moved %s',
            (int) $job->id,
            $job->kind->value,
            $job->state->value,
            $job->processed,
            $job->total,
            $job->lastProgressAt->format(DATE_ATOM),
        );
    }
}
