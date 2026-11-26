<?php

declare(strict_types=1);

namespace App\Tests\Service\Task\Sync;

use App\Domain\DTO\Calendar\CalendarSource;
use App\Domain\DTO\Task\RemoteTask;
use App\Domain\DTO\Task\RemoteTaskWriteResult;
use App\Domain\DTO\Task\TaskChangeSet;
use App\Domain\Enum\Calendar\SyncState;
use App\Domain\Enum\Integration\Provider;
use App\Domain\Enum\Task\TaskStatus;
use App\Domain\Exception\CalendarResyncRequiredException;
use App\Domain\Exception\CalendarSyncException;
use App\Domain\Exception\CalendarSyncPermanentException;
use App\Domain\Interface\TaskSyncDriverInterface;
use App\Entity\Integration\Integration;
use App\Entity\Mail\Account;
use App\Entity\Task\Task;
use App\Entity\Task\TaskAttachment;
use App\Entity\Task\TaskList;
use App\Entity\User\User;
use App\Repository\Task\TaskRepository;
use App\Service\Task\Sync\TaskPuller;
use App\Service\Task\Sync\TaskPusher;
use App\Service\Task\Sync\TaskSyncDriverRegistry;
use App\Service\Task\Sync\TaskSyncService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Two-way sync is only worth having if "both sides moved" has one answer.
 *
 * The rules are CalendarSyncService's: push before pull, a matching etag is not a
 * change, a real change means the remote wins. A list that takes writes and one
 * that does not, a row the remote never saw, and a token the remote has forgotten
 * are the cases where getting them wrong loses somebody's work without a word.
 *
 * Over a scripted driver and a real database, because the claim is what the rows
 * look like afterwards.
 */
