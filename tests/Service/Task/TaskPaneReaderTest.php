<?php

declare(strict_types=1);

namespace App\Tests\Service\Task;

use App\Domain\DTO\Task\TaskDraft;
use App\Domain\DTO\Task\TaskPaneQuery;
use App\Domain\Enum\Calendar\SyncState;
use App\Domain\Enum\Task\TaskStatus;
use App\Domain\Enum\Task\TaskView;
use App\Entity\Task\Task;
use App\Entity\Task\TaskList;
use App\Entity\User\User;
use App\Service\Task\TaskListProvisioner;
use App\Service\Task\TaskPaneReader;
use App\Service\Task\TaskWriter;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * My Day is the reader's day, not the server's, and a date is not an instant.
 *
 * The container runs on UTC, so the two mistakes available here both look right
 * from Greenwich: asking PHP for today moves the boundary by the reader's offset,
 * and storing a due date as midnight UTC puts it on the previous day west of
 * there. Each test below is set in a zone where the wrong answer differs from the
 * right one.
 *
 * Against a real container and database, because the behaviour worth pinning is
 * the SQL's: both date columns, the jsonb containment and the ordering.
 */
final class TaskPaneReaderTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private TaskPaneReader $reader;
    private TaskWriter $writer;
    private TaskListProvisioner $provisioner;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();

        $container         = self::getContainer();
        $this->em          = $container->get(EntityManagerInterface::class);
        $this->connection  = $container->get(Connection::class);
        $this->reader      = $container->get(TaskPaneReader::class);
        $this->writer      = $container->get(TaskWriter::class);
        $this->provisioner = $container->get(TaskListProvisioner::class);

        $this->connection->beginTransaction();

        $this->user            = new User();
        $this->user->email     = sprintf('tasks-%s@example.test', uniqid());
        $this->user->nameFirst = 'Task';
        $this->user->nameLast  = 'Fixture';
        $this->user->password  = 'x';
        $this->user->timezone  = 'America/Los_Angeles';
        $this->em->persist($this->user);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testADueDateIsTodayInTheReadersZoneRatherThanInUtc(): void
    {
        // 02:00 UTC on the 16th is still the evening of the 15th in Los Angeles.
        $now  = new DateTimeImmutable('2026-06-16 02:00:00', new DateTimeZone('UTC'));
        $task = $this->task('Due on the reader\'s today', dueDate: '2026-06-15');
        $this->task('Due on UTC\'s today', dueDate: '2026-06-17');

        $pane = $this->reader->read($this->user, new TaskPaneQuery(), $now);

        self::assertSame(['today'], array_keys($pane->sections));
        self::assertSame([$task], $pane->sections['today']);
    }

    public function testADateOnlyTaskIsNotOverdueUntilTheDayAfter(): void
    {
        $now   = new DateTimeImmutable('2026-06-15 23:00:00', new DateTimeZone('UTC'));
        $today = $this->task('Today', dueDate: '2026-06-15');
        $late  = $this->task('Yesterday', dueDate: '2026-06-14');

        $pane = $this->reader->read($this->user, new TaskPaneQuery(), $now);

        self::assertSame([$today], $pane->sections['today']);
        self::assertSame([$late], $pane->sections['overdue']);
    }

    public function testATimedTaskFromThisMorningStaysInTodayRatherThanOverdue(): void
    {
        // 15:00 in Los Angeles, and the task was due at 09:00 there.
        $now  = new DateTimeImmutable('2026-06-15 22:00:00', new DateTimeZone('UTC'));
        $task = $this->task('This morning', dueDate: '2026-06-15', dueTime: '09:00');

        $pane = $this->reader->read($this->user, new TaskPaneQuery(), $now);

        self::assertSame([$task], $pane->sections['today']);
        self::assertTrue($task->isOverdue($now, new DateTimeZone('America/Los_Angeles')));
    }

    public function testADueTimeIsReadInTheUsersZoneAndStoredAsAnInstant(): void
    {
        $task = $this->task('At nine', dueDate: '2026-06-15', dueTime: '09:00');

        self::assertNull($task->dueDate);
        self::assertSame('2026-06-15 16:00', $task->dueAt?->format('Y-m-d H:i'));
    }

    public function testAStartAndAReminderBringATaskOntoMyDay(): void
    {
        $now     = new DateTimeImmutable('2026-06-15 18:00:00', new DateTimeZone('UTC'));
        $starts  = $this->task('Starts today');
        $reminds = $this->task('Reminds today');
        $neither = $this->task('Tomorrow', dueDate: '2026-06-16');

        $starts->startDate   = new DateTimeImmutable('2026-06-15');
        $reminds->reminderAt = new DateTimeImmutable('2026-06-15 20:00:00', new DateTimeZone('UTC'));
        $this->em->flush();

        $pane = $this->reader->read($this->user, new TaskPaneQuery(), $now);

        self::assertContains($starts, $pane->sections['today']);
        self::assertContains($reminds, $pane->sections['today']);
        self::assertNotContains($neither, $pane->sections['today']);
    }

    public function testATaskCompletedTodayStaysVisibleUntilMidnightAndNoLonger(): void
    {
        $task = $this->task('Done', dueDate: '2026-06-15');
        $this->writer->complete($task, new DateTimeImmutable('2026-06-15 18:00:00', new DateTimeZone('UTC')));
        $this->em->flush();

        $sameEvening = new DateTimeImmutable('2026-06-16 02:00:00', new DateTimeZone('UTC'));
        $nextMorning = new DateTimeImmutable('2026-06-16 16:00:00', new DateTimeZone('UTC'));

        self::assertSame([$task], $this->reader->read($this->user, new TaskPaneQuery(), $sameEvening)->sections['completed']);
        self::assertSame([], $this->reader->read($this->user, new TaskPaneQuery(), $nextMorning)->sections);
    }

    public function testATickedRepeatingTaskShowsAsDoneTodayAndItsNextDateStaysOpen(): void
    {
        $now  = new DateTimeImmutable('2026-06-15 18:00:00', new DateTimeZone('UTC'));
        $task = $this->task('Weekly', dueDate: '2026-06-15');

        $task->recurrenceRule = 'FREQ=WEEKLY';
        $this->writer->complete($task, $now);
        $this->em->flush();

        $myDay = $this->reader->read($this->user, new TaskPaneQuery(), $now);

        self::assertCount(1, $myDay->sections['completed']);
        self::assertTrue($myDay->sections['completed'][0]->isOccurrence());
        self::assertArrayNotHasKey('today', $myDay->sections, 'next week is not today');

        $list = $this->reader->read($this->user, new TaskPaneQuery(TaskView::Lists), $now);

        self::assertSame(1, $list->completedLater);
        self::assertSame([$task], $list->sections['upcoming']);
    }

    public function testAListShowsEverythingInItsGroupsWithTheFinishedLast(): void
    {
        $now     = new DateTimeImmutable('2026-06-15 18:00:00', new DateTimeZone('UTC'));
        $late    = $this->task('Late', dueDate: '2026-06-01');
        $today   = $this->task('Today', dueDate: '2026-06-15');
        $future  = $this->task('Future', dueDate: '2026-07-01');
        $undated = $this->task('Undated');

        $older = $this->task('Finished first', dueDate: '2026-06-01');
        $this->writer->complete($older, new DateTimeImmutable('2026-06-10 09:00:00'));

        $newer = $this->task('Finished last');
        $this->writer->complete($newer, new DateTimeImmutable('2026-06-14 09:00:00'));
        $this->em->flush();

        $pane = $this->reader->read($this->user, new TaskPaneQuery(TaskView::Lists), $now);

        self::assertSame(['overdue', 'today', 'upcoming', 'no_date'], array_keys($pane->sections), 'the finished come later');
        self::assertSame([$late], $pane->sections['overdue'], 'a finished one is not overdue');
        self::assertSame([$today], $pane->sections['today']);
        self::assertSame([$future], $pane->sections['upcoming']);
        self::assertSame([$undated], $pane->sections['no_date']);
        self::assertSame(2, $pane->completedLater);

        $page = $this->reader->completedPage($this->user, new TaskPaneQuery(TaskView::Lists), 0, $now);

        self::assertSame([$newer, $older], $page['tasks'], 'the latest finished first');
        self::assertNull($page['next']);
    }

    /** One page at a time, and a page says whether there is another, without counting them all. */
    public function testFinishedTasksComeAPageAtATime(): void
    {
        $now = new DateTimeImmutable('2026-06-15 18:00:00', new DateTimeZone('UTC'));

        for ($i = 0; $i <= TaskPaneReader::COMPLETED_PAGE; ++$i) {
            $this->writer->complete($this->task('Done ' . $i), $now->modify(sprintf('-%d minutes', $i)));
        }

        $this->em->flush();

        $query = new TaskPaneQuery(TaskView::Lists);
        $first = $this->reader->completedPage($this->user, $query, 0, $now);

        self::assertCount(TaskPaneReader::COMPLETED_PAGE, $first['tasks']);
        self::assertSame('Done 0', $first['tasks'][0]->title);
        self::assertSame(TaskPaneReader::COMPLETED_PAGE, $first['next']);

        $second = $this->reader->completedPage($this->user, $query, (int) $first['next'], $now);

        self::assertSame(['Done ' . TaskPaneReader::COMPLETED_PAGE], array_map(static fn ($task): string => $task->title, $second['tasks']));
        self::assertNull($second['next']);
    }

    public function testAStepAndAPendingDeleteAreNotRowsOfTheirOwn(): void
    {
        $now    = new DateTimeImmutable('2026-06-15 18:00:00', new DateTimeZone('UTC'));
        $parent = $this->task('Parent');

        $step         = $this->task('Step');
        $step->parent = $parent;

        $gone           = $this->task('Gone');
        $gone->remoteId = 'remote-1';
        $this->writer->delete($gone);
        $this->em->flush();

        $pane = $this->reader->read($this->user, new TaskPaneQuery(TaskView::Lists), $now);

        self::assertSame([$parent], $pane->sections['no_date']);
        self::assertSame(SyncState::PendingDelete, $gone->syncState);
    }

    public function testAnotherUsersListIsNotThere(): void
    {
        $other            = new User();
        $other->email     = sprintf('tasks-other-%s@example.test', uniqid());
        $other->nameFirst = 'Other';
        $other->nameLast  = 'Fixture';
        $other->password  = 'x';
        $this->em->persist($other);

        $theirs       = new TaskList();
        $theirs->usr  = $other;
        $theirs->name = 'Theirs';
        $this->em->persist($theirs);
        $this->em->flush();

        $pane = $this->reader->read($this->user, new TaskPaneQuery(TaskView::Lists, $theirs->id));

        self::assertNull($pane->list);
    }

    public function testACompletionSurvivesAReopenWithoutItsDate(): void
    {
        $task = $this->task('Reopened');
        $this->writer->complete($task);
        $this->writer->reopen($task);

        self::assertSame(TaskStatus::NeedsAction, $task->status);
        self::assertNull($task->completedAt);
    }

    private function task(string $title, string $dueDate = '', string $dueTime = ''): Task
    {
        $task = $this->writer->create(
            $this->user,
            $this->provisioner->defaultFor($this->user),
            new TaskDraft($title, dueDate: $dueDate, dueTime: $dueTime),
        );

        $this->em->flush();

        return $task;
    }
}
