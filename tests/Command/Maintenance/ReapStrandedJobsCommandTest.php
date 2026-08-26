<?php

declare(strict_types=1);

namespace App\Tests\Command\Maintenance;

use App\Domain\Enum\Job\JobKind;
use App\Domain\Enum\Job\JobState;
use App\Entity\Job\BackgroundJob;
use App\Entity\User\User;
use App\Repository\Job\BackgroundJobRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The half of the fix that actually TELLS somebody.
 *
 * Bounding the indicator's query stopped three dead "Marking as read" jobs
 * being reported as running, but on its own that only makes them invisible —
 * and the user did ask for five thousand conversations to be marked read, about
 * fourteen hundred of them were, and silence about that is a quieter version of
 * the same lie. This command turns each stranded row into a real failure, which
 * the indicator already knows how to show once and then forget.
 *
 * Two properties are worth more than the rest here:
 *
 *   - **Idempotence.** finish() takes the row out of Queued/Running, so the
 *     query that found it cannot find it again. The scheduler replays a missed
 *     run and an operator will run this by hand next to a scheduled one; a
 *     second pass must not restamp finishedAt and slide the failure window
 *     forward, because a failure that keeps renewing itself is the permanent
 *     topbar fixture this whole change exists to remove.
 *   - **It leaves live work alone.** The expensive mistake is not a stranded
 *     job surviving another five minutes; it is failing a bulk action that was
 *     going to finish, and telling the user nothing happened when it did.
 */
final class ReapStrandedJobsCommandTest extends KernelTestCase
{
    /** Comfortably past the fifteen-minute default, and obviously so when a test fails. */
    private const string LONG_DEAD = '-2 hours';

    private EntityManagerInterface $em;
    private Connection $connection;
    private BackgroundJobRepository $repository;
    private TranslatorInterface $translator;
    private CommandTester $command;

    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->repository = $container->get(BackgroundJobRepository::class);
        $this->translator = $container->get(TranslatorInterface::class);

        $this->connection->beginTransaction();

        // THE TABLE IS THE FIXTURE HERE, so the table has to be emptied first.
        // app:jobs:reap sweeps every user's jobs by design, and background_job
        // is shared with whatever else the suite has run —
        // BulkStatusOffloadTest is a WebTestCase and COMMITS the jobs it
        // creates, which then go stale between runs of the suite. Without this
        // the counts below would be right on a fresh database and wrong on the
        // second afternoon. Transactional in Postgres, so the rollback in
        // tearDown puts them back.
        $this->connection->executeStatement('DELETE FROM background_job');

        $this->user = $this->seedUser();

        $this->command = new CommandTester(
            new Application(self::$kernel)->find('app:jobs:reap'),
        );
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /**
     * The reported row, and what the user should have been told about it weeks
     * ago.
     */
    public function testAStrandedJobIsFailedWithAReasonThePersonCanRead(): void
    {
        $job = $this->job(JobState::Running, self::LONG_DEAD);

        $this->command->execute([]);

        self::assertSame(Command::SUCCESS, $this->command->getStatusCode());
        self::assertStringContainsString('Failed 1', $this->command->getDisplay());

        $this->em->refresh($job);

        self::assertSame(JobState::Failed, $job->state);
        self::assertNotNull($job->finishedAt);

        // A translated category, not a raw key and not a stack trace. The
        // template renders this straight into the indicator, so anything that
        // leaks through appears verbatim in front of somebody.
        self::assertSame($this->translator->trans('jobs.failure.abandoned', [], null, $this->user->locale), $job->failureReason);
        self::assertStringNotContainsString('jobs.failure', (string) $job->failureReason);
    }

    /**
     * And the failure is then visible — once, through the window that already
     * exists.
     *
     * This is the property the whole change is for: the indicator that had been
     * lying goes quiet, says what actually happened, and clears itself, rather
     * than either lying forever or falling silent without explanation.
     */
    public function testTheFailureIsWhatTheIndicatorThenShows(): void
    {
        $this->job(JobState::Running, self::LONG_DEAD);

        $beforeSweep = $this->visibleStates();

        self::assertSame([], $beforeSweep, 'a stranded job is not reported as running');

        $this->command->execute([]);

        $afterSweep = $this->visibleStates();

        self::assertSame(['failed'], $afterSweep, 'and the sweep is what puts it back on screen, as a failure');
    }

