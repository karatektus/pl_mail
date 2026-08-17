<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageCategory;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Version\Version;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * "New" is not "unread", and the difference is the whole feature.
 *
 * Unread asks whether a conversation has been OPENED. New asks whether it has
 * been SHOWN — whether its row has ever been put in front of the user in a
 * list. Scroll past a message in the inbox without clicking it and you have
 * stopped being surprised by it while still not having read it: not new, still
 * unread. Both facts are true at once, so neither may be derived from the
 * other, and MessageThread::$listedAt exists rather than seenAt being made to
 * answer two questions.
 *
 * Three things here are worth more than the rest.
 *
 * The migration backfill. A nullable column added to a live table is null on
 * every existing row, and null means new, so the first person to open plMail
 * after a bare ADD COLUMN would find their entire mail history badged. It is
 * the most visible way this feature can fail and the least visible in review.
 *
 * The order of render and mark. The rows must be drawn from the state that was
 * true when the request arrived, and only then may that state be retired —
 * otherwise the badge is computed after the thing that clears it and nobody
 * ever sees it.
 *
 * Pagination. Marking the query rather than the page would silently retire
 * every badge behind the first fifty rows.
 */
final class NewMailMarkerTest extends WebTestCase
{
    use SeedsMarkerFixtures;

    /** The migration under test — spelled once, and read back by two tests. */
    private const string MIGRATION = 'DoctrineMigrations\Version20260812161500';

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        // The kernel would otherwise be rebuilt between requests, and with it
        // the connection holding this test's transaction — every fixture below
        // would vanish before the second request could see it. Several tests
        // here turn on what the SECOND render says, so this is load-bearing
        // rather than an optimisation.
        $this->client->disableReboot();

        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount();
        $this->inbox   = $this->seedLabel('Inbox', LabelRole::Inbox);

        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    // ── the migration ─────────────────────────────────────────────────────

    /**
     * The non-negotiable one.
     *
     * Asserted against the statements the migration actually plans rather than
     * against the file's text, so a rewrite that keeps the effect keeps the
     * test — and one that quietly drops the UPDATE fails it however the SQL is
     * spelled.
     */
    public function testTheMigrationBackfillsExistingThreadsAsAlreadySeen(): void
    {
        $statements = $this->plannedMigrationSql();

        $added = array_values(array_filter(
            $statements,
            static fn (string $sql): bool => str_contains($sql, 'ADD listed_at'),
        ));

        self::assertCount(1, $added, 'the column has to be added');

        // A column default would keep firing after the deploy and every newly
        // ingested thread would be born already-seen — the feature switched off
        // by the thing meant to protect the rollout.
        self::assertStringNotContainsStringIgnoringCase(
            'DEFAULT NOW()',
            $added[0],
            'the backfill is a one-off UPDATE, never a column default',
        );

        $backfill = array_values(array_filter(
            $statements,
            static fn (string $sql): bool => str_starts_with(strtoupper(trim($sql)), 'UPDATE MESSAGE_THREAD')
                && str_contains(strtolower($sql), 'listed_at'),
        ));

        self::assertCount(
            1,
            $backfill,
            'without this every existing thread in every mailbox reads as new mail on deploy',
        );
        self::assertStringContainsStringIgnoringCase('NOW()', $backfill[0]);
    }

    /** The backfill statement, run for real, does stamp a null row. */
    public function testTheBackfillStatementActuallyStampsANullThread(): void
    {
        $thread = $this->thread('older than the feature');

        $this->connection->executeStatement(
            'UPDATE message_thread SET listed_at = NULL WHERE id = :id',
            ['id' => $thread->id],
        );

        foreach ($this->plannedMigrationSql() as $sql) {
            if (false === str_starts_with(strtoupper(trim($sql)), 'UPDATE MESSAGE_THREAD')) {
                continue;
            }

            $this->connection->executeStatement($sql);
        }

        self::assertFalse($this->isNew($thread), 'the backfill left an existing thread looking like new mail');
    }