final class TaskSyncServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private TaskList $list;

    /** @var list<string> what the driver was asked, in order */
    private array $calls = [];

    /** @var list<TaskChangeSet> what the next pulls answer */
    private array $pulls = [];

    private ?CalendarSyncException $pushFailure = null;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

        $user            = new User();
        $user->email     = sprintf('task-sync-%s@example.test', uniqid());
        $user->nameFirst = 'Task';
        $user->nameLast  = 'Sync';
        $user->password  = 'x';
        $this->em->persist($user);

        $integration = new Integration($user, Provider::CalDav);
        $this->em->persist($integration);

        $this->list              = new TaskList();
        $this->list->usr         = $user;
        $this->list->integration = $integration;
        $this->list->name        = 'Synced';
        $this->list->remoteId    = 'https://dav/tasks/';
        $this->em->persist($this->list);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testALocalChangeGoesOutBeforeAnythingIsReadBack(): void
    {
        $task = $this->localTask('Mine', SyncState::PendingCreate);

        $this->pulls = [new TaskChangeSet([], 'token-1')];
        $this->service()->sync($this->list);

        self::assertSame(['push', 'pull'], $this->calls);
        self::assertSame(SyncState::Clean, $task->syncState);
        self::assertSame('https://dav/tasks/' . $task->uid, $task->remoteId);
        self::assertSame('token-1', $this->list->syncToken);
    }

    public function testACreateComingBackInTheSameRunIsNotInsertedTwice(): void
    {
        $task = $this->localTask('Mine', SyncState::PendingCreate);
        $uid  = $task->uid;

        $this->pulls = [new TaskChangeSet([new RemoteTask('https://dav/tasks/' . $uid, '"pushed"', uid: $uid, title: 'Mine')], 'token-1')];
        $this->service()->sync($this->list);

        self::assertCount(1, $this->em->getRepository(Task::class)->findBy(['taskList' => $this->list]));
    }

    public function testAMatchingEtagIsNotAChange(): void
    {
        $task             = $this->localTask('Typed since', SyncState::Clean);
        $task->remoteId   = 'r1';
        $task->remoteEtag = '"same"';
        $this->em->flush();

        $this->pulls = [new TaskChangeSet([new RemoteTask('r1', '"same"', uid: $task->uid, title: 'Older copy')], 't')];
        $this->service()->sync($this->list);

        self::assertSame('Typed since', $task->title);
    }

    public function testARealChangeMeansTheRemoteWinsEvenOverAPendingEdit(): void
    {
        $task             = $this->localTask('Local edit', SyncState::PendingUpdate);
        $task->remoteId   = 'r1';
        $task->remoteEtag = '"old"';
        $this->em->flush();

        // The push failed with a 412, which leaves the row pending for the pull.
        $this->pushFailure = new CalendarResyncRequiredException('Changed', 412);
        $this->pulls       = [new TaskChangeSet([new RemoteTask('r1', '"new"', uid: $task->uid, title: 'Remote edit', payload: 'ICS')], 't')];

        $this->service()->sync($this->list);

        self::assertSame('Remote edit', $task->title);
        self::assertSame(SyncState::Clean, $task->syncState);
        self::assertSame('ICS', $task->remotePayload);
    }

    public function testADeletedTaskIsToldToTheRemoteAndOnlyThenRemoved(): void
    {
        $task           = $this->localTask('Gone', SyncState::PendingDelete);
        $task->remoteId = 'r1';
        $this->em->flush();
        $id = $task->id;

        $this->pulls = [new TaskChangeSet([], 't')];
        $this->service()->sync($this->list);

        self::assertSame(['delete', 'pull'], $this->calls);
        self::assertNull($this->em->getRepository(Task::class)->find($id));
    }

    public function testATombstoneRemovesTheRow(): void
    {
        $task           = $this->localTask('There', SyncState::Clean);
        $task->remoteId = 'r1';
        $this->em->flush();
        $id = $task->id;

        $this->list->syncToken = 'old';
        $this->pulls           = [new TaskChangeSet([RemoteTask::deleted('r1')], 'new')];
        $this->service()->sync($this->list);

        self::assertNull($this->em->getRepository(Task::class)->find($id));
    }

    public function testADeadTokenCostsOneFullReadAndThatReadIsAuthoritative(): void
    {
        $kept           = $this->localTask('Kept', SyncState::Clean);
        $kept->remoteId = 'r1';
        $gone           = $this->localTask('Gone', SyncState::Clean);
        $gone->remoteId = 'r2';
        $unsent         = $this->localTask('Never sent', SyncState::Clean);
        $this->em->flush();
        $goneId = $gone->id;

        $this->list->syncToken = 'dead';
        $this->pulls           = [
            TaskChangeSet::resyncRequired(),
            new TaskChangeSet([new RemoteTask('r1', '"e"', uid: $kept->uid, title: 'Kept')], 'fresh'),
        ];

        $this->service()->sync($this->list);

        self::assertSame('fresh', $this->list->syncToken);
        self::assertNull($this->em->getRepository(Task::class)->find($goneId), 'a full read removes what it did not mention');
        self::assertNotNull($this->em->getRepository(Task::class)->find($unsent->id), 'a row with no remote id cannot be missing from one');
    }

    public function testAFullReadLeavesAFinishedEntryAloneAndNothingPushesIt(): void
    {
        $series           = $this->localTask('Weekly', SyncState::Clean);
        $series->remoteId = 'r1';

        $entry               = $this->localTask('Weekly', SyncState::Clean);
        $entry->occurrenceOf = $series;
        $entry->status       = TaskStatus::Completed;
        $this->em->flush();

        $this->pulls = [new TaskChangeSet([new RemoteTask('r1', '"e"', uid: $series->uid, title: 'Weekly')], 't')];
        $this->service()->sync($this->list);

        self::assertSame(['pull'], $this->calls);
        self::assertNotNull($this->em->getRepository(Task::class)->find($entry->id));
    }

    public function testADeltaWindowRemovesNothingItDidNotMention(): void
    {
        $task           = $this->localTask('Quiet', SyncState::Clean);
        $task->remoteId = 'r1';
        $this->em->flush();
        $id = $task->id;

        $this->list->syncToken = 'old';
        $this->pulls           = [new TaskChangeSet([], 'new')];
        $this->service()->sync($this->list);

        self::assertNotNull($this->em->getRepository(Task::class)->find($id));
    }

    public function testAReadOnlyListIsNeverPushedTo(): void
    {
        $this->localTask('Stuck', SyncState::PendingUpdate);
        $this->list->isReadOnly = true;

        $this->pulls = [new TaskChangeSet([], 't')];
        $this->service()->sync($this->list);

        self::assertSame(['pull'], $this->calls);
    }

    public function testAStepArrivingBeforeItsParentIsStillPlacedUnderIt(): void
    {
        $this->pulls = [new TaskChangeSet([
            new RemoteTask('s', '"1"', uid: 'step', title: 'Step', parentUid: 'parent'),
            new RemoteTask('p', '"2"', uid: 'parent', title: 'Parent'),
        ], 't')];

        $this->service()->sync($this->list);

        $step = $this->em->getRepository(Task::class)->findOneBy(['taskList' => $this->list, 'uid' => 'step']);

        self::assertSame('parent', $step?->parent?->uid);
    }

    public function testAStepOfAStepGoesUnderTheTopTaskSoNothingIsHidden(): void
    {
        $this->pulls = [new TaskChangeSet([
            new RemoteTask('a', '"1"', uid: 'top', title: 'Top'),
            new RemoteTask('b', '"2"', uid: 'middle', title: 'Middle', parentUid: 'top'),
            new RemoteTask('c', '"3"', uid: 'bottom', title: 'Bottom', parentUid: 'middle'),
        ], 't')];

        $this->service()->sync($this->list);

        $bottom = $this->em->getRepository(Task::class)->findOneBy(['taskList' => $this->list, 'uid' => 'bottom']);

        self::assertSame('top', $bottom?->parent?->uid);
    }

    /** How a remote with no uids of its own, Microsoft To Do, names a step's task. */
    public function testAStepNamingItsTaskByRemoteIdIsPlacedUnderIt(): void
    {
        $parent             = $this->localTask('Mine', SyncState::Clean);
        $parent->remoteId   = 'T1';
        $parent->remoteEtag = '"same"';
        $this->em->flush();

        $this->pulls = [new TaskChangeSet([new RemoteTask('T1/C1', null, title: 'Step', parentUid: 'T1')], 't')];
        $this->service()->sync($this->list);

        self::assertSame($parent->id, $this->em->getRepository(Task::class)->findOneBy(['remoteId' => 'T1/C1'])?->parent?->id);
    }

    /** Regression guard: a delta names only the task, so a step dropped elsewhere would otherwise stay forever. */
    public function testAStepTheTaskNoLongerNamesIsGone(): void
    {
        $parent           = $this->localTask('Mine', SyncState::Clean);
        $parent->remoteId = 'T1';

        $kept           = $this->localTask('Kept', SyncState::Clean);
        $kept->remoteId = 'T1/C1';
        $kept->parent   = $parent;

        $dropped           = $this->localTask('Dropped', SyncState::Clean);
        $dropped->remoteId = 'T1/C2';
        $dropped->parent   = $parent;

        $parent->steps->add($kept);
        $parent->steps->add($dropped);
        $this->em->flush();

        $droppedId = $dropped->id;

        $this->pulls = [new TaskChangeSet([
            new RemoteTask('T1', '"2"', title: 'Mine', stepRemoteIds: ['T1/C1']),
            new RemoteTask('T1/C1', null, title: 'Kept', parentUid: 'T1'),
        ], 't')];

        $this->service()->sync($this->list);

        self::assertNull($this->em->find(Task::class, $droppedId));
        self::assertSame(['Kept'], array_map(static fn (Task $step): string => $step->title, $parent->liveSteps()));
    }

    /** Off is plMail not talking to the server about it: nothing goes out, nothing is read. */
    public function testAListOfAnAccountWithTasksOffIsLeftAlone(): void
    {
        $task = $this->localTask('Waits', SyncState::PendingCreate);

        // Not persisted, and nothing flushes before the sync turns back.
        $account               = new Account();
        $account->tasksEnabled = false;
        $this->list->account   = $account;

        self::assertSame(0, $this->service()->sync($this->list));
        self::assertSame([], $this->calls);
        self::assertSame(SyncState::PendingCreate, $task->syncState);

        $this->list->account = null;
    }

    public function testAStepWhoseParentNobodyHasStaysATaskRatherThanVanishing(): void
    {
        $this->pulls = [new TaskChangeSet([new RemoteTask('s', '"1"', uid: 'orphan', title: 'Orphan', parentUid: 'nowhere')], 't')];

        $this->service()->sync($this->list);

        self::assertNull($this->em->getRepository(Task::class)->findOneBy(['uid' => 'orphan'])?->parent);
    }

    public function testAClientDroppingALinkDoesNotCostAKeptFile(): void
    {
        $task             = $this->localTask('With a file', SyncState::Clean);
        $task->remoteId   = 'r1';
        $task->remoteEtag = '"old"';

        $kept             = new TaskAttachment();
        $kept->task       = $task;
        $kept->usr        = $task->usr;
        $kept->filename   = 'kept.pdf';
        $kept->storedName = 'stored';
        $task->attachments->add($kept);

        $foreign              = new TaskAttachment();
        $foreign->task        = $task;
        $foreign->usr         = $task->usr;
        $foreign->filename    = 'gone.pdf';
        $foreign->externalUrl = 'https://example.org/gone.pdf';
        $task->attachments->add($foreign);
        $this->em->flush();

        $this->list->syncToken = 'old';
        $this->pulls           = [new TaskChangeSet([new RemoteTask(
            'r1',
            '"new"',
            uid: $task->uid,
            title: 'With a file',
            links: [['url' => 'https://example.org/new.pdf', 'filename' => 'new.pdf']],
        )], 't')];

        $this->service()->sync($this->list);

        $names = array_values(array_map(static fn (TaskAttachment $attachment): string => $attachment->filename, $task->attachments->toArray()));

        self::assertEqualsCanonicalizing(['kept.pdf', 'new.pdf'], $names);
    }

    public function testAFailureIsRecordedBacksOffAndKeepsThePosition(): void
    {
        $this->list->syncToken = 'position';
        $this->pulls           = [];

        try {
            $this->service(new CalendarSyncPermanentException('The password was refused.'))->sync($this->list);
            self::fail('The failure was swallowed.');
        } catch (CalendarSyncPermanentException) {
            self::assertSame('The password was refused.', $this->list->lastSyncError);
            self::assertSame(1, $this->list->syncFailureCount);
            self::assertNotNull($this->list->syncBackoffUntil);
            self::assertSame('position', $this->list->syncToken);
        }
    }

    public function testAPermanentlyRefusedTaskIsGivenUpOnAndTheRestStillGoOut(): void
    {
        $refused = $this->localTask('Refused', SyncState::PendingCreate);
        $fine    = $this->localTask('Fine', SyncState::PendingCreate);

        $this->pushFailure = new CalendarSyncPermanentException('No');
        $this->pulls       = [new TaskChangeSet([], 't')];

        $this->service()->sync($this->list);

        self::assertSame(SyncState::Clean, $refused->syncState);
        self::assertNull($refused->remoteId);
        self::assertSame('https://dav/tasks/' . $fine->uid, $fine->remoteId);
    }

    private function service(?CalendarSyncException $pullFailure = null): TaskSyncService
    {
        $em = $this->em;

        return new TaskSyncService(
            new TaskSyncDriverRegistry([$this->driver($pullFailure)]),
            new TaskPusher(self::getContainer()->get(TaskRepository::class), $em, new NullLogger()),
            new TaskPuller(self::getContainer()->get(TaskRepository::class), $em, new NullLogger()),
            $em,
            new NullLogger(),
        );
    }

    private function driver(?CalendarSyncException $pullFailure): TaskSyncDriverInterface
    {
        $test = $this;

        return new class ($test, $pullFailure) implements TaskSyncDriverInterface {
            public function __construct(private TaskSyncServiceTest $test, private ?CalendarSyncException $pullFailure) {}

            public function supports(CalendarSource $source): bool
            {
                return true;
            }

            public function discover(CalendarSource $source): array
            {
                return [];
            }

            public function pull(TaskList $list, ?string $syncToken): TaskChangeSet
            {
                if (null !== $this->pullFailure) {
                    throw $this->pullFailure;
                }

                return $this->test->nextPull();
            }

            public function push(TaskList $list, Task $task): RemoteTaskWriteResult
            {
                return $this->test->answerPush($task);
            }

            public function delete(TaskList $list, Task $task): void
            {
                $this->test->record('delete');
            }
        };
    }

    public function record(string $call): void
    {
        $this->calls[] = $call;
    }

    public function nextPull(): TaskChangeSet
    {
        $this->calls[] = 'pull';

        return array_shift($this->pulls) ?? new TaskChangeSet();
    }

    public function answerPush(Task $task): RemoteTaskWriteResult
    {
        $this->calls[] = 'push';

        // The failure is for the first row asked about, and only that one.
        if (null !== $this->pushFailure) {
            $failure           = $this->pushFailure;
            $this->pushFailure = null;

            throw $failure;
        }

        return new RemoteTaskWriteResult('https://dav/tasks/' . $task->uid, '"pushed"', 'PAYLOAD');
    }

    private function localTask(string $title, SyncState $state): Task
    {
        $task            = new Task();
        $task->usr       = $this->list->usr;
        $task->taskList  = $this->list;
        $task->title     = $title;
        $task->uid       = uniqid('uid-', true);
        $task->syncState = $state;
        $this->em->persist($task);
        $this->em->flush();

        return $task;
    }
}
