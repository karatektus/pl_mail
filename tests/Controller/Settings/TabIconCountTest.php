<?php

declare(strict_types=1);

namespace App\Tests\Controller\Settings;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\User\TabIconCountKind;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Service\Mail\NewMailMarkers;
use App\Service\Mail\TabIconCount;
use App\Service\Mail\UnifiedInboxes;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * What the number on the browser tab icon is made of (#662): new or unread,
 * one account group or all, every workspace or the one on screen — read off
 * the counts endpoint the sidebar asks, and the page's meta tag where the
 * icon starts from.
 *
 * Two accounts, each in a group of its own, each with two unread
 * conversations in the Inbox, one of them already shown in a list and so no
 * longer new. And a workspace over the second group only, the active one.
 */
final class TabIconCountTest extends WebTestCase
{
    use SeedsMarkerFixtures;

    private KernelBrowser $client;
    private Connection $connection;
    private Account $work;
    private int $workGroup;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $container = static::getContainer();

        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

        $this->user  = $this->seedUser();
        $this->inbox = $this->seedLabel('Inbox', LabelRole::Inbox);
        $this->account = $this->seedAccount();
        $this->mail(listed: 'Home, seen in a list', fresh: 'Home, new');
        $this->work  = $this->account = $this->seedAccount();
        $this->mail(listed: 'Work, seen in a list', fresh: 'Work, new');

        $inboxes = $container->get(UnifiedInboxes::class);
        $group   = $inboxes->create($this->user, 'Work', 'inbox');
        $inboxes->assign($this->user, $this->work, $group);
        $this->workGroup = (int) $group->id;

        $this->user->setSetting(User::SETTING_WORKSPACES, ['workspaces' => [
            ['id' => 'w1', 'name' => 'Everything', 'layout' => ['t' => 'pane', 'kind' => 'list']],
            ['id' => 'w2', 'name' => 'Work', 'layout' => ['t' => 'pane', 'kind' => 'list'], 'groups' => [$this->workGroup]],
        ], 'active' => 1]);
        $this->user->tabIconCount = true;
        $this->em->flush();

        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testOffSendsNoNumber(): void
    {
        $this->choose(static fn (User $user) => $user->tabIconCount = false);

        self::assertArrayNotHasKey(TabIconCount::KEY, $this->counts());
        self::assertCount(0, $this->client->request('GET', '/settings/notifications')->filter('meta[name="tab-icon-count"]'));
    }

    /**
     * The defaults: new mail, every group, every workspace — though the
     * workspace on screen shows only Work, and the title says so.
     */
    public function testByDefaultItCountsNewMailOfEveryWorkspace(): void
    {
        $counts = $this->counts();

        self::assertSame(2, $counts[TabIconCount::KEY], 'the two new conversations, one per account');
        self::assertSame(2, $counts['role:inbox'], 'the title: Work alone, its two unread');
        self::assertSame(1, $counts[NewMailMarkers::roleKey(LabelRole::Inbox)], 'the sidebar dot: Work alone');
        self::assertSame('2', $this->client->request('GET', '/settings/notifications')->filter('meta[name="tab-icon-count"]')->attr('content'));
    }

    public function testUnreadCountsWhatWasShownToo(): void
    {
        $this->choose(static fn (User $user) => $user->tabIconCountKind = TabIconCountKind::Unread);

        self::assertSame(4, $this->counts()[TabIconCount::KEY]);
    }

    public function testThisWorkspaceCountsOnlyItsAccounts(): void
    {
        $this->choose(static fn (User $user) => $user->tabIconCountThisWorkspace = true);

        self::assertSame(1, $this->counts()[TabIconCount::KEY]);
    }

    public function testOneGroupCountsOnlyItsAccounts(): void
    {
        $first = static::getContainer()->get(UnifiedInboxes::class)->first($this->em->find(User::class, $this->user->id));

        $this->choose(static fn (User $user) => $user->tabIconCountInbox = (int) $first->id);
        self::assertSame(1, $this->counts()[TabIconCount::KEY], 'Home alone');

        // A group outside the workspace on screen, with "this workspace" on:
        // nothing of it is on screen, so nothing is counted.
        $this->choose(static fn (User $user) => $user->tabIconCountThisWorkspace = true);
        self::assertSame(0, $this->counts()[TabIconCount::KEY]);
    }

    /** An account in two groups is one account's mail, counted once (#783). */
    public function testEveryGroupTogetherCountsAnAccountInTwoGroupsOnce(): void
    {
        $inboxes = static::getContainer()->get(UnifiedInboxes::class);
        $user    = $this->em->find(User::class, $this->user->id);
        $work    = $this->em->find(Account::class, $this->work->id);

        $inboxes->addAccount($user, $work, $inboxes->first($user));
        $this->em->flush();

        self::assertSame(2, $this->counts()[TabIconCount::KEY]);
    }

    /** Two unread conversations in the current account's Inbox, the first already listed. */
    private function mail(string $listed, string $fresh): void
    {
        $shown = $this->thread($listed, lastMessageAt: '-5 minutes', unread: 1);
        $this->thread($fresh, lastMessageAt: '-1 minute', unread: 1);

        $this->connection->executeStatement(
            'UPDATE message_thread SET listed_at = :at WHERE id = :id',
            ['at' => new DateTimeImmutable()->format('Y-m-d H:i:s'), 'id' => $shown->id],
        );
    }

    /** @param callable(User): mixed $change */
    private function choose(callable $change): void
    {
        $user = $this->em->find(User::class, $this->user->id);
        self::assertInstanceOf(User::class, $user);

        $change($user);
        $this->em->flush();
    }

    /** @return array<string, mixed> */
    private function counts(): array
    {
        $this->client->request('GET', '/mail/sidebar/counts');

        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
    }
}
