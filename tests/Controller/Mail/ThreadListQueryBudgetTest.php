<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MessageCategory;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Label\Label;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Tests\Support\Mail\SeedsMarkerFixtures;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\DataCollector\DoctrineDataCollector;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A list page costs a fixed number of queries, whatever is on it.
 *
 * The suite was fully green while every row of every list fired two extra
 * queries of its own — one for `item.account`, one for `item.messages` — so a
 * test that renders a list and reads the HTML back cannot be what guards this.
 * Nothing in the markup changes when a lazy load creeps back in; only the
 * query count does, and only against a list long enough for fifty of them to
 * be distinguishable from four.
 *
 * Hence a budget, asserted at PER_PAGE rows. The numbers below are ceilings
 * rather than equalities: a page that legitimately grows one grouped count
 * should not fail, and the gap between "a handful" and "one per row" is two
 * orders of magnitude, so a loose ceiling still catches the only regression
 * that matters.
 */
final class ThreadListQueryBudgetTest extends WebTestCase
{
    use SeedsMarkerFixtures;

    /** MailController::PER_PAGE — a full page is the only size that shows the bug. */
    private const int PER_PAGE = 50;

    /**
     * The ceiling for one full list render.
     *
     * The six lists below currently measure 21 to 24: the list query and its
     * count, the three row preloads, the category tab counts, the sidebar's
     * grouped counters, the user read and a couple of small per-user reads.
     * Twenty-seven is that with room for a page that grows another grouped
     * query or two, and it is nowhere near the 120 the inbox cost before (52
     * account reads, 50 message-collection reads) or the 167 search cost.
     *
     * The gap is what makes the number safe to be approximate. A single
     * reintroduced per-row lazy load costs PER_PAGE queries — fifty — so it
     * cannot fit under this ceiling however much slack the ceiling has, while
     * ordinary feature work never lands anywhere near it. Raising this constant
     * by more than a few at a time means something is being paid per row again;
     * find it rather than widening this.
     *
     * ── Why this was thirty, and what moved ─────────────────────────────────
     * The 18-to-21 this docblock used to quote had drifted to 26-to-29 without
     * anything per-row coming back — page furniture accumulating one honest
     * query at a time, which is precisely what a loose ceiling is there to
     * tolerate. Five of those were then found to be the SAME question asked
     * twice or three times by services that could not see each other: three
     * `label … role = ?` single-row reads where one `role IN (…)` serves the
     * sidebar's whole system block (SidebarCounts::roleLabelId), two identical
     * visible-calendar reads plus their superset where one read serves all
     * three (App\Service\Calendar\UserCalendars), and an account read plus its
     * own superset, likewise (App\Service\Mail\UserAccounts).
     *
     * Every remaining statement on every one of the six lists is now distinct.
     * That is what the QUERY_BUDGET_VERBOSE=1 dump is for: a repeat count above
     * 1 on a shape is the thing to look at, and there are none left that are
     * not deliberate — the two `GROUP BY l0_.role` statements and the two
     * starred ones differ by `listed_at IS NULL`, which is new-mail markers
     * against plain unread, and they are two answers rather than one asked
     * twice.
     */
    private const int BUDGET = 27;

    /**
     * The ceiling for the same list NAVIGATED rather than visited.
     *
     * Its own number rather than a fraction of the one above, because the two
     * measure different work. A visit renders a page: the list, and around it a
     * sidebar whose counters are eight grouped queries, a topbar, a calendar
     * pane and a reading pane. A frame navigation renders the list and the
     * document head — the head is NOT optional, see App\Twig\ListFragmentGlobal
     * — and Turbo discards the rest of the body without reading it, so the
     * chrome is not made cheaper here, it is not done at all.
     *
     * Measured at PER_PAGE rows, against 21 to 24 for the same six visited:
     *
     *   archive  9    account  9    starred 10
     *   label   11    search  12    inbox   13
     *
     * Nine is the floor and it is almost all list: the user, the conversations
     * and their count, the three row preloads, and three the TOOLBAR spends on
     * the menus inside the frame (the visible labels, the accounts and their
     * aliases, for "move to" and "label as"). What separates the six from there
     * is honest per-view work that a navigation genuinely has to redo — the
     * inbox's category-tab counts and the unread number its <title> carries,
     * the label's own row, search's settings read.
     *
     * FIFTEEN, from the thirteen the busiest of them costs with room for a view
     * that grows one grouped query. Deliberately tighter than BUDGET: that one
     * is loose because page furniture accumulates honestly around a list, and
     * the whole point here is that there is no furniture left inside the frame
     * to accumulate. Something that pushes a role list over this is chrome
     * creeping back in, and finding out what is the exercise.
     */
    private const int FRAME_BUDGET = 15;

