<?php

declare(strict_types=1);

namespace App\Tests\Repository\Job;

use App\Domain\Enum\Job\JobKind;
use App\Domain\Enum\Job\JobState;
use App\Entity\Job\BackgroundJob;
use App\Entity\User\User;
use App\Repository\Job\BackgroundJobRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What the topbar indicator is allowed to claim is still happening.
 *
 * The reported bug was three "Marking as read" jobs stuck at 1400/1770,
 * 600/1180 and 100/1275 that had stopped weeks earlier. Every one of them was a
 * row a killed worker left in `running`, and the query behind the indicator
 * matched `state IN (queued, running)` with no time bound of any kind — so
 * "still going" and "abandoned" were literally the same row, and no amount of
 * waiting or reloading was ever going to change that.
 *
 * These tests pin the line that now separates them, from both sides. The pair
 * that matters is testAJobStillReportingProgressIsShown and
 * testAJobWhoseWorkerDiedDropsOut: they differ in ONE column and in nothing
 * else, which is the whole claim.
 *
 * The last test pins the two queries against each other. The indicator keeps
 * what is strictly newer than the cutoff and findStranded() takes the rest, so
 * a job that fell through the gap between them would be neither shown to
 * anybody nor swept by anything — invisible and immortal, which is a worse
 * version of the bug rather than a fix for it.
 */
final class BackgroundJobVisibilityTest extends KernelTestCase
{
    /** Comfortably outside the fifteen-minute default, and obviously so when a test fails. */
    private const string LONG_DEAD = '-2 hours';

    private EntityManagerInterface $em;
    private Connection $connection;
    private BackgroundJobRepository $repository;

    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->repository = $container->get(BackgroundJobRepository::class);

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
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /** A worker that flushed a chunk a moment ago is working, however long it has been at it. */
    public function testAJobStillReportingProgressIsShown(): void
    {
        // Created hours ago on purpose: a bound on createdAt would pass every
        // other test in this file and fail this one, and a bulk action over a
        // large mailbox genuinely does run for hours.
        $job = $this->job(JobState::Running, '-30 seconds', createdAt: '-3 hours');

        self::assertSame([$job->id], $this->visibleIds());
    }

    /** And the same row, differing only in when it last moved, is not. */
    public function testAJobWhoseWorkerDiedDropsOut(): void
    {
        $this->job(JobState::Running, self::LONG_DEAD, createdAt: '-3 hours');

        self::assertSame([], $this->visibleIds(), 'a job nothing is working on must stop reporting itself as running');
    }

    /**
     * A job nothing has picked up yet is alive, not stale.
     *
     * The gap between queued and running is minutes on a busy worker — JobState
     * says so in as many words — and the constructor's stamp is what carries a
     * job across it. Read a missing or backdated stamp as "dead" and every bulk
     * action would vanish from the indicator the instant it was started.
     */
    public function testAQueuedJobNoWorkerHasReachedYetIsStillShown(): void
    {
        $job = new BackgroundJob($this->user, JobKind::MarkRead);

        $this->em->persist($job);
        $this->em->flush();

        self::assertSame([$job->id], $this->visibleIds());
    }

    /**
     * A failure stays long enough to be read, and then goes.
     *
     * This is the existing behaviour rather than new behaviour, and it is
     * tested here because it is what makes the reaper's answer terminate: once
     * a stranded job has been turned into a failure the user is told once, and
     * five minutes later the indicator is empty again rather than carrying a
     * new permanent fixture.
     */
    public function testAFailureIsShownForItsWindowAndNotAfterwards(): void
    {
        $recent = $this->job(JobState::Failed, self::LONG_DEAD, finishedAt: '-10 seconds');
        $this->job(JobState::Failed, self::LONG_DEAD, finishedAt: '-10 minutes');

        self::assertSame([$recent->id], $this->visibleIds());
    }

    /**
     * Everything the indicator has stopped showing, the sweep can still find.
     *
     * Same cutoff, complementary comparisons — `>` there, `<=` here. If the two
     * ever disagreed, the rows in between would be invisible AND unreachable,
     * so nothing would ever tell the user their five thousand conversations
     * were not marked read.
     */
    public function testWhatTheIndicatorDropsIsExactlyWhatTheSweepPicksUp(): void
    {
        $moving   = $this->job(JobState::Running, '-30 seconds');
        $stranded = $this->job(JobState::Running, self::LONG_DEAD);
        $queued   = $this->job(JobState::Queued, self::LONG_DEAD);

        self::assertSame([$moving->id], $this->visibleIds());

        $swept = array_map(
            static fn (BackgroundJob $job): ?int => $job->id,
            $this->repository->findStranded(new DateTimeImmutable(sprintf('-%d seconds', BackgroundJobRepository::DEFAULT_STALE_SECONDS))),
        );

        // Queued counts too: a job the worker never picked up at all has left
        // the user waiting for something that is not coming either.
        self::assertEqualsCanonicalizing([$stranded->id, $queued->id], $swept);
    }

    /** A job that finished cleanly is nobody's business afterwards, and never was. */
    public function testASuccessDoesNotLinger(): void
    {
        $this->job(JobState::Done, '-30 seconds', finishedAt: '-10 seconds');

        self::assertSame([], $this->visibleIds());
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /** @return list<int|null> */
    private function visibleIds(): array
    {
        return array_map(
            static fn (BackgroundJob $job): ?int => $job->id,
            $this->repository->findVisibleForUser($this->user),
        );
    }

    private function job(
        JobState $state,
        string $lastProgressAt,
        ?string $finishedAt = null,
        ?string $createdAt = null,
    ): BackgroundJob {
        $job = new BackgroundJob($this->user, JobKind::MarkRead);

        $job->state          = $state;
        $job->total          = 1770;
        $job->processed      = 1400;
        $job->lastProgressAt = new DateTimeImmutable($lastProgressAt);
        $job->createdAt      = new DateTimeImmutable($createdAt ?? $lastProgressAt);
        $job->finishedAt     = null !== $finishedAt ? new DateTimeImmutable($finishedAt) : null;

        $this->em->persist($job);
        $this->em->flush();

        return $job;
    }

    private function seedUser(): User
    {
        $user = new User();

        $user->email     = 'jobs-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Jobs';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
