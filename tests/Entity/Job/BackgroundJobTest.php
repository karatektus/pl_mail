<?php

declare(strict_types=1);

namespace App\Tests\Entity\Job;

use App\Domain\Enum\Job\JobKind;
use App\Domain\Enum\Job\JobState;
use App\Entity\Job\BackgroundJob;
use App\Entity\User\User;
use PHPUnit\Framework\TestCase;

/**
 * The numbers an indicator shows a person while they wait.
 *
 * Small arithmetic, and worth pinning because each of these has an
 * obviously-right implementation that is wrong: a bar that reaches the end
 * before the work does, a percentage of nothing, a job reading 100% while it is
 * still going.
 */
final class BackgroundJobTest extends TestCase
{
    public function testAJobWithNoTotalYetShowsNoProgress(): void
    {
        $job = $this->job();

        // Queued: the work has not been planned, so there is no denominator.
        // Dividing by it would be fatal; guessing one would be a lie.
        self::assertSame(0, $job->percent());
        self::assertTrue($job->isActive());
    }

    public function testProgressIsFlooredSoItNeverArrivesEarly(): void
    {
        $job            = $this->job();
        $job->total     = 3;
        $job->processed = 2;

        // 66.6…, and rounding would show 67% — harmless — while the same
        // rounding at 99.5% shows 100% on work that is not finished, which is
        // the one number a person is actually watching for.
        self::assertSame(66, $job->percent());
    }

    public function testProgressIsCappedIfMoreWasDoneThanPlanned(): void
    {
        $job            = $this->job();
        $job->total     = 10;
        $job->processed = 14;

        // Reachable: the view is resolved when the work RUNS, so mail arriving
        // mid-job can push the count past the total that was planned. Better to
        // sit at 100 than to render a bar wider than its track.
        self::assertSame(100, $job->percent());
    }

    public function testFinishingRecordsWhenAndWhy(): void
    {
        $job = $this->job();

        $job->finish(JobState::Failed, str_repeat('x', 900));

        self::assertFalse($job->isActive());
        self::assertNotNull($job->finishedAt);

        // Truncated like every other stored failure here: a stack trace is not
        // something to put in front of somebody.
        self::assertSame(500, mb_strlen((string) $job->failureReason));
    }

    public function testASuccessfulFinishCarriesNoReason(): void
    {
        $job = $this->job();
        $job->finish(JobState::Failed, 'went wrong');
        $job->finish(JobState::Done);

        self::assertNull($job->failureReason, 'a completed job must not keep an old failure');
    }

    /** Marking read and unread are one action with a flag, as the controller has it. */
    public function testTheTwoMarkKindsShareAnActionAndDifferInTheFlag(): void
    {
        self::assertSame('read', JobKind::MarkRead->action());
        self::assertSame('read', JobKind::MarkUnread->action());
        self::assertTrue(JobKind::MarkRead->readFlag());
        self::assertFalse(JobKind::MarkUnread->readFlag());

        self::assertSame(JobKind::MarkUnread, JobKind::forAction('read', false));
        self::assertSame(JobKind::Trash, JobKind::forAction('trash', true));
    }

    private function job(): BackgroundJob
    {
        return new BackgroundJob(new User(), JobKind::MarkRead);
    }
}
