<?php

declare(strict_types=1);

namespace App\Tests\Service\Layout;

use App\Domain\DTO\Layout\Workspace;
use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Calendar\Calendar;
use App\Entity\Mail\Account;
use App\Entity\Mail\UnifiedInbox;
use App\Entity\Task\TaskList;
use App\Entity\User\User;
use App\Service\Layout\WorkspacePin;
use App\Service\Layout\WorkspaceScope;
use App\Service\Layout\WorkspaceStore;
use App\Service\Mail\UnifiedInboxes;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What a workspace shows (#783): the accounts of its account groups, their
 * calendars and task lists, and the ones without an account that were placed
 * in it — the first workspace unless the user chose another.
 *
 * Against the real container, because the answer is made of the user's
 * settings (the workspaces), the group rows and the calendar rows together,
 * and each of those has its own rule for what is gone or never set.
 */
final class WorkspaceScopeTest extends KernelTestCase
{
    use SeedsMarkerFixtures;

    private Connection $connection;
    private WorkspaceScope $scope;
    private UnifiedInboxes $groups;
    private Account $second;
    private UnifiedInbox $work;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->scope      = $container->get(WorkspaceScope::class);
        $this->groups     = $container->get(UnifiedInboxes::class);

        $this->connection->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount();
        $this->second  = $this->seedAccount();
        $this->inbox   = $this->seedLabel('Inbox', LabelRole::Inbox);

        // The first account in Work alone; the second stays in the first group.
        $this->work = $this->groups->create($this->user, 'Work', 'briefcase');
        $this->groups->assign($this->user, $this->account, $this->work);

        $this->workspaces([
            ['id' => 'all', 'name' => 'Everything', 'layout' => ['t' => 'pane', 'kind' => 'list']],
            ['id' => 'job', 'name' => 'Job', 'layout' => ['t' => 'pane', 'kind' => 'list'], 'groups' => [(int) $this->work->id]],
        ]);
    }

    protected function tearDown(): void
    {
        self::getContainer()->get(WorkspacePin::class)->reset();

        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAWorkspaceThatNeverChoseShowsEveryAccount(): void
    {
        self::assertNull($this->scope->accountIds($this->user));
        self::assertNull($this->scope->groupIds($this->user));
        self::assertNull($this->scope->calendarIds($this->user));
        self::assertTrue($this->scope->includes($this->user, $this->second));
    }

    public function testAWorkspaceOfGroupsShowsTheirAccounts(): void
    {
        $this->activate(1);

        self::assertSame([(int) $this->account->id], $this->scope->accountIds($this->user));
        self::assertSame([(int) $this->work->id], $this->scope->groupIds($this->user));
        self::assertFalse($this->scope->includes($this->user, $this->second));

        // A list of accounts narrowed to it; null in is the scope itself.
        self::assertSame([(int) $this->account->id], $this->scope->narrow($this->user, [(int) $this->account->id, (int) $this->second->id]));
        self::assertSame([(int) $this->account->id], $this->scope->narrow($this->user, null));
    }

    public function testAWorkspaceWhoseGroupsAreGoneShowsEveryAccountRatherThanNothing(): void
    {
        $this->workspaces([
            ['id' => 'all', 'name' => 'Everything', 'layout' => ['t' => 'pane', 'kind' => 'list']],
            ['id' => 'job', 'name' => 'Job', 'layout' => ['t' => 'pane', 'kind' => 'list'], 'groups' => [999_999]],
        ], 1);

        self::assertNull($this->scope->accountIds($this->user));
    }

    public function testThePinOutranksTheSavedChoiceForOneRequest(): void
    {
        self::getContainer()->get(WorkspacePin::class)->pin('job');
        $this->scope->reset();

        self::assertSame([(int) $this->account->id], $this->scope->accountIds($this->user), 'drawn for the pinned workspace');
        self::assertSame(0, $this->stored()['active'], 'and nothing written');
    }

    public function testSpaceNamesAWorkspaceWithoutRegardToCase(): void
    {
        self::assertSame([(int) $this->account->id], $this->scope->forSpace($this->user, ' JOB '));
        self::assertNull($this->scope->forSpace($this->user, 'everything'));
        self::assertSame([], $this->scope->forSpace($this->user, 'Holidays'), 'no such workspace finds nothing');
    }

    public function testCalendarsFollowTheirAccountAndALocalOneItsPlace(): void
    {
        $work  = $this->calendar('Work', $this->account);
        $home  = $this->calendar('Home', $this->second);
        $local = $this->calendar('Birthdays', null);

        $job = $this->workspace(1);
        $all = $this->workspace(0);

        // In the first workspace until placed; the first shows everything.
        self::assertSame('all', $this->scope->homeOf($this->user, $local));
        self::assertSame([(int) $work->id], $this->scope->calendarIds($this->user, $job));
        self::assertNull($this->scope->calendarIds($this->user, $all));
        self::assertNull($this->scope->homeOf($this->user, $home), 'one of an account goes where its account goes');

        $this->scope->place($this->user, $local, 'job');

        self::assertSame('job', $this->scope->homeOf($this->user, $local));
        self::assertEqualsCanonicalizing([(int) $work->id, (int) $local->id], $this->scope->calendarIds($this->user, $job));

        // Back to the first: nothing stored, so it follows whichever is first.
        $this->scope->place($this->user, $local, 'all');
        self::assertNull($this->user->localWorkspaceOf('calendar', (int) $local->id));

        // A workspace that is gone is the first too.
        $this->scope->place($this->user, $local, 'nowhere');
        self::assertSame('all', $this->scope->homeOf($this->user, $local));
    }

    public function testTaskListsFollowTheSameRule(): void
    {
        $work  = $this->taskList('Work', $this->account);
        $local = $this->taskList('Errands', null);

        $this->scope->place($this->user, $local, 'job');

        self::assertEqualsCanonicalizing([(int) $work->id, (int) $local->id], $this->scope->taskListIds($this->user, $this->workspace(1)));
    }

    public function testAnEditorOffersTheWorkspacesOnlyForOneWithoutAnAccount(): void
    {
        $local = $this->calendar('Birthdays', null);
        $owned = $this->calendar('Work', $this->account);

        self::assertSame(['workspaces' => ['Everything' => 'all', 'Job' => 'job'], 'workspace' => 'all'], $this->scope->placeChoices($this->user, $local));
        self::assertSame(['workspaces' => [], 'workspace' => null], $this->scope->placeChoices($this->user, $owned));
    }

    /** @param list<array<string, mixed>> $workspaces */
    private function workspaces(array $workspaces, int $active = 0): void
    {
        $this->user->setSetting(User::SETTING_WORKSPACES, ['workspaces' => $workspaces, 'active' => $active]);
        $this->em->flush();
        $this->scope->reset();
    }

    private function activate(int $index): void
    {
        $document           = $this->stored();
        $document['active'] = $index;

        $this->workspaces($document['workspaces'], $index);
    }

    /** @return array{workspaces: list<array<string, mixed>>, active: int} */
    private function stored(): array
    {
        return $this->user->getSetting(User::SETTING_WORKSPACES);
    }

    private function workspace(int $index): Workspace
    {
        return self::getContainer()->get(WorkspaceStore::class)->load($this->user)->all[$index];
    }

    private function calendar(string $name, ?Account $account): Calendar
    {
        $calendar           = new Calendar();
        $calendar->usr      = $this->user;
        $calendar->account  = $account;
        $calendar->name     = $name;
        $calendar->timeZone = 'UTC';

        $this->em->persist($calendar);
        $this->em->flush();

        return $calendar;
    }

    private function taskList(string $name, ?Account $account): TaskList
    {
        $list          = new TaskList();
        $list->usr     = $this->user;
        $list->account = $account;
        $list->name    = $name;

        $this->em->persist($list);
        $this->em->flush();

        return $list;
    }
}