    // ── render, then mark ─────────────────────────────────────────────────

    public function testAFreshlyIngestedThreadIsNewAndSaysSoOnTheFirstRender(): void
    {
        $thread = $this->thread('just arrived');

        self::assertTrue($this->isNew($thread), 'precondition: a new thread starts unlisted');

        $this->client->request('GET', '/mail/inbox');

        self::assertResponseIsSuccessful();
        self::assertSame('true', $this->rowAttribute($thread, 'data-new'));
        self::assertStringContainsString(
            'data-thread-new',
            (string) $this->client->getResponse()->getContent(),
            'the badge itself has to be in the markup, not just the row flag',
        );
    }

    /**
     * The order of operations, stated as an outcome: the same request that
     * showed the badge is the one that retires it.
     */
    public function testTheRenderThatShowedTheBadgeIsWhatRetiresIt(): void
    {
        $thread = $this->thread('just arrived');

        $this->client->request('GET', '/mail/inbox');

        self::assertSame('true', $this->rowAttribute($thread, 'data-new'), 'shown first…');
        self::assertFalse($this->isNew($thread), '…and marked after');
    }

    public function testASecondRenderKeepsItNotNew(): void
    {
        $thread = $this->thread('just arrived');

        $this->client->request('GET', '/mail/inbox');
        $this->client->request('GET', '/mail/inbox');

        self::assertSame('false', $this->rowAttribute($thread, 'data-new'));
        self::assertFalse($this->isNew($thread));
    }

    /**
     * Opening it was never required. This is the sentence the feature is built
     * around: listed but never opened is not new, and still unread.
     */
    public function testAThreadScrolledPastButNeverOpenedIsNotNewAndStillUnread(): void
    {
        $thread = $this->thread('unopened', unread: 1);

        $this->client->request('GET', '/mail/inbox');
        $this->client->request('GET', '/mail/inbox');

        self::assertSame('false', $this->rowAttribute($thread, 'data-new'));
        self::assertSame('true', $this->rowAttribute($thread, 'data-unread'));

        // And the unread marker is still drawn, so the row has not gone quiet
        // just because it stopped being news.
        self::assertNotNull(
            $this->row($thread),
            'the row is still listed',
        );
        self::assertSame(
            1,
            (int) $this->connection->fetchOne(
                'SELECT unread_count FROM message_thread WHERE id = :id',
                ['id' => $thread->id],
            ),
            'retiring the new marker must not touch the unread count',
        );
    }

    // ── pagination ────────────────────────────────────────────────────────

    /**
     * Marking the query instead of the page is a one-word mistake with a very
     * large blast radius: every badge past row fifty, gone unseen.
     */
    public function testRenderingPageOneDoesNotRetirePageTwosBadges(): void
    {
        // PER_PAGE + 1, so exactly one thread falls onto the second page. The
        // list is newest-first, so the OLDEST is the one that lands there.
        $threads = [];

        for ($i = 0; $i <= 50; ++$i) {
            $threads[] = $this->thread(
                sprintf('thread %02d', $i),
                // Inside the window, and ascending, so thread 0 is the oldest
                // and lands on page two.
                lastMessageAt: sprintf('now -%d minutes', 200 - $i),
                flush: false,
            );
        }

        $this->em->flush();

        $onPageTwo = $threads[0];
        $onPageOne = $threads[50];

        $this->client->request('GET', '/mail/inbox');

        self::assertFalse($this->isNew($onPageOne), 'page one was shown, so page one is retired');
        self::assertTrue(
            $this->isNew($onPageTwo),
            'a thread the user has not been shown must keep its badge',
        );

        $this->client->request('GET', '/mail/inbox?page=2');

        self::assertFalse($this->isNew($onPageTwo), 'and it retires when its own page is shown');
    }

    // ── the category dot ──────────────────────────────────────────────────