    private KernelBrowser $client;
    private Connection $connection;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        // Same reason as NewMailMarkerTest: a reboot between requests would
        // take the connection holding this test's transaction with it.
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

    /**
     * The inbox tab the regression was profiled on.
     */
    public function testAFullInboxPageStaysWithinItsQueryBudget(): void
    {
        $this->seedPage(MessageCategory::Promotions);

        $queries = $this->queriesFor('/mail/inbox?tab=promotions');

        self::assertLessThanOrEqual(
            self::BUDGET,
            $queries,
            sprintf(
                'the inbox spent %d queries on %d rows — a per-row lazy load is back',
                $queries,
                self::PER_PAGE,
            ),
        );

        $this->assertNavigatingCostsFarLess('/mail/inbox?tab=promotions', $queries);
    }

    /**
     * Every other list goes through the same row partial, so a fix that only
     * reached the inbox is not a fix. Archive stands in for findForRole(),
     * which serves six of them.
     */
    public function testAFullRoleListStaysWithinItsQueryBudget(): void
    {
        $archive = $this->seedLabel('Archive', LabelRole::Archive);
        $this->seedPage(MessageCategory::Primary, $archive);

        $queries = $this->queriesFor('/mail/archive');

        self::assertLessThanOrEqual(self::BUDGET, $queries, sprintf('archive spent %d queries', $queries));

        $this->assertNavigatingCostsFarLess('/mail/archive', $queries);
    }

    /** findForStarred(). */
    public function testAFullStarredListStaysWithinItsQueryBudget(): void
    {
        $this->seedPage(MessageCategory::Primary, null, true);

        $queries = $this->queriesFor('/mail/starred');

        self::assertLessThanOrEqual(self::BUDGET, $queries, sprintf('starred spent %d queries', $queries));

        $this->assertNavigatingCostsFarLess('/mail/starred', $queries);
    }

    /** findForLabel(). */
    public function testAFullLabelListStaysWithinItsQueryBudget(): void
    {
        $label = $this->seedLabel('Receipts');
        $this->seedPage(MessageCategory::Primary, $label);

        $queries = $this->queriesFor('/mail/label/' . $label->id);

        self::assertLessThanOrEqual(self::BUDGET, $queries, sprintf('the label view spent %d queries', $queries));

        $this->assertNavigatingCostsFarLess('/mail/label/' . $label->id, $queries);
    }

    /** findForAccountInbox(). */
    public function testAFullAccountListStaysWithinItsQueryBudget(): void
    {
        $this->seedPage(MessageCategory::Primary);

        $queries = $this->queriesFor('/mail/account/' . $this->account->id);

        self::assertLessThanOrEqual(self::BUDGET, $queries, sprintf('the account view spent %d queries', $queries));

        $this->assertNavigatingCostsFarLess('/mail/account/' . $this->account->id, $queries);
    }

    /** The search results list, which renders the same rows. */
    public function testAFullSearchResultListStaysWithinItsQueryBudget(): void
    {
        $this->seedPage(MessageCategory::Primary);

        $queries = $this->queriesFor('/mail/search?q=budget');

        self::assertLessThanOrEqual(self::BUDGET, $queries, sprintf('search spent %d queries', $queries));

        $this->assertNavigatingCostsFarLess('/mail/search?q=budget', $queries);
    }

    // ── the same lists, navigated rather than visited ─────────────────────

