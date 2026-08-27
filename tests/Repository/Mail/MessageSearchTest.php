<?php

declare(strict_types=1);

namespace App\Tests\Repository\Mail;

use App\Domain\DTO\Ai\SemanticSearch;
use App\Domain\DTO\ParsedSearchQuery;
use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\SearchSortOrder;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Repository\Mail\MessageThreadRepository;
use App\Service\Ai\EmbeddingStore;
use App\Service\Search\FreeTextCompiler;
use App\Service\Search\SearchQueryParser;
use Doctrine\Persistence\ManagerRegistry;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Search, from the string a person types to the rows that come back.
 *
 * Driven through the parser rather than by handing the repository a filter
 * object, because the two halves are only useful together and the bugs live in
 * the seam: an operator the parser understands and the SQL never applies is
 * indistinguishable, from the outside, from one it applies to the wrong column.
 * Both failures widen the result set, and a search that returns too much is one
 * nobody notices is broken.
 *
 * Against Postgres, not a double: every operator here is a range, a JSON
 * containment or a full-text match, which is exactly what a fake would have to
 * reimplement to be wrong in a different way.
 */
final class MessageSearchTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private MessageThreadRepository $repository;
    private SearchQueryParser $parser;
    private User $user;
    private Account $account;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->repository = $container->get(MessageThreadRepository::class);
        $this->parser     = $container->get(SearchQueryParser::class);

        $this->connection->beginTransaction();

        $this->user    = $this->seedUser();
        $this->account = $this->seedAccount($this->user);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testFromMatchesTheAddressAndTheName(): void
    {
        $this->seedMessage(subject: 'Invoice', fromAddress: 'billing@acme.test', fromName: 'Acme Billing');
        $this->seedMessage(subject: 'Lunch', fromAddress: 'kim@example.test', fromName: 'Kim');

        self::assertSame(['Invoice'], $this->search('from:billing@'));
        self::assertSame(['Invoice'], $this->search('from:acme billing'));
        // Case is the sender's business, not the searcher's.
        self::assertSame(['Invoice'], $this->search('from:BILLING@ACME.TEST'));
    }

    /**
     * to: and cc: read a JSON array of {name, address} objects. cc: existed on
     * the SQL side for a long time with no way to reach it — the parser had no
     * case for it — so this is as much about the seam as the column.
     */
    public function testToAndCcReadTheirOwnRecipientLists(): void
    {
        $this->seedMessage(
            subject: 'Direct',
            to: [['name' => 'Kim', 'address' => 'kim@example.test']],
        );
        $this->seedMessage(
            subject: 'Copied',
            to: [['name' => 'Sam', 'address' => 'sam@example.test']],
            cc: [['name' => 'Kim', 'address' => 'kim@example.test']],
        );

        self::assertSame(['Direct'], $this->search('to:kim@example.test'));
        self::assertSame(['Copied'], $this->search('cc:kim@example.test'));
    }

    public function testSubjectMatchesPartOfIt(): void
    {
        $this->seedMessage(subject: 'Quarterly invoice 2026-0841');
        $this->seedMessage(subject: 'Lunch on Thursday');

        self::assertSame(['Quarterly invoice 2026-0841'], $this->search('subject:invoice'));
    }

    /**
     * Free text goes through the generated tsvector, which is the whole reason
     * it is Postgres doing the matching: "meetings" finds "meeting" because the
     * column is stemmed, and no LIKE would.
     */
    public function testFreeTextIsStemmed(): void
    {
        $this->seedMessage(subject: 'Notes', body: 'The meeting is postponed until Tuesday.');
        $this->seedMessage(subject: 'Other', body: 'Nothing relevant in here.');

        self::assertSame(['Notes'], $this->search('meetings'));
    }

    /**
     * The table from the bug report, verbatim.
     *
     * One mail, five ways of reaching for it, four of which used to come back
     * empty. Kept as one test over one fixture because that is the shape of the
     * complaint: the SAME mail, visible on screen, unfindable by every spelling
     * of what is visibly in it except the one nobody would type.
     *
     * The two failures have different causes and different fixes, which is why
     * both a prefix pass and a substring pass exist:
     *
     *   "Testmai"   a prefix of a lexeme. `:*` reaches it.
     *   "wirhub"    NOT a prefix of anything — the tokenizer emits
     *               "help.wirhub.de" as one host lexeme, so it is a substring
     *               of a lexeme, and only ILIKE reaches it.
     */
    #[DataProvider('reportedSearches')]
    public function testTheReportedSearchesAllFindTheMail(string $query): void
    {
        $this->seedMessage(
            subject: 'Testmail Betreff',
            body: 'Bitte besuche help.wirhub.de fuer mehr Infos',
        );
        $this->seedMessage(subject: 'Unrelated', body: 'Nothing in particular.');

        self::assertSame(['Testmail Betreff'], $this->search($query));
    }

    /** @return iterable<string, array{string}> */
    public static function reportedSearches(): iterable
    {
        yield 'whole word, which already worked' => ['Testmail'];
        yield 'prefix of a subject word'         => ['Testmai'];
        yield 'inside a host token'              => ['wirhub'];
        yield 'a suffix of a host token'         => ['wirhub.de'];
        yield 'the host token entire'            => ['help.wirhub.de'];
    }

    /**
     * Widening free text must not widen a query that was narrowed on purpose.
     *
     * Negation is the one that matters: OR-ing a prefix match onto `-invoice`
     * hands back precisely the mail the user asked not to see, which is worse
     * than the bug being fixed here. So websearch syntax turns the extra passes
     * off rather than fighting them.
     */
    public function testDeliberateWebsearchSyntaxIsNotWidened(): void
    {
        $this->seedMessage(subject: 'Invoice reminder', body: 'Payment for the invoice.');
        $this->seedMessage(subject: 'Payment plan', body: 'A plan for payment.');

        self::assertSame(['Payment plan'], $this->search('payment -invoice'));
        self::assertSame(['Payment plan'], $this->search('"payment plan"'));
        self::assertCount(2, $this->search('invoice OR plan'));
    }

    /**
     * tsquery syntax typed into the box is text, not syntax. Before the prefix
     * pass there was nothing to escape — websearch_to_tsquery is total over its
     * input — so this is new surface and worth pinning: any of these reaching
     * to_tsquery unescaped is a 500, and `!` reaching it is a search that
     * returns the complement of what was asked for.
     */
    #[DataProvider('hostileQueries')]
    public function testTsqueryPunctuationIsTextNotSyntax(string $query, int $expected): void
    {
        $this->seedMessage(subject: 'Invoice reminder', body: 'Payment for the invoice.');

        self::assertCount($expected, $this->search($query));
    }

    /**
     * The counts are the point, not just the absence of an exception: the
     * punctuation is stripped and the words around it still do their work, and
     * a query made of nothing BUT punctuation finds nothing rather than
     * everything — which is what `!` would do if it reached to_tsquery as an
     * operator.
     *
     * @return iterable<string, array{string, int}>
     */
    public static function hostileQueries(): iterable
    {
        yield 'bare colon star'    => ['invoice:*', 1];
        yield 'unbalanced quote'   => ["invoice'", 1];
        yield 'boolean operators'  => ['invoice & payment', 1];
        yield 'negation operator'  => ['!invoice', 1];
        yield 'unbalanced paren'   => ['(invoice', 1];
        yield 'nothing but syntax' => ['&|!():*', 0];
    }

    public function testLabelMatchesAUserLabelByName(): void
    {
        $receipts = $this->seedLabel('Receipts');

        $this->seedMessage(subject: 'Filed', labels: [$receipts]);
        $this->seedMessage(subject: 'Unfiled');

        self::assertSame(['Filed'], $this->search('label:receipts'));
    }

    /**
     * in: names a mailbox and the SQL matches a role, which only works because
     * the parser resolves the alias first. `in:junk` finding nothing would look
     * like an empty spam folder rather than a filter that never applied.
     */
    public function testInMatchesTheRoleBehindTheMailboxName(): void
    {
        $spam = $this->seedLabel('Spam', LabelRole::Spam);

        $this->seedMessage(subject: 'Dubious', labels: [$spam]);
        $this->seedMessage(subject: 'Ordinary');

        self::assertSame(['Dubious'], $this->search('in:junk'));
        self::assertSame(['Dubious'], $this->search('in:spam'));
    }

    /**
     * The rule every list view applies since v0.0.25, arriving at search last
     * because search builds its own SQL: a deleted conversation is not part of
     * the mailbox somebody is looking through until they say `in:trash`.
     *
     * The trashed thread here deliberately carries a second label, because
     * that is the case a naive `lbl.role <> 'trash'` would get wrong — the
     * label join exists once per label, so the thread would keep matching
     * through its "Receipts" row. `label:receipts` finding nothing is the
     * assertion that actually pins the NOT EXISTS.
     */
    public function testTrashIsOnlyFoundWhenAskedFor(): void
    {
        $trash    = $this->seedLabel('Trash', LabelRole::Trash);
        $receipts = $this->seedLabel('Receipts');

        $this->seedMessage(subject: 'Binned invoice', body: 'the quarterly invoice', labels: [$trash, $receipts]);
        $this->seedMessage(subject: 'Kept invoice', body: 'the quarterly invoice');

        self::assertSame(['Kept invoice'], $this->search('invoice'));
        self::assertSame([], $this->search('label:receipts'));
        self::assertSame(['Binned invoice'], $this->search('in:trash'));
        self::assertSame(['Binned invoice'], $this->search('in:bin'));
    }

    public function testFlagsAndDatesNarrowTheSameWay(): void
    {
        $this->seedMessage(subject: 'Unread old', receivedAt: '2024-03-01', seen: false);
        $this->seedMessage(subject: 'Read recent', receivedAt: '2026-03-01', seen: true);

        self::assertSame(['Unread old'], $this->search('is:unread'));
        self::assertSame(['Read recent'], $this->search('is:read'));
        self::assertSame(['Read recent'], $this->search('after:2025-01-01'));
        self::assertSame(['Unread old'], $this->search('before:2025-01-01'));
    }

    /** Operators are ANDed: each one may only ever narrow the result. */
    public function testOperatorsCombine(): void
    {
        $this->seedMessage(subject: 'Invoice', fromAddress: 'billing@acme.test');
        $this->seedMessage(subject: 'Invoice', fromAddress: 'other@else.test');

        self::assertSame(['Invoice'], $this->search('subject:invoice from:acme'));
        self::assertCount(2, $this->search('subject:invoice'));
    }

    /**
     * The guard the whole search rests on. Every clause is ANDed onto a user
     * scope, so a filter that matches everything still may not reach another
     * user's mail — the failure mode nobody would see in their own account.
     */
    public function testSearchNeverLeavesTheUser(): void
    {
        $this->seedMessage(subject: 'Mine', fromAddress: 'billing@acme.test');

        $stranger = $this->seedUser();
        $this->seedMessage(
            subject: 'Theirs',
            fromAddress: 'billing@acme.test',
            account: $this->seedAccount($stranger),
        );

        self::assertSame(['Mine'], $this->search('from:billing@acme.test'));
        self::assertSame(1, $this->repository->searchPage($this->user, $this->parser->parse('from:billing@acme.test'))->total);
    }

    // ── Order ────────────────────────────────────────────────────────────────

    /**
     * The default is newest first, and it did not use to be.
     *
     * Search answered in `ts_rank` order with no way to ask for anything else,
     * so a keyword that matched a 2004 mail and a 2026 mail put them in an
     * order that had nothing to do with when they arrived. That is a search
     * engine's answer to a mailbox's question.
     */
    public function testResultsAreNewestFirstByDefault(): void
    {
        // Deliberately seeded out of order, and with the *worse* full-text
        // match on the newest one: under the old ordering "Ancient" led.
        $this->seedMessage(subject: 'Middle',  body: 'invoice', receivedAt: '2015-06-01');
        $this->seedMessage(subject: 'Ancient', body: 'invoice invoice invoice', receivedAt: '2004-12-01');
        $this->seedMessage(subject: 'Newest',  body: 'invoice', receivedAt: '2026-05-01');

        self::assertSame(['Newest', 'Middle', 'Ancient'], $this->search('invoice'));
    }

    /** And the other position of the switch is the order search shipped with. */
    public function testRelevanceOrdersByRankInstead(): void
    {
        $this->seedMessage(subject: 'Middle',  body: 'invoice', receivedAt: '2015-06-01');
        $this->seedMessage(subject: 'Ancient', body: 'invoice invoice invoice', receivedAt: '2004-12-01');
        $this->seedMessage(subject: 'Newest',  body: 'invoice', receivedAt: '2026-05-01');

        $byRank = $this->search('invoice', SearchSortOrder::Relevance);

        self::assertSame('Ancient', $byRank[0], 'the densest match should lead under relevance');
        self::assertCount(3, $byRank);
        self::assertNotSame($this->search('invoice'), $byRank, 'the two orders must differ');
    }

    /**
     * Pagination over a tied sort, which is the bug this order switch exposed.
     *
     * `ORDER BY rank DESC, last_message_at DESC` is not a total order: identical
     * text scoring identically on the same day leaves Postgres free to break the
     * tie however each page's plan happens to, and LIMIT/OFFSET over that shows
     * a row twice and some other row never. Not an exotic case either — a query
     * that stems to nothing makes `ts_rank` degenerate and ties *every* row, so
     * the entire ordering rests on the tiebreaker.
     *
     * Asserted as "the pages, concatenated, are the whole result set in the
     * documented order" rather than merely "no duplicates": a wrong-but-stable
     * order would pass the weaker check on a small table.
     */
    public function testRelevanceStaysStableAcrossPagesWhenRanksTie(): void
    {
        // Same body, same day: rank ties and last_message_at ties with it.
        for ($i = 1; $i <= 6; $i++) {
            $this->seedMessage(subject: 'Tied ' . $i, body: 'quarterly report', receivedAt: '2026-02-02');
        }

        $parsed = $this->parser->parse('quarterly report');
        $single = $this->searchIds($parsed, page: 1, perPage: 6, sort: SearchSortOrder::Relevance);

        self::assertCount(6, $single);

        $paged = array_merge(
            $this->searchIds($parsed, page: 1, perPage: 2, sort: SearchSortOrder::Relevance),
            $this->searchIds($parsed, page: 2, perPage: 2, sort: SearchSortOrder::Relevance),
            $this->searchIds($parsed, page: 3, perPage: 2, sort: SearchSortOrder::Relevance),
        );

        self::assertSame($single, $paged, 'paging a tied ranking dropped or repeated rows');
        self::assertSame($single, array_values(array_unique($paged)));

        // The tiebreaker is id, descending — deterministic, and the same one
        // whichever page you are on.
        $descending = $single;
        rsort($descending);

        self::assertSame($descending, $single);
    }

    /** The same guarantee for the default order, whose dates tie just as often. */
    public function testRecentStaysStableAcrossPagesWhenDatesTie(): void
    {
        for ($i = 1; $i <= 6; $i++) {
            $this->seedMessage(subject: 'Same day ' . $i, body: 'standup notes', receivedAt: '2026-02-02');
        }

        $parsed = $this->parser->parse('standup notes');
        $single = $this->searchIds($parsed, page: 1, perPage: 6);

        $paged = array_merge(
            $this->searchIds($parsed, page: 1, perPage: 2),
            $this->searchIds($parsed, page: 2, perPage: 2),
            $this->searchIds($parsed, page: 3, perPage: 2),
        );

        self::assertCount(6, $single);
        self::assertSame($single, $paged, 'paging a tied date order dropped or repeated rows');

        $descending = $single;
        rsort($descending);

        self::assertSame($descending, $single);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    /** @return list<string> subjects of the matching threads */
    private function search(string $query, SearchSortOrder $sort = SearchSortOrder::Recent): array
    {
        return array_map(
            static fn (MessageThread $thread): string => (string) $thread->subject,
            $this->repository->searchPage($this->user, $this->parser->parse($query), sort: $sort)->threads,
        );
    }

    /** @return list<int> thread ids, in the order the SQL returned them */
    private function searchIds(
        ParsedSearchQuery $parsed,
        int $page = 1,
        int $perPage = 50,
        SearchSortOrder $sort = SearchSortOrder::Recent,
    ): array {
        return array_map(
            static fn (MessageThread $thread): int => (int) $thread->id,
            $this->repository->searchPage($this->user, $parsed, $page, $perPage, $sort)->threads,
        );
    }

    /**
     * @param list<array{name: string, address: string}> $to
     * @param list<array{name: string, address: string}> $cc
     * @param list<Label>                                $labels
     */
    /**
     * The body-substring pass is allowed to run out of time; the search is not
     * allowed to fall over when it does.
     *
     * That pass scans `body_text ILIKE '%needle%'` and cannot be indexed out of
     * existence — buildSearchSql() explains why at length, and its note already
     * recorded a term going "from 13 seconds to a 30-second PHP timeout". Being
     * conditional made it rare rather than bounded, and a live mailbox duly
     * produced MaxExecutionTimeError at Statement.php:55.
     *
     * Run here with a one-millisecond budget, which no query can meet, so the
     * expiry is certain rather than hoped for. What must survive is what the
     * cheap full-text pass already found.
     */
    public function testAnExpiredBodyPassKeepsTheResultsTheCheapPassFound(): void
    {
        $this->seedMessage('Quarterly figures', body: 'The pelican audit is attached.');

        $impatient = new MessageThreadRepository(
            self::getContainer()->get(ManagerRegistry::class),
            new FreeTextCompiler(),
            rescueTimeoutMs: 1,
        );

        $page = $impatient->searchPage($this->user, $this->parser->parse('pelican'));

        self::assertCount(
            1,
            $page->threads,
            'the full-text hit was already in hand before the body pass was attempted',
        );
        self::assertSame('Quarterly figures', $page->threads[0]->subject);
    }

    /**
     * The semantic arm finds a message the words do not.
     *
     * "meeting minutes" shares no term with "Notes from the standup", so every
     * lexical arm misses it — full-text stems words, the prefix pass matches
     * prefixes, and the ILIKE passes need the literal string. Only the vector
     * can reach it, which is what makes this a test of the new arm rather than
     * of the ones beside it.
     *
     * The vectors are hand-written rather than fetched from a model: what is
     * being tested is the SQL — the UNION arm, the parenthesised inner LIMIT,
     * the cast of the bound literal, and the 1:1 join the rank reads — none of
     * which cares where the numbers came from, and all of which would be
     * hostage to whichever model happened to be installed.
     */
    public function testTheSemanticArmFindsAMessageTheWordsDoNot(): void
    {
        $this->seedMessage('Notes from the standup', body: 'Who is doing what this week.');

        $id = (int) $this->connection->fetchOne(
            'SELECT id FROM message WHERE subject = ?',
            ['Notes from the standup'],
        );

        $this->store()->store($id, [1.0, 0.0, 0.0], 'test-model');

        // Lexically hopeless.
        self::assertCount(
            0,
            $this->repository->searchPage($this->user, $this->parser->parse('meeting minutes'))->threads,
            'if the words alone find this, the test is not about the vector',
        );

        // The same query, with a vector pointing where the message is.
        $page = $this->repository->searchPage(
            $this->user,
            $this->parser->parse('meeting minutes'),
            semantic: $this->semantic([1.0, 0.0, 0.0]),
        );

        self::assertCount(1, $page->threads);
        self::assertSame('Notes from the standup', $page->threads[0]->subject);
        self::assertSame(1, $page->total, 'the total has to come from the same statement as the rows');

        // ...and it says so. A person who typed two words that appear nowhere
        // in this message is owed the reason it is in their list.
        self::assertSame([(int) $page->threads[0]->id], $page->semanticOnly);
    }

    /** Far apart in vector space is not a hit, or the arm would return everything. */
    public function testTheSemanticArmRespectsItsDistanceThreshold(): void
    {
        $this->seedMessage('Notes from the standup', body: 'Who is doing what this week.');

        $id = (int) $this->connection->fetchOne('SELECT id FROM message WHERE subject = ?', ['Notes from the standup']);
        $this->store()->store($id, [1.0, 0.0, 0.0], 'test-model');

        $page = $this->repository->searchPage(
            $this->user,
            $this->parser->parse('meeting minutes'),
            // Orthogonal: cosine distance 1, well past the threshold.
            semantic: $this->semantic([0.0, 1.0, 0.0]),
        );

        self::assertCount(0, $page->threads);
    }

    /** A message with no embedding still matches lexically, and ranks. */
    public function testAMessageWithoutAnEmbeddingIsStillFoundByWords(): void
    {
        $this->seedMessage('Quarterly figures', body: 'The pelican audit is attached.');

        $page = $this->repository->searchPage(
            $this->user,
            $this->parser->parse('pelican'),
            semantic: $this->semantic([1.0, 0.0, 0.0]),
        );

        self::assertCount(1, $page->threads, 'the LEFT JOIN must not drop rows that have no vector');
        self::assertSame([], $page->semanticOnly, 'the words found it, so nothing needs explaining');
    }

    /**
     * A row the words found is not labelled as a row the vector found, even
     * when the vector would happily have found it too.
     *
     * This is the distinction the provenance column exists to make, and the
     * easy way to get it wrong is to mark every row with an embedding near the
     * query — which on a fully indexed mailbox is most of the page.
     */
    public function testARowTheWordsFoundIsNeverMarkedAsAMeaningMatch(): void
    {
        $this->seedMessage('Quarterly figures', body: 'The pelican audit is attached.');
        $this->seedMessage('Notes from the standup', body: 'Who is doing what this week.');

        // Both sit at the same point in vector space, so both are inside the
        // threshold. Only one of them contains the word.
        $this->store()->store($this->messageId('Quarterly figures'), [1.0, 0.0, 0.0], 'test-model');
        $this->store()->store($this->messageId('Notes from the standup'), [1.0, 0.0, 0.0], 'test-model');

        $page = $this->repository->searchPage(
            $this->user,
            $this->parser->parse('pelican'),
            semantic: $this->semantic([1.0, 0.0, 0.0]),
        );

        self::assertCount(2, $page->threads);

        $marked = array_map(
            fn (int $id): string => (string) $this->connection->fetchOne('SELECT subject FROM message_thread WHERE id = ?', [$id]),
            $page->semanticOnly,
        );

        self::assertSame(['Notes from the standup'], $marked);
    }

    /**
     * Vectors written by a different model are left where they are.
     *
     * Changing the search model in the admin panel invalidates every stored
     * vector: different space, different width, and the comparison does not
     * fail — the shipped distance function compares whatever overlaps and
     * answers a plausible number, so the failure mode is a search that ranks
     * confidently and wrongly. On an installation with pgvector the same
     * comparison raises `different vector dimensions`, which is a 500 on
     * /mail/search for everybody.
     *
     * Either way the answer is the same: search what matches the model in hand,
     * and never mix two spaces in one query.
     */
    public function testVectorsFromAnotherModelAreNotSearched(): void
    {
        $this->seedMessage('Notes from the standup', body: 'Who is doing what this week.');
        $this->store()->store($this->messageId('Notes from the standup'), [1.0, 0.0, 0.0], 'previous-model');

        $page = $this->repository->searchPage(
            $this->user,
            $this->parser->parse('meeting minutes'),
            semantic: $this->semantic([1.0, 0.0, 0.0], 'current-model'),
        );

        self::assertCount(0, $page->threads, 'a vector from another model is not a hit');
    }

    /** The same, for a model that kept its name and changed its width. */
    public function testVectorsOfAnotherWidthAreNotSearched(): void
    {
        $this->seedMessage('Notes from the standup', body: 'Who is doing what this week.');
        $this->store()->store($this->messageId('Notes from the standup'), [1.0, 0.0, 0.0], 'test-model');

        $page = $this->repository->searchPage(
            $this->user,
            $this->parser->parse('meeting minutes'),
            semantic: SemanticSearch::ran(
                (string) EmbeddingStore::unitLiteral([1.0, 0.0, 0.0, 0.0]),
                'test-model',
                4,
            ),
        );

        self::assertCount(0, $page->threads);
    }

    /**
     * The vector is allowed to run out of time; the search is not allowed to
     * fall over, and it is not allowed to come back empty.
     *
     * Semantic search measured 12.9 seconds mean and 47 seconds worst case in
     * production. The shape that caused it is fixed in buildSearchSql(), but a
     * query plan is a decision the planner makes at runtime and no amount of
     * correct SQL binds it — so the vector carries a budget, and losing it
     * costs the vector rather than the search.
     *
     * One millisecond, which no query can meet, so the expiry is certain rather
     * than hoped for. What must survive is the row the WORDS found.
     */
    public function testAnExpiredSemanticPassStillAnswersWithWhatTheWordsFound(): void
    {
        $this->seedMessage('Quarterly figures', body: 'The pelican audit is attached.');
        $this->store()->store($this->messageId('Quarterly figures'), [1.0, 0.0, 0.0], 'test-model');

        $impatient = new MessageThreadRepository(
            self::getContainer()->get(ManagerRegistry::class),
            new FreeTextCompiler(),
            semanticTimeoutMs: 1,
        );

        $page = $impatient->searchPage(
            $this->user,
            $this->parser->parse('pelican'),
            semantic: $this->semantic([1.0, 0.0, 0.0]),
        );

        self::assertCount(1, $page->threads, 'the keyword pass answers when the vector cannot');
        self::assertSame('Quarterly figures', $page->threads[0]->subject);
        self::assertSame(1, $page->total, 'the total still comes from the statement that answered');
    }

    /**
     * And what only the vector could have found is what is given up.
     *
     * The deliberate price, stated as a test so that nobody has to infer it:
     * a degraded search is a search, and this is what "degraded" means here.
     */
    public function testAnExpiredSemanticPassGivesUpTheRowsOnlyTheVectorCouldFind(): void
    {
        $this->seedMessage('Notes from the standup', body: 'Who is doing what this week.');
        $this->store()->store($this->messageId('Notes from the standup'), [1.0, 0.0, 0.0], 'test-model');

        $impatient = new MessageThreadRepository(
            self::getContainer()->get(ManagerRegistry::class),
            new FreeTextCompiler(),
            semanticTimeoutMs: 1,
        );

        $page = $impatient->searchPage(
            $this->user,
            $this->parser->parse('meeting minutes'),
            semantic: $this->semantic([1.0, 0.0, 0.0]),
        );

        self::assertCount(0, $page->threads);
        self::assertSame([], $page->semanticOnly);
    }

    /**
     * A search with no vector never enters the budget at all.
     *
     * The same distinction testTheBudgetDoesNotAffectTheCheapPass() draws for
     * the body pass: the guard is on one pass, not on the query as a whole, and
     * an installation with the feature off must produce the search it always
     * did on a connection nobody has touched.
     */
    public function testTheSemanticBudgetDoesNotAffectASearchWithoutAVector(): void
    {
        $this->seedMessage('Quarterly figures', body: 'The pelican audit is attached.');

        $impatient = new MessageThreadRepository(
            self::getContainer()->get(ManagerRegistry::class),
            new FreeTextCompiler(),
            semanticTimeoutMs: 1,
        );

        self::assertCount(
            1,
            $impatient->searchPage($this->user, $this->parser->parse('pelican'))->threads,
            'a keyword search must be untouched by the vector budget',
        );
    }

    /**
     * Every candidate is scored ONCE, and the outer query does no arithmetic.
     *
     * THIS IS THE 12.9-SECOND BUG, AND NOTHING ELSE IN THIS FILE CAN SEE IT.
     * Every other semantic test here passes against the shape that took 47
     * seconds in production: the rows were right, the flags were right, and the
     * cost was the defect. So this one reads the statement instead.
     *
     * Three properties, each of which was separately false before:
     *
     *  - plmail_embed_distance() appears ONCE. It used to be written three
     *    times — once in the UNION arm and twice in the outer query, for `rank`
     *    and for `semantic_only`.
     *  - :queryVector is bound ONCE. DBAL expands each occurrence of a named
     *    parameter into its own positional parameter, so two textually
     *    identical expressions arrived at Postgres as two different ones and
     *    the aggregate sharing the old comment relied on never happened.
     *    Measured on 20,000 rows: 12,616 calls where 2,000 were needed.
     *  - The CTE is MATERIALIZED. Spelled NOT MATERIALIZED the planner inlines
     *    it at both references and re-evaluates it, which is the bug restored:
     *    4,369 calls and 2.2s against 2,000 calls and 0.63s.
     *
     * Reading the SQL is the point rather than a shortcut. There is no result
     * this can be asserted from — that is exactly why it went unnoticed.
     */
    public function testTheDistanceIsComputedOnceOverCandidatesOnly(): void
    {
        $build = new \ReflectionMethod($this->repository, 'buildSearchSql');

        [$sql, $params] = $build->invoke(
            $this->repository,
            $this->user,
            $this->parser->parse('pelican'),
            true,
            $this->semantic([1.0, 0.0, 0.0]),
        );

        self::assertSame(
            1,
            substr_count($sql, 'plmail_embed_distance'),
            'the distance belongs in the CTE and nowhere else',
        );
        self::assertSame(
            1,
            substr_count($sql, ':queryVector'),
            'a second occurrence is a second positional parameter, and a second scan',
        );
        self::assertStringContainsString(
            'AS MATERIALIZED (',
            $sql,
            'an inlined CTE is re-evaluated per reference, which is the bug',
        );
        self::assertArrayHasKey('semanticSimilarity', $params);
        self::assertArrayNotHasKey(
            'semanticDistance',
            $params,
            'one constant: the arm and the provenance column read the same threshold',
        );
    }

    private function messageId(string $subject): int
    {
        return (int) $this->connection->fetchOne('SELECT id FROM message WHERE subject = ?', [$subject]);
    }

    /**
     * @param list<float> $vector
     */
    private function semantic(array $vector, string $model = 'test-model'): SemanticSearch
    {
        return SemanticSearch::ran(
            (string) EmbeddingStore::unitLiteral($vector),
            $model,
            count($vector),
        );
    }

    private function store(): EmbeddingStore
    {
        return new EmbeddingStore($this->connection, new \Psr\Log\NullLogger());
    }

    /**
     * A free-text search does not join the label tables at all.
     *
     * This is a test about the SHAPE of the SQL rather than about a result,
     * which needs justifying: the bug it guards was invisible in every result.
     * `thread_label` and `label` were joined unconditionally, both are
     * to-many, and every (thread, matching message) row was therefore
     * multiplied by the number of labels the thread carries — then collapsed
     * again by the GROUP BY, which hid the duplication perfectly. The answers
     * were always correct. Only the clock showed it, at two seconds a search
     * on a real mailbox.
     *
     * So there is nothing observable to assert on, and an assertion about the
     * result set could not fail if the joins came back. This one can.
     */
    public function testAFreeTextSearchDoesNotJoinTheLabelTables(): void
    {
        $sql = $this->searchSqlFor('amazon');

        self::assertStringNotContainsString('thread_label tl', $sql, 'nothing in a free-text search reads a label');
        self::assertStringNotContainsString('label lbl', $sql);

        // The trash exclusion is a NOT EXISTS with its own aliases and must
        // still be there — it is what keeps deleted mail out of results.
        self::assertStringContainsString('NOT EXISTS', $sql);
    }

    /**
     * ...and a search that DOES ask about a label still joins them, or the
     * filter would have nothing to read.
     */
    public function testALabelSearchStillJoinsTheLabelTables(): void
    {
        $sql = $this->searchSqlFor('label:Receipts');

        self::assertStringContainsString('thread_label tl', $sql);
        self::assertStringContainsString('label lbl', $sql);
    }

    /** The same, for the mailbox role behind `in:`. */
    public function testAMailboxSearchStillJoinsTheLabelTables(): void
    {
        self::assertStringContainsString('label lbl', $this->searchSqlFor('in:spam'));
    }

    /**
     * The statement the repository would run, without running it.
     *
     * Reflection because buildSearchSql() is private and should stay private —
     * it is not an API, and the alternative is making a method public purely so
     * a test can look at it.
     */
    private function searchSqlFor(string $query): string
    {
        $method = new \ReflectionMethod(MessageThreadRepository::class, 'buildSearchSql');

        /** @var array{string, array<string,mixed>, array<string,mixed>} $built */
        $built = $method->invoke($this->repository, $this->user, $this->parser->parse($query), false);

        return $built[0];
    }

    /**
     * Proof that the body pass is genuinely reached, so the two tests around
     * this one are not passing on a pass that never ran.
     *
     * "elica" sits inside "pelican" and nowhere else: full-text stems whole
     * words, the prefix pass matches `'elica':*` which "pelican" does not
     * start with, and the subject and sender columns do not contain it. Only
     * `body_text ILIKE '%elica%'` can find this row.
     */
    public function testAnInfixIsFoundOnlyByTheBodyPass(): void
    {
        $this->seedMessage('Quarterly figures', body: 'The pelican audit is attached.');

        self::assertCount(
            1,
            $this->repository->searchPage($this->user, $this->parser->parse('elica'))->threads,
            'if this finds nothing the body pass is not running and the budget tests prove nothing',
        );
    }

    /**
     * The same search, with a budget no query can meet: nothing found, and
     * nothing thrown.
     *
     * Before this, the pass ran unbounded and a live mailbox answered with
     * `MaxExecutionTimeError: Maximum execution time of 30 seconds exceeded`.
     * Losing the infix is the deliberate price; a 500 was not a price anybody
     * chose.
     */
    public function testAnExpiredBodyPassGivesUpInsteadOfFailing(): void
    {
        $this->seedMessage('Quarterly figures', body: 'The pelican audit is attached.');

        $impatient = new MessageThreadRepository(
            self::getContainer()->get(ManagerRegistry::class),
            new FreeTextCompiler(),
            rescueTimeoutMs: 1,
        );

        $page = $impatient->searchPage($this->user, $this->parser->parse('elica'));

        self::assertCount(0, $page->threads, 'the one row only the expired pass could find');
        self::assertSame(0, $page->total);
    }

    /**
     * And the same budget must not quietly empty an ordinary search: the guard
     * is on one pass, not on the query as a whole.
     */
    public function testTheBudgetDoesNotAffectTheCheapPass(): void
    {
        $this->seedMessage('Invoice 88', fromAddress: 'billing@acme.test');

        $impatient = new MessageThreadRepository(
            self::getContainer()->get(ManagerRegistry::class),
            new FreeTextCompiler(),
            rescueTimeoutMs: 1,
        );

        self::assertCount(
            1,
            $impatient->searchPage($this->user, $this->parser->parse('from:billing@acme.test'))->threads,
            'operator searches never reach the body pass and must be untouched by its budget',
        );
    }

    private function seedMessage(
        string $subject,
        string $fromAddress = 'sender@example.test',
        string $fromName = 'Sender',
        array $to = [],
        array $cc = [],
        array $labels = [],
        string $body = 'Nothing in particular.',
        string $receivedAt = '2026-01-01',
        bool $seen = false,
        ?Account $account = null,
    ): void {
        $account ??= $this->account;

        $thread                    = new MessageThread();
        $thread->account           = $account;
        $thread->subject           = $subject;
        $thread->normalizedSubject = mb_strtolower($subject);
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
        $thread->lastMessageAt     = new DateTimeImmutable($receivedAt);

        $message                 = new Message();
        $message->account        = $account;
        $message->thread         = $thread;
        $message->subject        = $subject;
        $message->fromAddress    = $fromAddress;
        $message->fromName       = $fromName;
        $message->toAddresses    = $to;
        $message->ccAddresses    = $cc;
        $message->bodyText       = $body;
        $message->receivedAt     = new DateTimeImmutable($receivedAt);
        $message->sentAt         = $message->receivedAt;
        $message->hasAttachments = false;
        $message->flags          = [];

        if (true === $seen) {
            $message->seenAt = new DateTimeImmutable($receivedAt);
        }

        foreach ($labels as $label) {
            $message->addLabel($label);
            $thread->addLabel($label);
        }

        $thread->addMessage($message);

        $this->em->persist($thread);
        $this->em->persist($message);
        $this->em->flush();
    }

    private function seedLabel(string $name, ?LabelRole $role = null): Label
    {
        $label            = new Label();
        $label->usr       = $this->user;
        $label->name      = $name;
        $label->role      = $role;
        $label->isVisible = true;

        $this->em->persist($label);
        $this->em->flush();

        return $label;
    }

    private function seedAccount(User $user): Account
    {
        $account                 = new Account();
        $account->usr            = $user;
        $account->name           = 'Search fixture';
        $account->email          = 'search@example.test';
        $account->username       = uniqid('search-', true);
        $account->imapHost       = 'imap.example.test';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        $account->authType       = 'password';
        $account->isActive       = true;

        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }

    private function seedUser(): User
    {
        $user            = new User();
        $user->email     = 'search-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Search';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