    public function testACategoryDotAppearsOnlyWhileThatCategoryHoldsNewMail(): void
    {
        // Two categories, so the tab strip renders at all — a lone Primary tab
        // is deliberately suppressed.
        $this->thread('a promo', category: MessageCategory::Promotions);
        $this->thread('a normal one');

        $this->client->request('GET', '/mail/inbox');

        self::assertTrue(
            $this->dotIsVisible('new:category:promotions'),
            'Promotions holds an unshown thread, so its tab is dotted',
        );

        // Looking at Promotions is what clears it — the rows get shown there.
        $this->client->request('GET', '/mail/inbox?tab=promotions');
        $this->client->request('GET', '/mail/inbox?tab=promotions');

        self::assertFalse($this->dotIsVisible('new:category:promotions'));
    }

    /**
     * The tab's marker says how many — "2 new", the Gmail hint — where the
     * sidebar's stays a wordless dot. The word matters as much as the number:
     * it is what tells this figure apart from the unread count sitting on the
     * same tab, so the assertion is on the whole rendered phrase rather than
     * on a digit being present somewhere.
     */
    public function testTheCategoryTabPillSaysHowMany(): void
    {
        $this->thread('a promo', category: MessageCategory::Promotions);
        $this->thread('another promo', category: MessageCategory::Promotions);
        $this->thread('a normal one');

        $this->client->request('GET', '/mail/inbox');

        $pill = $this->client->getCrawler()
            ->filter('[data-new-dot][data-count-key="new:category:promotions"]');

        self::assertGreaterThan(0, $pill->count());
        self::assertSame('2 new', trim($pill->first()->text()));
        self::assertSame('2 new in Promotions', $pill->first()->attr('aria-label'));

        // The templates the live patcher fills numbers into, placeholder still
        // in place — a template that arrived pre-filled would freeze the pill
        // at the page-load count. See patchNewDots in sidebar_controller.js.
        self::assertSame('%count% new', $pill->first()->attr('data-count-template'));
        self::assertSame(
            '%count% new in Promotions',
            $pill->first()->attr('data-label-template'),
        );
    }

    /**
     * The Gmail hint's second half: WHO the new mail is from, newest arrival
     * first. Only categories the user is not currently looking at keep their
     * hints — the promo threads are unlisted because the render below shows
     * the Primary tab, which is exactly the situation the hint exists for.
     */
    public function testTheTabSenderHintNamesWhoTheNewMailIsFrom(): void
    {
        $this->thread('older promo', category: MessageCategory::Promotions, lastMessageAt: 'now -2 hours', fromName: 'Old Shop');
        $this->thread('newer promo', category: MessageCategory::Promotions, lastMessageAt: 'now -1 hour', fromName: 'New Shop');
        $this->thread('a normal one');

        $this->client->request('GET', '/mail/inbox');

        $hint = $this->client->getCrawler()
            ->filter('[data-tab-senders][data-count-key="senders:category:promotions"]');

        self::assertGreaterThan(0, $hint->count());
        self::assertSame('New Shop, Old Shop', trim($hint->first()->text()));
    }

    /**
     * The sender hints are a live family of their own — the payload's one
     * STRING — and the payload the sidebar controller patches from has to
     * emit every key the strip renders, or the first sync freezes whichever
     * hint it cannot find. The same invariant the dots established, extended
     * to the mark the tabs added. (The tabs deliberately carry no unread
     * count, so there is no third family to hold this for.)
     */
    public function testEveryTabMarkIsPatchableFromTheCountsPayload(): void
    {
        $this->thread('a promo', category: MessageCategory::Promotions, unread: 2, fromName: 'Acme');
        $this->thread('a normal one');

        $counts = $this->countsPayload();

        self::assertSame(1, $counts['new:category:promotions']);
        self::assertSame('Acme', $counts['senders:category:promotions']);

        $this->client->request('GET', '/mail/inbox');

        $keys = $this->client->getCrawler()->filter('[data-tab-senders]')->each(
            static fn (Crawler $mark): string => (string) $mark->attr('data-count-key'),
        );

        self::assertNotEmpty($keys, 'the tab hints are always rendered — an omitted one cannot be patched');

        foreach ($keys as $key) {
            self::assertArrayHasKey($key, $counts, sprintf('the endpoint emits no "%s"', $key));
        }
    }