    /**
     * The same list, fetched as a frame navigation instead of as a page.
     *
     * This is the whole point of answering Turbo's `Turbo-Frame` header with
     * the document stripped to the list frame (App\Twig\ListFragmentGlobal):
     * clicking a folder in the sidebar cannot change the sidebar, and it used
     * to re-render it anyway — along with the topbar, the calendar pane and the
     * reading pane, which is where more than half of a list page's queries go.
     *
     * Called from each of the six tests above rather than written once against
     * one list, for the same reason those six exist rather than one: the six
     * views reach the same rows through six different queries, and a saving
     * that only reached the one it was measured on is not a saving. It is also
     * the only way the ceiling below can honestly be a ceiling — it is drawn
     * over the busiest of the six, not the cheapest.
     *
     * @param int $page  what the same URI cost as an ordinary visit, so the two
     *                   numbers in a failure message are from the same fixtures
     *                   on the same run.
     */
    private function assertNavigatingCostsFarLess(string $uri, int $page): void
    {
        $frame = $this->queriesFor($uri, ['HTTP_TURBO_FRAME' => 'inbox-list-frame']);

        self::assertLessThanOrEqual(
            self::FRAME_BUDGET,
            $frame,
            sprintf('%s spent %d queries navigated, where the visit spends %d', $uri, $frame, $page),
        );

        // Relative as well as absolute. The absolute number is only meaningful
        // beside the one it replaces, and a day when the page itself gets
        // cheaper is not a day this should quietly stop saving anything.
        self::assertLessThan(
            $page * 0.7,
            $frame,
            sprintf('%s: the navigation (%d) has stopped being much cheaper than the visit (%d)', $uri, $frame, $page),
        );
    }

    /**
     * The poll's own fragment, which has been the cheap answer all along and
     * must not be made expensive by the navigation path sharing its machinery.
     */
    public function testThePollFragmentStaysTheCheapestAnswerOfAll(): void
    {
        $archive = $this->seedLabel('Archive', LabelRole::Archive);
        $this->seedPage(MessageCategory::Primary, $archive);

        $queries = $this->queriesFor('/mail/archive', ['HTTP_X_LIST_FRAGMENT' => 'inbox-list-frame']);

        self::assertLessThanOrEqual(
            self::FRAME_BUDGET,
            $queries,
            sprintf('the poll fragment spent %d queries', $queries),
        );
    }

    /**
     * The preload has to hand the collection over in the association's own
     * order, and nothing else in the suite would notice if it did not.
     *
     * `{% set latest = item.messages|last %}` is only "the newest message"
     * while the collection is sorted; #[ORM\OrderBy] guarantees that for a
     * lazy load and a fetch join does NOT inherit it. Drop the addOrderBy in
     * preloadMessages() and every row in every list quietly starts previewing
     * whichever message Postgres happened to return last — a silent, plausible
     * wrong answer, which is the worst kind.
     */
    public function testTheBatchPreloadStillYieldsTheNewestMessageAsTheSnippet(): void
    {
        $this->seedPage(MessageCategory::Promotions);

        $this->client->request('GET', '/mail/inbox?tab=promotions');

        self::assertResponseIsSuccessful();

        $html = (string) $this->client->getResponse()->getContent();

        // Thread 04 has five messages, so it has four wrong answers available.
        self::assertStringContainsString(
            'newest body of budget thread 04',
            $html,
            'the snippet is not coming from the newest message',
        );
        self::assertStringNotContainsString(
            'older body of',
            $html,
            'an older message surfaced as a row snippet — the preload lost its order',
        );
    }

    // ── fixtures ──────────────────────────────────────────────────────────