    /**
     * A job whose worker flushed a chunk a moment ago is working, however long
     * it has been running.
     */
    public function testAJobStillReportingProgressIsLeftAlone(): void
    {
        $job = $this->job(JobState::Running, '-30 seconds', createdAt: '-3 hours');

        $this->command->execute([]);

        self::assertStringContainsString('No stranded background jobs.', $this->command->getDisplay());

        $this->em->refresh($job);

        self::assertSame(JobState::Running, $job->state);
        self::assertNull($job->finishedAt);
    }

    /**
     * Running it again finds nothing and, above all, does not restamp the
     * failure.
     *
     * The scheduler replays a missed run and an operator will run this by hand;
     * a second pass that moved finishedAt forward would push the five-minute
     * failure window along with it, and the indicator would never clear.
     */
    public function testRunningItAgainReapsNothingAndDoesNotMoveTheFailureWindow(): void
    {
        $job = $this->job(JobState::Running, self::LONG_DEAD);

        $this->command->execute([]);
        $this->em->refresh($job);

        $finishedAt = $job->finishedAt;

        $this->command->execute([]);

        self::assertSame(Command::SUCCESS, $this->command->getStatusCode());
        self::assertStringContainsString('No stranded background jobs.', $this->command->getDisplay());

        $this->em->refresh($job);

        self::assertEquals($finishedAt, $job->finishedAt, 'a second sweep must not renew a failure it did not cause');
        self::assertSame(JobState::Failed, $job->state);
    }

    /** A dry run says what it would do and writes nothing, like every other sweep here. */
    public function testADryRunChangesNothing(): void
    {
        $job = $this->job(JobState::Running, self::LONG_DEAD);

        $this->command->execute(['--dry-run' => true]);

        self::assertStringContainsString('Would fail 1', $this->command->getDisplay());

        $this->em->refresh($job);

        self::assertSame(JobState::Running, $job->state);
        self::assertNull($job->finishedAt);
    }

    /**
     * A window short enough to race the work it is watching is refused.
     *
     * A chunk is a hundred conversations and each can carry a provider round
     * trip, so seconds-scale staleness would fail healthy jobs mid-run — and
     * the operator who typed it would read the resulting failures as evidence
     * that the queue is broken.
     */
    public function testAnAbsurdlyShortWindowIsRefusedRatherThanObeyed(): void
    {
        $job = $this->job(JobState::Running, '-30 seconds');

        $this->command->execute(['--stale-seconds' => '5']);

        self::assertSame(Command::INVALID, $this->command->getStatusCode());

        $this->em->refresh($job);

        self::assertSame(JobState::Running, $job->state);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * What the topbar indicator would render for this user, as plain strings.
     *
     * States rather than entities, because the claim is about what a person
     * sees. Read into a variable by every caller before asserting: two
     * assertions against the same call expression let static analysis conclude
     * the second one is unreachable.
     *
     * @return list<string>
     */
    private function visibleStates(): array
    {
        return array_map(
            static fn (BackgroundJob $job): string => $job->state->value,
            $this->repository->findVisibleForUser($this->user),
        );
    }

    private function job(
        JobState $state,
        string $lastProgressAt,
        ?string $createdAt = null,
    ): BackgroundJob {
        $job = new BackgroundJob($this->user, JobKind::MarkRead);

        $job->state          = $state;
        $job->total          = 1770;
        $job->processed      = 1400;
        $job->lastProgressAt = new DateTimeImmutable($lastProgressAt);
        $job->createdAt      = new DateTimeImmutable($createdAt ?? $lastProgressAt);

        $this->em->persist($job);
        $this->em->flush();

        return $job;
    }

    private function seedUser(): User
    {
        $user = new User();

        $user->email     = 'reap-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Reap';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