    /**
     * The invariant BadgeSemanticsTest holds for the unread badges, extended to
     * the new-mail dots: the endpoint the sidebar patches from has to say what
     * the server-rendered markup said, or the first sync moves a marker the
     * user never did anything to deserve.
     */
    public function testTheCountsEndpointAgreesWithTheRenderedDots(): void
    {
        $this->thread('a promo', category: MessageCategory::Promotions);
        $this->thread('a normal one');

        // The endpoint FIRST, and that ordering is the feature rather than a
        // test convenience: rendering the inbox retires the badges it showed,
        // so counts read afterwards would honestly describe a different moment.
        // Asked in this order, both requests see the same state — which is what
        // the sidebar controller sees too, since it patches from a payload
        // fetched against whatever the page last rendered.
        $counts = $this->countsPayload();

        $this->client->request('GET', '/mail/inbox');

        $rendered = [];

        foreach ($this->client->getCrawler()->filter('[data-new-dot]')->each(
            static fn (Crawler $dot): array => [
                (string) $dot->attr('data-count-key'),
                false === str_contains((string) $dot->attr('class'), 'hidden'),
            ],
        ) as [$key, $visible]) {
            $rendered[$key] = $visible;
        }

        self::assertNotEmpty($rendered, 'the dots are always rendered and hidden at zero');

        foreach ($rendered as $key => $visible) {
            self::assertArrayHasKey($key, $counts, sprintf('the endpoint emits no "%s"', $key));
            self::assertSame(
                $visible,
                $counts[$key] > 0,
                sprintf('"%s" was rendered %s but the endpoint says %d', $key, $visible ? 'visible' : 'hidden', $counts[$key]),
            );
        }
    }

    public function testTheCountsEndpointStillAnswersTheUnreadKeysItAlwaysDid(): void
    {
        $counts = $this->countsPayload();

        // The dots ride on the same payload; they must not have displaced
        // anything the sidebar controller was already patching.
        self::assertArrayHasKey('starred', $counts);
        self::assertArrayHasKey('role:inbox', $counts);
        self::assertArrayHasKey('new:starred', $counts);
        self::assertArrayHasKey('new:role:inbox', $counts);
    }

    // ── the list-fragment refresh ─────────────────────────────────────────

    /**
     * The refresh renders the rows straight into the visible list, so it is a
     * display like any other: the badge has to be in the fragment, and marking
     * it is then correct rather than a badge lost in the background.
     *
     * What keeps that honest is on the client — the refresh is deferred while
     * document.hidden and held during a write; see mail_pane_controller.
     */
    public function testAListFragmentRefreshShowsTheBadgeAndThenRetiresIt(): void
    {
        $thread = $this->thread('arrived while you watched');

        $this->client->request(
            'GET',
            '/mail/inbox',
            server: ['HTTP_X_LIST_FRAGMENT' => 'inbox-list-frame'],
        );

        self::assertResponseIsSuccessful();
        self::assertSame('true', $this->rowAttribute($thread, 'data-new'), 'the fragment carries the badge');
        self::assertFalse($this->isNew($thread), 'and having carried it, retires it');

        $this->client->request(
            'GET',
            '/mail/inbox',
            server: ['HTTP_X_LIST_FRAGMENT' => 'inbox-list-frame'],
        );

        self::assertSame('false', $this->rowAttribute($thread, 'data-new'));
    }

    // ── speculative fetches ───────────────────────────────────────────────

