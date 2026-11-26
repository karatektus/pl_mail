<?php

declare(strict_types=1);

namespace App\Tests\Service\Layout;

use App\Domain\DTO\Layout\Workspaces;
use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Service\Layout\WorkspaceNews;
use App\Service\Layout\WorkspaceScope;
use App\Service\Layout\WorkspaceStore;
use App\Service\Mail\UnifiedInboxes;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A workspace tab's "new" count (#783): what reached the workspace since it
 * was last on screen, counted in its own accounts, cleared by a visit.
 *
 * New is not unread, and these pin the difference: mail read elsewhere still
 * counts, mail the user sent does not, mail in another workspace's accounts
 * does not, and nothing that came before the first visit is news.
 */
final class WorkspaceNewsTest extends KernelTestCase
{
    use SeedsMarkerFixtures;

    private Connection $connection;
    private WorkspaceNews $news;
    private Account $second;
    private Label $sent;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->news       = $container->get(WorkspaceNews::class);

        $this->connection->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount();
        $this->second  = $this->seedAccount();
        $this->inbox   = $this->seedLabel('Inbox', LabelRole::Inbox);
        $this->sent    = $this->seedLabel('Sent', LabelRole::Sent);

        $groups = $container->get(UnifiedInboxes::class);
        $work   = $groups->create($this->user, 'Work', 'briefcase');
        $groups->assign($this->user, $this->account, $work);

        $this->user->setSetting(User::SETTING_WORKSPACES, ['workspaces' => [
            ['id' => 'home', 'name' => 'Home', 'layout' => ['t' => 'pane', 'kind' => 'list']],
            ['id' => 'job', 'name' => 'Job', 'layout' => ['t' => 'pane', 'kind' => 'list'], 'groups' => [(int) $work->id]],
            ['id' => 'plan', 'name' => 'Plan', 'layout' => ['t' => 'pane', 'kind' => 'calendar'], 'groups' => [(int) $work->id]],
        ], 'active' => 0]);
        $this->em->flush();

        $container->get(WorkspaceScope::class)->reset();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAWorkspaceNeverLookedAtHasNothingNew(): void
    {
        $this->arrived($this->account, '-1 hour');

        self::assertSame(['job' => ['count' => 0, 'soon' => false], 'plan' => ['count' => 0, 'soon' => false]], $this->tabs());
    }

    public function testWhatArrivedInItsAccountsSinceTheVisitCounts(): void
    {
        $this->seen('job', '-1 hour');

        $this->arrived($this->account, 'now');               // its account: news, read or not
        $this->arrived($this->account, 'now', read: true);
        $this->arrived($this->second, 'now');                // another workspace's account
        $this->arrived($this->account, 'now', sent: true);   // what the user sent

        self::assertSame(2, $this->tabs()['job']['count']);
        self::assertSame(0, $this->tabs()['plan']['count'], 'a workspace without a mail list counts no mail');
    }

    public function testAVisitClearsItAndTheWorkspaceOnScreenIsNotATab(): void
    {
        $this->seen('job', '-1 hour');
        $this->arrived($this->account, 'now');

        self::assertTrue($this->news->touch($this->user, 'job'));
        self::assertSame(0, $this->tabs()['job']['count']);
        self::assertArrayNotHasKey('home', $this->tabs(), 'the active one is being looked at');
    }

    public function testATouchWithinHalfAMinuteWritesNothing(): void
    {
        self::assertTrue($this->news->touch($this->user, 'job'));
        self::assertFalse($this->news->touch($this->user, 'job'), 'every list click would otherwise be a settings write');
    }

    public function testAWorkspaceThatIsGoneIsForgotten(): void
    {
        $this->news->touch($this->user, 'job', 'gone');

        $this->news->prune($this->user, self::getContainer()->get(WorkspaceStore::class)->load($this->user));

        self::assertSame(['job'], array_keys($this->user->getSetting(User::SETTING_WORKSPACES_SEEN)));
    }

    public function testThePulseAsksAgainByTomorrowAtTheLatest(): void
    {
        $pulse = $this->news->pulse($this->user);

        self::assertFalse($pulse['soon'], 'nothing in any calendar or list');
        self::assertGreaterThan(new DateTimeImmutable(), $pulse['next']);
        self::assertLessThanOrEqual(new DateTimeImmutable('+1 day'), $pulse['next']);
    }

    /** @return array<string, array{count: int, soon: bool}> */
    private function tabs(): array
    {
        $workspaces = self::getContainer()->get(WorkspaceStore::class)->load($this->user);

        self::assertInstanceOf(Workspaces::class, $workspaces);

        return $this->news->forTabs($this->user, $workspaces);
    }

    private function seen(string $workspace, string $when): void
    {
        $seen             = $this->user->getSetting(User::SETTING_WORKSPACES_SEEN) ?? [];
        $seen[$workspace] = (new DateTimeImmutable($when))->getTimestamp();

        $this->user->setSetting(User::SETTING_WORKSPACES_SEEN, $seen);
    }

    /** One mail in an account's inbox, its row made at $when. */
    private function arrived(Account $account, string $when, bool $read = false, bool $sent = false): void
    {
        $previous      = $this->account;
        $this->account = $account;
        $thread        = $this->thread('Hello ' . uniqid(), unread: $read ? 0 : 1);
        $this->account = $previous;

        foreach ($thread->messages as $message) {
            $message->addLabel($this->inbox);

            if ($sent) {
                $message->addLabel($this->sent);
            }
        }

        $this->em->flush();

        $this->connection->executeStatement(
            'UPDATE message SET created_at = :at WHERE thread_id = :thread',
            ['at' => (new DateTimeImmutable($when))->format('Y-m-d H:i:s'), 'thread' => $thread->id],
        );
    }
}
