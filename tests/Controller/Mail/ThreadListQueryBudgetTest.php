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
     * The six lists below currently measure 18 to 21: the list query and its
     * count, the three row preloads, the category tab counts, the sidebar's
     * grouped counters, the user read and a couple of small per-user reads.
     * Thirty is that with room for a page that grows another grouped query or
     * two, and it is nowhere near the 120 the inbox cost before (52 account
     * reads, 50 message-collection reads) or the 167 search cost.
     *
     * The gap is what makes the number safe to be approximate. A single
     * reintroduced per-row lazy load costs PER_PAGE queries — fifty — so it
     * cannot fit under this ceiling however much slack the ceiling has, while
     * ordinary feature work never lands anywhere near it. Raising this constant
     * by more than a few at a time means something is being paid per row again;
     * find it rather than widening this.
     */
    private const int BUDGET = 30;

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
    }

    /** findForStarred(). */
    public function testAFullStarredListStaysWithinItsQueryBudget(): void
    {
        $this->seedPage(MessageCategory::Primary, null, true);

        $queries = $this->queriesFor('/mail/starred');

        self::assertLessThanOrEqual(self::BUDGET, $queries, sprintf('starred spent %d queries', $queries));
    }

    /** findForLabel(). */
    public function testAFullLabelListStaysWithinItsQueryBudget(): void
    {
        $label = $this->seedLabel('Receipts');
        $this->seedPage(MessageCategory::Primary, $label);

        $queries = $this->queriesFor('/mail/label/' . $label->id);

        self::assertLessThanOrEqual(self::BUDGET, $queries, sprintf('the label view spent %d queries', $queries));
    }

    /** findForAccount(). */
    public function testAFullAccountListStaysWithinItsQueryBudget(): void
    {
        $this->seedPage(MessageCategory::Primary);

        $queries = $this->queriesFor('/mail/account/' . $this->account->id);

        self::assertLessThanOrEqual(self::BUDGET, $queries, sprintf('the account view spent %d queries', $queries));
    }

    /** The search results list, which renders the same rows. */
    public function testAFullSearchResultListStaysWithinItsQueryBudget(): void
    {
        $this->seedPage(MessageCategory::Primary);

        $queries = $this->queriesFor('/mail/search?q=budget');

        self::assertLessThanOrEqual(self::BUDGET, $queries, sprintf('search spent %d queries', $queries));
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
    private function queriesFor(string $uri): int
    {
        // Warm-up, deliberately not the measured one. It carries the fixture
        // INSERTs the collector had not yet flushed, and it is the render that
        // retires the "New" badges — the steady state is the visit after that.
        $this->totalQueriesAfter($uri);

        // Nothing may be left managed from the warm-up, or an association that
        // is still lazily mapped would look preloaded simply because the
        // previous request had already initialised it — the test would pass on
        // the strength of a warm identity map that no real request has.
        $this->em->clear();

        [$count, $sql] = $this->totalQueriesAfter($uri);

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

    /** @return array{int, array<string,int>} */
    private function totalQueriesAfter(string $uri): array
    {
        $this->client->enableProfiler();
        $this->client->request('GET', $uri);

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