    /**
     * Turbo 8 fetches a link when the pointer merely rests on it, and every
     * sidebar row is prefetched — only the thread rows and account folder rows
     * opt out. So without this, sweeping the mouse across the nav on the way to
     * somewhere else would retire the badges in Promotions, in Starred and in
     * every label it passed over, on a page that was rendered into a buffer and
     * thrown away.
     */
    public function testAPrefetchRendersTheBadgeWithoutRetiringIt(): void
    {
        $thread = $this->thread('nobody looked at this');

        $this->client->request('GET', '/mail/inbox', server: ['HTTP_X_SEC_PURPOSE' => 'prefetch']);

        self::assertResponseIsSuccessful();
        self::assertSame('true', $this->rowAttribute($thread, 'data-new'));
        self::assertTrue($this->isNew($thread), 'a page nobody saw must not retire anything');

        // And the real visit, when it comes, still gets to show it.
        $this->client->request('GET', '/mail/inbox');

        self::assertSame('true', $this->rowAttribute($thread, 'data-new'));
        self::assertFalse($this->isNew($thread));
    }

    /** The browser's own spelling, for prerender and speculation rules. */
    public function testTheStandardSecPurposeHeaderCountsToo(): void
    {
        $thread = $this->thread('speculatively fetched');

        $this->client->request('GET', '/mail/inbox', server: ['HTTP_SEC_PURPOSE' => 'prefetch;anonymous-client-ip']);

        self::assertTrue($this->isNew($thread));
    }

    // ── other list views ──────────────────────────────────────────────────

    /**
     * A row that has been shown has been shown, whichever list did the showing.
     * Otherwise a badge already looked at in one view lies in wait in another.
     */
    public function testBeingShownInALabelViewRetiresTheBadgeEverywhere(): void
    {
        $receipts = $this->seedLabel('Receipts');
        $thread   = $this->thread('a receipt');
        $thread->addLabel($receipts);
        $this->em->flush();

        $this->client->request('GET', '/mail/label/' . $receipts->id);

        self::assertFalse($this->isNew($thread));

        $this->client->request('GET', '/mail/inbox');

        self::assertSame('false', $this->rowAttribute($thread, 'data-new'));
    }


    // ── the window: "new" times out ──────────────────────────────────────

    /**
     * The user's second complaint, and the more interesting one: "mail thats a
     * day old isnt new. you dont have to see EVERY mail to get rid of new
     * tags."
     *
     * That is a statement about what the marker is FOR. As first built, the
     * badge could only be cleared by having its row rendered, which makes it a
     * debt the user works off one page at a time — a backlog of unvisited
     * labels stays badged forever. A "new" marker is supposed to say "this
     * arrived while you were away", and after a day that is no longer an
     * interesting thing to say about anything.
     *
     * Both edges, because a window asserted on one side is a window that can
     * silently become "always" or "never".
     */
    public function testMailInsideTheWindowIsStillNew(): void
    {
        $thread = $this->thread('23 hours old', lastMessageAt: 'now -23 hours');

        $this->client->request('GET', '/mail/inbox');

        self::assertSame(
            'true',
            $this->rowAttribute($thread, 'data-new'),
            'inside the window, an unshown thread is still news',
        );
    }

    public function testMailPastTheWindowIsNoLongerNew(): void
    {
        $thread = $this->thread('25 hours old', lastMessageAt: 'now -25 hours');

        $this->client->request('GET', '/mail/inbox');

        self::assertSame(
            'false',
            $this->rowAttribute($thread, 'data-new'),
            'a day-old conversation is not new, however few rows have been looked at',
        );
    }

    /**
     * The window is an age test, NOT a second way of stamping listedAt.
     *
     * An aged-out thread keeps listedAt null on purpose. The column records
     * WHEN THE ROW WAS PUT IN FRONT OF THE USER, and stamping it for mail
     * nobody ever saw would write a false statement that outlives the marker:
     * the rethread backfill COALESCEs listedAt forward across a rebuild, and
     * any later feature asking "was this ever displayed" would inherit the lie.
     * Failing the window test is cheap by comparison — one range predicate on
     * last_message_at, which is already the list's sort column.
     */
    public function testAgingOutDoesNotStampTheThreadAsHavingBeenShown(): void
    {
        $thread = $this->thread('long past it', lastMessageAt: 'now -3 days');

        $this->client->request('GET', '/mail/inbox');

        self::assertSame('false', $this->rowAttribute($thread, 'data-new'));
        self::assertTrue(
            $this->isNew($thread),
            'listedAt must stay null — nobody was shown this row',
        );
    }

