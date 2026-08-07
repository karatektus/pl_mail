<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Domain\Enum\Mail\SearchSortOrder;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The search page's order switch, end to end.
 *
 * Search answered in `ts_rank` order and only that, which put a mail from 2004
 * between two from 2026 and read as a page that had ignored the question. The
 * default is now newest-first and relevance is the other position of a switch
 * beside the pagination — so the things worth pinning are which order arrives
 * unasked, that asking changes it in both directions, and that having asked
 * once carries into the next search, when the URL says nothing at all.
 *
 * Asserted on the rendered order of rows rather than on the SQL: the SQL is the
 * repository's test, and what a reader is promised here is the page.
 */
final class SearchSortEndpointTest extends WebTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private User $user;
    private Account $account;

    /** @var array<int, string> thread id → subject, filled by seedThread() */
    private array $subjectsById = [];

    protected function tearDown(): void
    {
        if (isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testResultsArriveNewestFirstWithoutBeingAsked(): void
    {
        $client = $this->signIn();
        $this->seedCorpus();

        $client->request('GET', '/mail/search?q=invoice');

        self::assertResponseIsSuccessful();
        self::assertSame(['Newest', 'Middle', 'Ancient'], $this->renderedSubjects($client));
    }

    public function testTheSwitchTogglesTheOrderInBothDirections(): void
    {
        $client = $this->signIn();
        $this->seedCorpus();

        $client->request('GET', '/mail/search?q=invoice&sort=relevance');

        self::assertResponseIsSuccessful();
        self::assertSame(
            'Ancient',
            $this->renderedSubjects($client)[0],
            'sort=relevance should put the densest match first',
        );

        $client->request('GET', '/mail/search?q=invoice&sort=recent');

        self::assertSame(['Newest', 'Middle', 'Ancient'], $this->renderedSubjects($client));
    }

    /**
     * The point of storing it. The second request is the one a person makes
     * from the search box in the topbar, which knows nothing about sorting and
     * sends no `sort` at all.
     */
    public function testTheChoiceSurvivesIntoTheNextSearch(): void
    {
        $client = $this->signIn();
        $this->seedCorpus();

        $client->request('GET', '/mail/search?q=invoice&sort=relevance');
        $client->request('GET', '/mail/search?q=invoice');

        self::assertResponseIsSuccessful();
        self::assertSame(
            'Ancient',
            $this->renderedSubjects($client)[0],
            'the remembered order was not applied to a request that did not ask',
        );

        self::assertSame(SearchSortOrder::Relevance, $this->storedOrder());
    }

    /** A hand-edited URL is not a choice: it keeps what the user actually set. */
    public function testAnUnrecognisedOrderChangesNothing(): void
    {
        $client = $this->signIn();
        $this->seedCorpus();

        $client->request('GET', '/mail/search?q=invoice&sort=sideways');

        self::assertResponseIsSuccessful();
        self::assertSame(['Newest', 'Middle', 'Ancient'], $this->renderedSubjects($client));

        self::assertSame(SearchSortOrder::Recent, $this->storedOrder());
    }

    /**
     * Where the control is, not merely that it exists — beside the pagination
     * in the list toolbar is the affordance people already know, and a menu
     * rendered anywhere else is a different feature.
     */
    public function testTheSwitchRendersBesideThePagination(): void
    {
        $client = $this->signIn();
        $this->seedCorpus();

        $crawler = $client->request('GET', '/mail/search?q=invoice');

        self::assertResponseIsSuccessful();

        $toolbar = $crawler->filter('[data-controller="mail--list-toolbar"]');

        self::assertCount(1, $toolbar);

        // A direct child of the toolbar, immediately before the pagination
        // block — "beside the pagination" is the whole affordance, and a menu
        // that rendered above the list or under the filter pills would be a
        // different feature that happened to sort.
        $menu = $toolbar->filter('[data-controller="mail--list-toolbar"] > [data-search-sort-menu]');

        self::assertCount(1, $menu, 'the switch is not a child of the list toolbar');

        $afterMenu = $menu->nextAll()->first();

        self::assertStringContainsString(
            'of',
            $afterMenu->text(),
            'the element after the switch is not the pagination readout',
        );
        self::assertSame(
            1,
            $afterMenu->filter('[title="Newer"], [title="Older"], [aria-disabled="true"]')->count() > 0 ? 1 : 0,
            'the pagination controls are not the switch\'s neighbour',
        );

        // Translated, not a raw key: the control says what order you are in,
        // and 'search.sort.recent' says nothing to anybody.
        self::assertStringContainsString('Most recent', $menu->filter('button')->first()->text());

        // Both options offered, and the one in force is marked.
        $options = $toolbar->filter('[data-search-sort]');

        self::assertSame(
            ['Most recent', 'Most relevant'],
            $options->each(static fn ($node): string => trim($node->text())),
        );

        self::assertSame(
            ['recent', 'relevance'],
            $options->each(static fn ($node): string => (string) $node->attr('data-search-sort')),
        );

        self::assertSame(
            'true',
            $options->eq(0)->attr('aria-current'),
            'the order in force is not marked in the menu',
        );

        // Switching drops the page rather than carrying it: page 4 of one order
        // is not page 4 of the other.
        $href = (string) $options->eq(1)->attr('href');

        self::assertStringContainsString('sort=relevance', $href);
        self::assertStringContainsString('q=invoice', $href);
        self::assertStringNotContainsString('page=', $href);
    }

    /** Paginating keeps the order — the links carry it, the setting backs it. */
    public function testPaginationLinksCarryTheOrder(): void
    {
        $client = $this->signIn();

        // Enough to force a second page at the page size the controller uses.
        for ($i = 1; $i <= 60; $i++) {
            $this->seedThread(subject: 'Bulk ' . $i, body: 'invoice', receivedAt: '2026-03-01');
        }

        $crawler = $client->request('GET', '/mail/search?q=invoice&sort=relevance');

        self::assertResponseIsSuccessful();

        $next = $crawler->filter('[title="Older"]')->first();

        self::assertSame(1, $next->count(), 'no next-page link on a two-page result');
        self::assertStringContainsString('sort=relevance', (string) $next->attr('href'));
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /**
     * The order actually written to the settings bag.
     *
     * Re-read rather than asserted on the instance the fixtures hold: the
     * request ran through the same EntityManager and detached it on the way,
     * and an assertion against a stale object would pass whatever was stored.
     */
    private function storedOrder(): SearchSortOrder
    {
        $this->em->clear();

        $user = $this->em->find(User::class, $this->user->id);

        self::assertNotNull($user, 'the test user vanished');

        return $user->searchSortOrder;
    }

    /**
     * Subjects of the rendered result rows, in the order they appear.
     *
     * Read off the row ids rather than the visible text: the row renders the
     * subject in several places (overlay label, headline, snippet) and a test
     * that scraped one of them would break on a layout change that has nothing
     * to do with ordering. The id is the row's identity.
     *
     * @return list<string>
     */
    private function renderedSubjects(KernelBrowser $client): array
    {
        $subjects = $this->subjectsById;

        return $client->getCrawler()
            ->filter('li[data-controller="mail--message-row"]')
            ->each(static fn ($node): string => $subjects[(int) $node->attr('data-mail--message-row-id-value')] ?? '?');
    }

    /**
     * Three matches whose date order and rank order disagree: the newest is the
     * weakest match, so a page that leads with "Ancient" is ranked and a page
     * that leads with "Newest" is dated. Nothing here can pass both ways.
     */
    private function seedCorpus(): void
    {
        $this->seedThread(subject: 'Middle',  body: 'invoice',                  receivedAt: '2015-06-01');
        $this->seedThread(subject: 'Ancient', body: 'invoice invoice invoice',  receivedAt: '2004-12-01');
        $this->seedThread(subject: 'Newest',  body: 'invoice',                  receivedAt: '2026-05-01');
    }

    private function seedThread(string $subject, string $body, string $receivedAt): void
    {
        // Filled as rows are seeded, and read back by renderedSubjects().
        $thread                    = new MessageThread();
        $thread->account           = $this->account;
        $thread->subject           = $subject;
        $thread->normalizedSubject = mb_strtolower($subject);
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
        $thread->lastMessageAt     = new \DateTimeImmutable($receivedAt);
        $thread->unreadCount       = 0;

        $message                 = new Message();
        $message->account        = $this->account;
        $message->thread         = $thread;
        $message->subject        = $subject;
        $message->fromAddress    = 'sender@example.test';
        $message->fromName       = 'Sender';
        $message->bodyText       = $body;
        $message->receivedAt     = new \DateTimeImmutable($receivedAt);
        $message->sentAt         = $message->receivedAt;
        $message->hasAttachments = false;
        $message->flags          = [];
        $message->messageId      = sprintf('<search-sort-%s@example.test>', uniqid('', true));

        $thread->addMessage($message);

        $this->em->persist($thread);
        $this->em->persist($message);
        $this->em->flush();

        $this->subjectsById[(int) $thread->id] = $subject;
    }

    private function signIn(): KernelBrowser
    {
        $client = static::createClient();

        // One kernel across the whole test: these cases make two requests
        // against fixtures staged in a transaction, and a reboot between them
        // would detach the EntityManager holding it open.
        $client->disableReboot();

        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        // Rolled back in tearDown, so this never leaves threads behind for the
        // tests that render somebody else's mailbox.
        $this->connection->beginTransaction();

        $this->user            = new User();
        $this->user->email     = 'search-sort-' . uniqid('', true) . '@example.test';
        $this->user->nameFirst = 'Search';
        $this->user->nameLast  = 'Sort';
        $this->user->roles     = ['ROLE_USER'];
        $this->user->password  = 'x';
        $this->em->persist($this->user);
        $this->em->flush();

        $client->loginUser($this->user);

        $this->account                 = new Account();
        $this->account->usr            = $this->user;
        $this->account->name           = 'Search sort fixture';
        $this->account->email          = 'search-sort@example.test';
        $this->account->username       = 'search-sort-' . uniqid('', true) . '@example.test';
        $this->account->imapHost       = 'localhost';
        $this->account->imapPort       = 993;
        $this->account->imapEncryption = 'ssl';
        $this->account->authType       = 'password';
        $this->account->isActive       = true;
        $this->em->persist($this->account);
        $this->em->flush();

        return $client;
    }
}