    /**
     * A full page of conversations of varying length.
     *
     * The lengths vary because a lazy `item.messages` costs one query per
     * THREAD regardless of how many messages come back, while the batch that
     * replaces it costs rows — so a page of uniform one-message threads would
     * flatter both and tell us nothing about either.
     */
    private function seedPage(MessageCategory $category, ?Label $extraLabel = null, bool $starred = false): void
    {
        for ($i = 0; $i < self::PER_PAGE; ++$i) {
            $thread                    = new MessageThread();
            $thread->account           = $this->account;
            $thread->subject           = sprintf('budget thread %02d', $i);
            $thread->normalizedSubject = mb_strtolower($thread->subject);
            $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
            $thread->lastMessageAt     = new DateTimeImmutable(sprintf('-%d minutes', $i));
            $thread->category          = $category;
            $thread->unreadCount       = 0 === $i % 3 ? 1 : 0;
            $thread->starredAt         = true === $starred ? new DateTimeImmutable() : null;
            $thread->addLabel($this->inbox);

            if (null !== $extraLabel) {
                $thread->addLabel($extraLabel);
            }

            $this->em->persist($thread);

            // 1 to 6 messages, so the page holds both singletons and long
            // conversations.
            $length               = ($i % 6) + 1;
            $thread->messageCount = $length;

            // INSERTED NEWEST FIRST, deliberately. A fixture written in date
            // order would sit in the table in date order, Postgres would hand
            // it back that way with no ORDER BY at all, and the ordering test
            // below would pass against a preload that had lost its sort.
            for ($m = 0; $m < $length; ++$m) {
                $message                 = new Message();
                $message->account        = $this->account;
                $message->thread         = $thread;
                $message->subject        = $thread->subject;
                $message->fromAddress    = sprintf('sender%d@example.test', $m);
                $message->fromName       = sprintf('Sender %d', $m);
                $message->receivedAt     = new DateTimeImmutable(sprintf('-%d minutes', ($i * 10) + $m + 1));
                $message->sentAt         = $message->receivedAt;
                $message->seenAt         = $message->receivedAt;
                $message->flags          = [];
                $message->hasAttachments = false;
                // The snippet is taken from the NEWEST message, and the batch
                // preload is what now decides which one that is — see the
                // ordering test.
                $message->bodyText = sprintf(
                    '%s body of %s',
                    0 === $m ? 'newest' : 'older',
                    $thread->subject,
                );

                $thread->addMessage($message);
                $this->em->persist($message);
            }
        }

        $this->em->flush();
        $this->em->clear();
    }

    /**
     * Queries spent serving one request, as a difference rather than a total.
     *
     * The kernel is not rebooted between requests here (it cannot be — the
     * fixtures live in an open transaction on this connection), so Doctrine's
     * debug data holder never resets and its collector reports everything the
     * connection has done since the client was built, fixture INSERTs
     * included. Two identical requests and the gap between them is the cost of
     * one render, with the seeding already behind both of them.
     *
     * The first of the pair is also the warm-up the measurement needs for its
     * own reason: it is the render that retires the "New" badges, so the
     * second is the steady state every subsequent visit pays.
     */
    private function queriesFor(string $uri, array $server = []): int
    {
        // Warm-up, deliberately not the measured one. It carries the fixture
        // INSERTs the collector had not yet flushed, and it is the render that
        // retires the "New" badges — the steady state is the visit after that.
        $this->totalQueriesAfter($uri, $server);

        // Nothing may be left managed from the warm-up, or an association that
        // is still lazily mapped would look preloaded simply because the
        // previous request had already initialised it — the test would pass on
        // the strength of a warm identity map that no real request has.
        $this->em->clear();

        [$count, $sql] = $this->totalQueriesAfter($uri, $server);

        if (true === (bool) getenv('QUERY_BUDGET_VERBOSE')) {
            $map = $this->em->getUnitOfWork()->getIdentityMap();

            // Rows as well as queries. The batch preload was chosen over
            // denormalising onto MessageThread on the strength of this number
            // being UNCHANGED by it — the lazy loads hydrated every message of
            // every thread too, just fifty queries at a time.
            fwrite(\STDERR, sprintf(
                "\n%s => %d queries, %d message rows over %d threads\n",
                $uri,
                $count,
                count($map[Message::class] ?? []),
                count($map[MessageThread::class] ?? []),
            ));

            foreach ($sql as $statement => $times) {
                fwrite(\STDERR, sprintf("  %4dx %s\n", $times, (string) preg_replace('/SELECT .*? FROM /', 'SELECT … FROM ', $statement)));
            }
        }

        return $count;
    }

    /**
     * @param array<string,string> $server
     *
     * @return array{int, array<string,int>}
     */
    private function totalQueriesAfter(string $uri, array $server = []): array
    {
        $this->client->enableProfiler();
        $this->client->request('GET', $uri, server: $server);

        self::assertResponseIsSuccessful();

        $profile = $this->client->getProfile();

        self::assertNotFalse($profile, 'no profile — the query count cannot be asserted');

        $collector = $profile->getCollector('db');

        self::assertInstanceOf(DoctrineDataCollector::class, $collector);

        $seen = [];

        foreach ($collector->getQueries() as $queries) {
            foreach ($queries as $query) {
                $sql        = (string) preg_replace('/\s+/', ' ', (string) $query['sql']);
                $seen[$sql] = ($seen[$sql] ?? 0) + 1;
            }
        }

        arsort($seen);

        return [$collector->getQueryCount(), $seen];
    }
}