    /** The dots time out with the badges, or they promise mail the list denies. */
    public function testAnAgedOutThreadStopsDottingItsPlace(): void
    {
        $this->thread('yesterdays promo', category: MessageCategory::Promotions, lastMessageAt: 'now -30 hours');
        $this->thread('a normal one');

        $this->client->request('GET', '/mail/inbox');

        self::assertFalse(
            $this->dotIsVisible('new:category:' . MessageCategory::Promotions->value),
            'Promotions holds nothing recent, so it must not claim to',
        );

        $counts = $this->countsPayload();

        self::assertSame(0, $counts['new:category:' . MessageCategory::Promotions->value]);
    }

    // ── search ───────────────────────────────────────────────────────────

    /**
     * "search can remove new tags."
     *
     * A row whose subject and sender the user has just read in a result list
     * has been shown, whatever route put it there. Leaving it badged means
     * finding your own search results announced back to you as news.
     */
    public function testSearchResultsRetireTheBadge(): void
    {
        $thread = $this->thread('quarterly invoice');

        $this->client->request('GET', '/mail/search?q=quarterly');

        self::assertResponseIsSuccessful();
        self::assertSame(
            'true',
            $this->rowAttribute($thread, 'data-new'),
            'the result list still shows the badge in the frame that retires it',
        );
        self::assertFalse($this->isNew($thread), 'and search counts as having been shown');
    }

    /** Search takes the same path, so it inherits the same prefetch guard. */
    public function testAPrefetchedSearchRetiresNothing(): void
    {
        $thread = $this->thread('quarterly invoice');

        $this->client->request(
            'GET',
            '/mail/search?q=quarterly',
            server: ['HTTP_X_SEC_PURPOSE' => 'prefetch'],
        );

        self::assertResponseIsSuccessful();
        self::assertTrue($this->isNew($thread));
    }

    // ── the client's confirmation: the reload bug ────────────────────────

    /**
     * The bug the user actually hit: "the tag survived a reload and tab
     * navigation."
     *
     * Turbo 8 prefetches a link on hover and then SERVES THAT RESPONSE for the
     * click, so every list reached from the sidebar or the tab strip arrives
     * carrying X-Sec-Purpose: prefetch and there is no second request. The
     * server was right to refuse to mark a speculative fetch and wrong to
     * assume another request would follow; nothing ever retired the badge.
     *
     * The fix is that the DOM reports the rows it is actually showing. This is
     * the controller half of that — see new-mail-marker.spec.ts for the half
     * that proves a real browser does it.
     */
    public function testRowsReportedByTheClientAreRetired(): void
    {
        $thread = $this->thread('arrived by prefetch');

        // Exactly what a hovered sidebar link does: the page renders, and
        // nothing is marked.
        $this->client->request('GET', '/mail/inbox', server: ['HTTP_X_SEC_PURPOSE' => 'prefetch']);

        self::assertTrue($this->isNew($thread));

        $this->client->request(
            'POST',
            '/mail/threads/listed',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['ids' => [$thread->id]], JSON_THROW_ON_ERROR),
        );

