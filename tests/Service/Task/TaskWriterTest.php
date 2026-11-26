<?php

declare(strict_types=1);

namespace App\Tests\Service\Task;

use App\Domain\DTO\Task\TaskDraft;
use App\Domain\Enum\Calendar\SyncState;
use App\Domain\Exception\TaskListReadOnlyException;
use App\Entity\Mail\Account;
use App\Entity\Task\Task;
use App\Entity\Task\TaskList;
use App\Entity\User\User;
use App\Service\Task\TaskWriter;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A local change says what it owes the remote, and a read-only list takes none.
 *
 * A write that forgot to mark the row is a change that never leaves, which nobody
 * notices until two devices disagree, so the states are pinned here rather than
 * left to the sync tests to imply.
 */
final class TaskWriterTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private TaskWriter $writer;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->writer     = $container->get(TaskWriter::class);

        $this->connection->beginTransaction();

        $this->user            = new User();
        $this->user->email     = sprintf('tasks-%s@example.test', uniqid());
        $this->user->nameFirst = 'Task';
        $this->user->nameLast  = 'Fixture';
        $this->user->password  = 'x';
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

    public function testALocalListOwesNobodyAnything(): void
    {
        $task = $this->writer->create($this->user, $this->list(), new TaskDraft('Local'));

        self::assertSame(SyncState::Clean, $task->syncState);

        $this->writer->update($task, new TaskDraft('Renamed'));

        self::assertSame(SyncState::Clean, $task->syncState);
    }

    public function testASyncedListIsOwedACreateThenAnUpdate(): void
    {
        $task = $this->writer->create($this->user, $this->list('remote'), new TaskDraft('Synced'));

        self::assertSame(SyncState::PendingCreate, $task->syncState);

        // Still a create: there is nothing at the remote to update yet.
        $this->writer->update($task, new TaskDraft('Renamed before it left'));
        self::assertSame(SyncState::PendingCreate, $task->syncState);

        $task->remoteId  = 'id';
        $task->syncState = SyncState::Clean;

        $this->writer->complete($task);
        self::assertSame(SyncState::PendingUpdate, $task->syncState);
    }

    public function testDeletingAnUnsentTaskRemovesItRatherThanAskingTheRemote(): void
    {
        $task = $this->writer->create($this->user, $this->list('remote'), new TaskDraft('Never left'));
        $this->em->flush();

        $this->writer->delete($task);
        $this->em->flush();

        self::assertFalse($this->em->contains($task));
    }

    public function testAReadOnlyListRefusesEveryWrite(): void
    {
        $list             = $this->list('remote');
        $task             = $this->writer->create($this->user, $list, new TaskDraft('Before'));
        $list->isReadOnly = true;

        $attempts = [
            fn () => $this->writer->create($this->user, $list, new TaskDraft('New')),
            fn () => $this->writer->update($task, new TaskDraft('After')),
            fn () => $this->writer->complete($task),
            fn () => $this->writer->reopen($task),
            fn () => $this->writer->delete($task),
        ];

        foreach ($attempts as $attempt) {
            try {
                $attempt();
                self::fail('A read-only list accepted a write.');
            } catch (TaskListReadOnlyException) {
                self::assertSame('Before', $task->title);
            }
        }
    }

    public function testAnUnparseableDateIsNoDateRatherThanToday(): void
    {
        $task = $this->writer->create($this->user, $this->list(), new TaskDraft('Odd', dueDate: '2026-02-31'));

        self::assertNull($task->dueDate);
        self::assertNull($task->dueAt);
    }

    public function testAnEditWithoutADateClearsBothDueColumns(): void
    {
        $task = $this->writer->create($this->user, $this->list(), new TaskDraft('Dated', dueDate: '2026-06-15', dueTime: '09:00'));

        $this->writer->update($task, new TaskDraft('Undated'));

        self::assertNull($task->dueDate);
        self::assertNull($task->dueAt);
    }

    public function testAnEditLeavesTheFieldsItHasNoControlFor(): void
    {
        $task            = $this->writer->create($this->user, $this->list(), new TaskDraft('Kept'));
        $task->priority  = 3;
        $task->startDate = new DateTimeImmutable('2026-06-01');

        $this->writer->update($task, new TaskDraft('Renamed'));

        self::assertSame(3, $task->priority);
        self::assertSame('2026-06-01', $task->startDate->format('Y-m-d'));
    }

    public function testTickingARepeatingTaskMovesItAndOpensItsSteps(): void
    {
        $list = $this->list();
        $task = $this->writer->create($this->user, $list, new TaskDraft('Weekly', dueDate: '2026-06-01', recurrence: 'weekly'));
        $step = $this->writer->addStep($task, 'Part');
        $this->writer->complete($step, new DateTimeImmutable('2026-06-01 09:00:00'));

        $this->writer->complete($task, new DateTimeImmutable('2026-06-01 10:00:00', new DateTimeZone('UTC')));

        self::assertTrue($task->status->isOpen(), 'no finished copy: the same row, a new date');
        self::assertNull($task->completedAt);
        self::assertSame('2026-06-08', $task->dueDate?->format('Y-m-d'));
        self::assertTrue($step->status->isOpen());
    }

    public function testATickLeavesAFinishedEntryForTheDateItLeftBehind(): void
    {
        $task = $this->writer->create($this->user, $this->list(), new TaskDraft('Weekly', dueDate: '2026-06-01', recurrence: 'weekly'));
        $this->em->flush();

        $now = new DateTimeImmutable('2026-06-01 10:00:00', new DateTimeZone('UTC'));
        $this->writer->complete($task, $now);
        $this->em->flush();

        $entries = $this->entriesOf($task);

        self::assertCount(1, $entries);
        self::assertSame('Weekly', $entries[0]->title);
        self::assertFalse($entries[0]->status->isOpen());
        self::assertSame('2026-06-01', $entries[0]->dueDate?->format('Y-m-d'), 'the date done, not the next one');
        self::assertEquals($now, $entries[0]->completedAt);
        self::assertSame('2026-06-08', $task->dueDate?->format('Y-m-d'));
    }

    public function testAnEntryIsNeverOwedToTheServerWhateverHappensToIt(): void
    {
        $task            = $this->writer->create($this->user, $this->list('remote'), new TaskDraft('Weekly', dueDate: '2026-06-01', recurrence: 'weekly'));
        $task->remoteId  = 'r1';
        $task->syncState = SyncState::Clean;
        $this->em->flush();

        $this->writer->complete($task, new DateTimeImmutable('2026-06-01 10:00:00', new DateTimeZone('UTC')));
        $this->em->flush();

        $entry = $this->entriesOf($task)[0];

        self::assertSame(SyncState::Clean, $entry->syncState);
        self::assertNull($entry->remoteId);
        self::assertSame(SyncState::PendingUpdate, $task->syncState, 'the move itself does go out');

        $this->writer->update($entry, new TaskDraft('Renamed'));

        self::assertSame(SyncState::Clean, $entry->syncState);
    }

    public function testATaskThatDoesNotRepeatLeavesNoEntryAndNeitherDoesARuleThatEnded(): void
    {
        $plain = $this->writer->create($this->user, $this->list(), new TaskDraft('Once'));
        $last  = $this->writer->create($this->user, $this->list(), new TaskDraft('Last', dueDate: '2026-06-01'));

        $last->recurrenceRule = 'FREQ=WEEKLY;COUNT=1';
        $this->em->flush();

        $this->writer->complete($plain);
        $this->writer->complete($last, new DateTimeImmutable('2026-06-01 10:00:00', new DateTimeZone('UTC')));
        $this->em->flush();

        self::assertSame([], $this->entriesOf($plain));
        self::assertSame([], $this->entriesOf($last));
        self::assertFalse($last->status->isOpen(), 'the task itself is what finished');
    }

    public function testUntickingTheNewestEntryPutsTheTaskBackOnThatDate(): void
    {
        $task = $this->writer->create($this->user, $this->list(), new TaskDraft('Weekly', dueDate: '2026-06-01'));

        $task->recurrenceRule = 'FREQ=WEEKLY;COUNT=3';
        $this->em->flush();

        $this->writer->complete($task, new DateTimeImmutable('2026-06-01 10:00:00', new DateTimeZone('UTC')));
        $this->em->flush();

        $this->writer->reopen($this->entriesOf($task)[0]);
        $this->em->flush();

        self::assertSame([], $this->entriesOf($task));
        self::assertSame('2026-06-01', $task->dueDate?->format('Y-m-d'));
        self::assertSame('FREQ=WEEKLY;COUNT=3', $task->recurrenceRule, 'the spent occurrence is given back');
    }

    public function testUntickingAnOlderEntryDoesNotRewindPastANewerTick(): void
    {
        $task = $this->writer->create($this->user, $this->list(), new TaskDraft('Weekly', dueDate: '2026-06-01', recurrence: 'weekly'));
        $this->em->flush();

        $this->writer->complete($task, new DateTimeImmutable('2026-06-01 10:00:00', new DateTimeZone('UTC')));
        $this->em->flush();
        $this->writer->complete($task, new DateTimeImmutable('2026-06-08 10:00:00', new DateTimeZone('UTC')));
        $this->em->flush();

        $older = array_values(array_filter($this->entriesOf($task), static fn (Task $entry): bool => '2026-06-01' === $entry->dueDate?->format('Y-m-d')))[0];

        $this->writer->reopen($older);
        $this->em->flush();

        self::assertCount(1, $this->entriesOf($task));
        self::assertSame('2026-06-15', $task->dueDate?->format('Y-m-d'));
    }

    public function testTickingAStepNeverMovesAnythingEvenIfItCarriesARule(): void
    {
        $task                 = $this->writer->create($this->user, $this->list(), new TaskDraft('Parent'));
        $step                 = $this->writer->addStep($task, 'Part');
        $step->recurrenceRule = 'FREQ=DAILY';

        $this->writer->complete($step);

        self::assertFalse($step->status->isOpen());
    }

    public function testAStepOfAStepHangsFromTheTopTask(): void
    {
        $task  = $this->writer->create($this->user, $this->list(), new TaskDraft('Top'));
        $step  = $this->writer->addStep($task, 'One');
        $child = $this->writer->addStep($step, 'Two');

        self::assertSame($task, $child->parent);
        self::assertSame(1, $child->position);
    }

    public function testDeletingATaskOnAServerTellsItAboutTheStepsToo(): void
    {
        $task           = $this->writer->create($this->user, $this->list('remote'), new TaskDraft('Parent'));
        $task->remoteId = 'r1';
        $step           = $this->writer->addStep($task, 'Part');
        $step->remoteId = 'r2';
        $this->em->flush();

        $this->writer->delete($task);

        self::assertSame(SyncState::PendingDelete, $task->syncState);
        self::assertSame(SyncState::PendingDelete, $step->syncState);
        self::assertSame([], $task->liveSteps());
    }

    public function testKeepLeavesARuleTheEditorCannotSayAndEmptyClearsIt(): void
    {
        $task                 = $this->writer->create($this->user, $this->list(), new TaskDraft('Custom'));
        $task->recurrenceRule = 'FREQ=WEEKLY;BYDAY=MO,WE';

        self::assertSame('keep', $task->recurrenceChoice());

        $this->writer->update($task, new TaskDraft('Custom', recurrence: 'keep'));
        self::assertSame('FREQ=WEEKLY;BYDAY=MO,WE', $task->recurrenceRule);

        $this->writer->update($task, new TaskDraft('Custom', recurrence: ''));
        self::assertNull($task->recurrenceRule);
    }

    public function testQuickAddHasNoSayOverARepeatOrAReminder(): void
    {
        $task                 = $this->writer->create($this->user, $this->list(), new TaskDraft('Kept'));
        $task->recurrenceRule = 'FREQ=DAILY';
        $task->reminderAt     = new DateTimeImmutable('2026-06-01 10:00:00');

        $this->writer->update($task, new TaskDraft('Renamed'));

        self::assertSame('FREQ=DAILY', $task->recurrenceRule);
        self::assertNotNull($task->reminderAt);
    }

    public function testAMovedTaskTakesItsStepsAndItsFinishedEntriesWithIt(): void
    {
        $from = $this->list();
        $to   = $this->list();
        $task = $this->writer->create($this->user, $from, new TaskDraft('Weekly', dueDate: '2026-06-01', recurrence: 'weekly'));
        $step = $this->writer->addStep($task, 'Step');
        $this->em->flush();

        $this->writer->complete($task, new DateTimeImmutable('2026-06-01 10:00:00', new DateTimeZone('UTC')));
        $this->em->flush();

        $this->writer->move($task, $to);
        $this->em->flush();

        self::assertSame($to, $task->taskList);
        self::assertSame($to, $step->taskList);
        self::assertSame($to, $this->entriesOf($task)[0]->taskList);
        self::assertSame(SyncState::Clean, $task->syncState, 'a local list owes nobody a create');
    }

    /** A remote copy cannot follow: it is deleted where it was and created where it goes. */
    public function testMovingOffASyncedListLeavesAStandInToDeleteAndCreatesAfresh(): void
    {
        $from = $this->list('https://dav/a/');
        $to   = $this->list('https://dav/b/');
        $task = $this->writer->create($this->user, $from, new TaskDraft('Synced'));
        $step = $this->writer->addStep($task, 'Step');

        $task->remoteId  = 'https://dav/a/1.ics';
        $task->syncState = SyncState::Clean;
        $step->remoteId  = 'https://dav/a/2.ics';
        $step->syncState = SyncState::Clean;
        $this->em->flush();

        $uid = $task->uid;

        $this->writer->move($task, $to);
        $this->em->flush();

        $standIns = $this->em->getRepository(Task::class)->findBy(['taskList' => $from, 'syncState' => SyncState::PendingDelete]);

        self::assertEqualsCanonicalizing(['https://dav/a/1.ics', 'https://dav/a/2.ics'], array_map(static fn (Task $each): ?string => $each->remoteId, $standIns));
        self::assertSame(SyncState::PendingCreate, $task->syncState);
        self::assertSame(SyncState::PendingCreate, $step->syncState);
        self::assertNull($task->remoteId);
        self::assertNotSame($uid, $task->uid, 'a server with both lists may refuse the first one\'s uid');
        self::assertSame([$step], $task->liveSteps());
    }

    public function testNothingMovesOffOrOntoAReadOnlyList(): void
    {
        $from     = $this->list();
        $readOnly = $this->list('https://dav/ro/');
        $task     = $this->writer->create($this->user, $from, new TaskDraft('Stays'));

        $readOnly->isReadOnly = true;
        $this->em->flush();

        $this->expectException(TaskListReadOnlyException::class);

        $this->writer->move($task, $readOnly);
    }

    /** Microsoft moves it on when the tick arrives; doing it here as well would move it twice. */
    public function testOnAListWhoseRemoteMovesRepeatsATickOnlyTicks(): void
    {
        $task = $this->writer->create($this->user, $this->microsoftList(), new TaskDraft('Weekly', dueDate: '2026-06-01', recurrence: 'weekly'));

        $this->writer->complete($task, new DateTimeImmutable('2026-06-01 10:00:00', new DateTimeZone('UTC')));

        self::assertFalse($task->status->isOpen());
        self::assertSame('2026-06-01', $task->dueDate?->format('Y-m-d'), 'not moved to its next date');
        self::assertSame('FREQ=WEEKLY', $task->recurrenceRule);
    }

    /** Microsoft To Do keeps the day only, and a time saved here would be gone at the next pull. */
    public function testOnAListThatKeepsNoTimeADueTimeIsADay(): void
    {
        $task = $this->writer->create($this->user, $this->microsoftList(), new TaskDraft('Call', dueDate: '2026-06-01', dueTime: '15:30'));

        self::assertSame('2026-06-01', $task->dueDate?->format('Y-m-d'));
        self::assertNull($task->dueAt);
    }

    /** Not persisted: the account would need a whole mailbox behind it, and nothing here is flushed. */
    private function microsoftList(): TaskList
    {
        $list           = new TaskList();
        $list->usr      = $this->user;
        $list->name     = 'To Do';
        $list->account  = new Account();
        $list->remoteId = 'L1';

        return $list;
    }

    /** @return list<Task> */
    private function entriesOf(Task $task): array
    {
        return $this->em->getRepository(Task::class)->findBy(['occurrenceOf' => $task]);
    }

    private function list(?string $remoteId = null): TaskList
    {
        $list           = new TaskList();
        $list->usr      = $this->user;
        $list->name     = 'List';
        $list->remoteId = $remoteId;
        $this->em->persist($list);
        $this->em->flush();

        return $list;
    }
}
