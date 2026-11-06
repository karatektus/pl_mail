<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageCategory;
use App\Entity\Mail\Account;
use App\Entity\Mail\UnifiedInbox;
use App\Service\Mail\ListViewResolver;
use App\Service\Mail\UnifiedInboxes;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Two unified inboxes over two accounts (#265): each lists, counts and tabs
 * only its own accounts' mail, the sidebar draws both under their own names,
 * and select-all reaches the one that was on screen.
 */
final class UnifiedInboxListTest extends WebTestCase
{
    use SeedsMarkerFixtures;

    private KernelBrowser $client;
    private Connection $connection;
    private UnifiedInboxes $inboxes;
    private Account $personal;
    private Account $workAccount;
    private UnifiedInbox $first;
    private UnifiedInbox $work;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->inboxes    = $container->get(UnifiedInboxes::class);

        $this->connection->beginTransaction();

        $this->user        = $this->seedUser();
        $this->personal    = $this->seedAccount();
        $this->workAccount = $this->seedAccount();
        $this->inbox       = $this->seedLabel('Inbox', LabelRole::Inbox);

        $this->account = $this->personal;
        $this->thread('Holiday photos', MessageCategory::Primary, unread: 1);
        $this->thread('Garden centre sale', MessageCategory::Promotions, unread: 1);

        $this->account = $this->workAccount;
        $this->thread('Quarterly report', MessageCategory::Primary, unread: 1);
        $this->thread('Team offsite', MessageCategory::Primary);

        $this->first = $this->inboxes->first($this->user);
        $this->work  = $this->inboxes->create($this->user, 'Work', 'briefcase');
        $this->inboxes->assign($this->user, $this->workAccount, $this->work);

        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testEachInboxListsOnlyItsOwnAccounts(): void
    {
        $this->client->request('GET', '/mail/inbox/' . $this->work->id);
        self::assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('Quarterly report', $html);
        self::assertStringNotContainsString('Holiday photos', $html);

        // The bare address is the first inbox.
        $this->client->request('GET', '/mail/inbox');
        $html = (string) $this->client->getResponse()->getContent();

        self::assertStringContainsString('Holiday photos', $html);
        self::assertStringNotContainsString('Quarterly report', $html);
    }

    public function testTheTabStripIsTheInboxsOwn(): void
    {
        // Promotions holds mail only in the first inbox, so Work has no strip.
        $this->client->request('GET', '/mail/inbox/' . $this->work->id);
        self::assertStringNotContainsString('tab=promotions', (string) $this->client->getResponse()->getContent());

        $this->client->request('GET', '/mail/inbox');
        self::assertStringContainsString(
            '/mail/inbox/' . $this->first->id . '?tab=promotions',
            (string) $this->client->getResponse()->getContent(),
        );
    }

    public function testTheSidebarDrawsEveryInboxWithItsOwnBadge(): void
    {
        $crawler = $this->client->request('GET', '/mail/inbox/' . $this->work->id);

        $rows = $crawler->filter('#sidebar [data-unified-inbox-row]');

        self::assertSame(
            [(string) $this->first->id, (string) $this->work->id],
            $rows->each(static fn ($row): string => (string) $row->attr('data-unified-inbox-row')),
            'the first inbox and Work; Other is empty, so not drawn',
        );
        self::assertStringContainsString('Work', $rows->eq(1)->text());
        self::assertSame(1, $rows->eq(1)->filter('.fa-briefcase')->count());

        self::assertSame('2', trim($crawler->filter('#sidebar [data-count-key="inbox:' . $this->first->id . '"]')->text()));
        self::assertSame('1', trim($crawler->filter('#sidebar [data-count-key="inbox:' . $this->work->id . '"]')->text()));

        // The open one is Work, and only Work.
        self::assertStringContainsString('is-active', (string) $rows->eq(1)->attr('class'));
        self::assertStringNotContainsString('is-active', (string) $rows->eq(0)->attr('class'));

        // The page's title counts the open inbox.
        self::assertSelectorExists('meta[name="title-count-key"][content="inbox:' . $this->work->id . '"]');
    }

    public function testTheCountsEndpointCountsEachInboxAndScopesTheTabsToTheOpenOne(): void
    {
        $this->client->request('GET', '/mail/sidebar/counts?inbox=' . $this->work->id);
        $counts = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertSame(2, $counts['inbox:' . $this->first->id]);
        self::assertSame(1, $counts['inbox:' . $this->work->id]);
        self::assertSame(3, $counts['role:inbox'], 'the whole Inbox, whichever list it is in');
        self::assertSame(1, $counts['category:primary'], 'Work\'s Primary: the report, not the photos');
        self::assertSame(0, $counts['category:promotions']);

        $this->client->request('GET', '/mail/sidebar/counts?inbox=' . $this->first->id);
        $counts = json_decode((string) $this->client->getResponse()->getContent(), true);

        self::assertSame(1, $counts['category:primary']);
        self::assertSame(1, $counts['category:promotions']);
    }

    public function testAnotherReadersInboxIsNotFound(): void
    {
        $this->client->request('GET', '/mail/inbox/999999999');

        self::assertResponseStatusCodeSame(404);
    }

    public function testSelectAllReachesOnlyTheInboxThatWasOnScreen(): void
    {
        $resolver = static::getContainer()->get(ListViewResolver::class);

        $subjects = static fn (array $threads): array => array_map(static fn ($thread): string => (string) $thread->subject, $threads);

        self::assertEqualsCanonicalizing(
            ['Quarterly report', 'Team offsite'],
            $subjects($resolver->threadsIn($this->user, 'inbox', UnifiedInboxes::listValue($this->work, 'primary'), false)),
        );

        // A value from before there were several inboxes is the first one.
        self::assertSame(['Holiday photos'], $subjects($resolver->threadsIn($this->user, 'inbox', 'primary', false)));

        // One that is not the reader's selects nothing.
        self::assertSame([], $resolver->threadsIn($this->user, 'inbox', '999999999:primary', false));
    }
}