        self::assertResponseIsSuccessful();
        self::assertFalse($this->isNew($thread), 'a row the browser says it showed is a row that was shown');
    }

    /**
     * The endpoint takes ids straight off the wire, so ownership is a WHERE
     * clause rather than an assumption. Without it a guessed id retires
     * somebody else's badge.
     */
    public function testTheClientCannotRetireAnotherUsersBadges(): void
    {
        $mine = $this->thread('mine');

        $stranger = $this->seedUser();

        $strangersAccount                 = new Account();
        $strangersAccount->usr            = $stranger;
        $strangersAccount->name           = 'Someone else';
        $strangersAccount->email          = uniqid('other-', true) . '@example.test';
        $strangersAccount->username       = uniqid('other-', true);
        $strangersAccount->imapHost       = 'imap.example.test';
        $strangersAccount->imapPort       = 993;
        $strangersAccount->imapEncryption = 'ssl';
        $strangersAccount->authType       = 'password';
        $strangersAccount->isActive       = true;
        $this->em->persist($strangersAccount);

        $strangersThread                    = new MessageThread();
        $strangersThread->account           = $strangersAccount;
        $strangersThread->subject           = 'not yours';
        $strangersThread->normalizedSubject = 'not yours';
        $strangersThread->threadingMethod   = ThreadingMethod::SubjectFallback;
        $strangersThread->lastMessageAt     = new DateTimeImmutable();
        $strangersThread->category          = MessageCategory::Primary;
        $strangersThread->messageCount      = 1;
        $strangersThread->unreadCount       = 0;
        $this->em->persist($strangersThread);
        $this->em->flush();

        $this->client->request(
            'POST',
            '/mail/threads/listed',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(
                ['ids' => [$mine->id, $strangersThread->id]],
                JSON_THROW_ON_ERROR,
            ),
        );

        self::assertResponseIsSuccessful();
        self::assertFalse($this->isNew($mine));
        self::assertTrue(
            $this->isNew($strangersThread),
            'another account\'s badge is not ours to retire',
        );
    }

    /** Garbage in the body is not a 500. */
    public function testTheEndpointShrugsOffARubbishBody(): void
    {
        $this->client->request(
            'POST',
            '/mail/threads/listed',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: 'not json at all',
        );

        self::assertResponseIsSuccessful();
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /**
     * The statements the migration would run.
     *
     * @return list<string>
     */
    private function plannedMigrationSql(): array
    {
        // Asked of Doctrine's own registry rather than constructed here, and
        // that is worth more than it costs: this only answers if the migration
        // is actually REGISTERED — in the configured directory, with a class
        // name matching its file. A migration that exists but is not picked up
        // ships an unmigrated column, and this fails rather than passing
        // against a hand-built object the application would never load.
        $migration = self::getContainer()
            ->get('doctrine.migrations.dependency_factory')
            ->getMigrationRepository()
            ->getMigration(new Version(self::MIGRATION))
            ->getMigration();

        $migration->up(new Schema());

        return array_map(
            static fn (object $query): string => $query->getStatement(),
            $migration->getSql(),
        );
    }

    private function isNew(MessageThread $thread): bool
    {
        return null === $this->connection->fetchOne(
            'SELECT listed_at FROM message_thread WHERE id = :id',
            ['id' => $thread->id],
        );
    }

    private function row(MessageThread $thread): ?Crawler
    {
        $rows = $this->client->getCrawler()->filter('#thread_' . $thread->id);

        return 0 === $rows->count() ? null : $rows;
    }

    private function rowAttribute(MessageThread $thread, string $attribute): string
    {
        $row = $this->row($thread);

        self::assertNotNull($row, sprintf('thread %d is not in the rendered list', (int) $thread->id));

        return (string) $row->attr($attribute);
    }

    private function dotIsVisible(string $key): bool
    {
        $dots = $this->client->getCrawler()->filter(sprintf('[data-new-dot][data-count-key="%s"]', $key));

        self::assertGreaterThan(
            0,
            $dots->count(),
            sprintf('"%s" is never rendered, so it can never be patched either', $key),
        );

        return false === str_contains((string) $dots->first()->attr('class'), 'hidden');
    }

    /** @return array<string,int|string> ints, except the "senders:" family */
    private function countsPayload(): array
    {
        $this->client->request('GET', '/mail/sidebar/counts');

        self::assertResponseIsSuccessful();

        /** @var array<string,int> */
        return json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }
}
