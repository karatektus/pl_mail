<?php

declare(strict_types=1);

namespace App\Repository\Mail\MessageThread;

use App\Domain\DTO\Ai\SemanticSearch;
use App\Domain\DTO\Mail\SearchPage;
use App\Domain\DTO\ParsedSearchQuery;
use App\Domain\Enum\Ai\SemanticSkipReason;
use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\SearchSortOrder;
use App\Entity\Mail\MessageThread;
use App\Service\Search\FreeTextCompiler;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Search: one page of threads for a parsed query, with its total, under the time
 * budgets that keep the body pass and the vector arm from holding a request.
 */
class MessageThreadSearchRepository extends ServiceEntityRepository
{
    /**
     * How many recent messages the semantic arm is allowed to look at.
     *
     * The distance function costs about 0.13ms a row at 1024 dimensions, so
     * this is roughly 260ms of arithmetic — measured at 200ms for the whole CTE
     * on a 20,000-message corpus, in the same range as the full-text pass
     * beside it (89ms) and the prefix pass (111ms). Over a whole
     * 100,000-message mailbox the same loop is thirteen seconds, which is worse
     * than the pathology the UNION shape exists to avoid, so this number is not
     * a tuning knob so much as the thing that keeps the feature affordable at
     * all.
     *
     * It is now also exactly how many times the function runs, which it was
     * not: the outer join used to score every matched row again, twice. See the
     * CTE in buildSearchSql().
     *
     * Recency is the bound because it is what people search within, and because
     * it does not change between pages — a bound that moved would make
     * LIMIT/OFFSET return the same conversation twice.
     *
     * NOT because an index serves it, which this used to claim. There is no
     * index on (account_id, received_at) and adding one changes nothing
     * measurable: on 40,000 messages of 1024 dimensions the arm runs in 188 ms,
     * and building that index moves it to 186 ms. Picking the candidates is a
     * bitmap scan and a top-N heapsort of a few hundred buffers; the 2,000
     * distance calls are the entire cost. The claim was plausible, unmeasured
     * and load-bearing for nothing, and it would have sent somebody off to
     * build an index that buys 1%.
     */
    private const int SEMANTIC_CANDIDATES = 2000;
    /**
     * How many of those candidates may actually reach the results.
     *
     * **A threshold alone does not bound a result set, and this is what that
     * costs.** Searching one company name returned 63 rows where Gmail over the
     * same mail returned 16: sixteen matches on words, and forty-seven rows the
     * vector liked enough to clear 0.42 — LinkedIn job alerts, a newsletter, an
     * invoice, a piece of outright spam. Every one correctly badged "meaning",
     * every one useless, and together they filled the page so completely that
     * the best match in the mailbox — the company's own reply, matching on
     * subject AND sender — was on page two.
     *
     * The threshold was not mistuned. It was measured on the twelve-message
     * corpus in {@see \App\Domain\Ai\EmbeddingPreset}, where a false-positive
     * rate of a couple of percent is invisible. Against SEMANTIC_CANDIDATES it
     * is forty-seven rows. A precision figure taken at n=12 does not survive
     * being applied at n=2000, and no value of it would: the fix for "a small
     * fraction of two thousand is still a lot" is a cap, not a stricter
     * fraction.
     *
     * **Why the cap is safe HERE when the note above says it is not.** That
     * objection is real and applies to a top-k taken over the UNION, which
     * would interact with OFFSET and make `COUNT(*) OVER ()` mean "lexical plus
     * up to k, on page one only". This LIMIT is inside `sem_hits`, which is a
     * CTE evaluated once per statement and does not see the page: the candidate
     * window is deterministic (`received_at DESC, id DESC`), so the admitted set
     * is byte-identical on every page and the total stays honest. The earlier
     * reasoning was right about the wrong position.
     *
     * Ten, because the semantic arm is a SUGGESTION and not an answer. A search
     * whose words found something shows what they found first — see
     * {@see \App\Domain\Enum\Mail\SearchSortOrder::orderBy()} — and these ride
     * behind it as a short tail somebody can read or ignore. A search whose
     * words found nothing is the case the feature exists for, and ten is a
     * reasonable answer there too.
     */
    private const int SEMANTIC_HITS = 10;
    /**
     * How long the body-substring pass gets before it is abandoned.
     *
     * The pass is measured at about 3 seconds on a 300,000-message corpus —
     * see the rescue note in buildSearchSql() — so this is generous for a
     * healthy mailbox and a hard stop for one where it is not. It has to be a
     * DATABASE timeout rather than a PHP one: `max_execution_time` kills the
     * PHP process and leaves Postgres still running the scan, so the work
     * continues after the person who asked for it has already been shown a
     * 500. `statement_timeout` stops the actual query.
     *
     * Higher than the type-ahead's 1500ms because the two are different
     * promises. A dropdown that misses a keystroke costs nothing; a search
     * somebody typed and pressed enter on has earned a few seconds.
     */
    private const int SEARCH_RESCUE_TIMEOUT_MS = 5000;
    /**
     * How long a search carrying a vector gets before the vector is dropped.
     *
     * WHY A SEARCH WITH A VECTOR NEEDS ITS OWN STOP
     * ─────────────────────────────────────────────
     * The semantic arm is bounded by SEMANTIC_CANDIDATES, and that bound is
     * arithmetic the planner is free to ignore: it can decide the CTE is cheap,
     * push the join the wrong way round, or lose the index that serves the
     * recency ordering, and then plmail_embed_distance() runs over the mailbox
     * instead of over two thousand rows. That is not hypothetical — it is what
     * 47 seconds on /mail/search was, and no amount of correct SQL prevents a
     * plan nobody can see from a production request.
     *
     * Measured at 0.63s for the whole statement over 2,000 candidates of 1024
     * dimensions, so this is eight times the healthy cost and a hard stop for a
     * plan that has gone wrong.
     *
     * The same 5000 as the body pass, and deliberately: the two are the only
     * unbounded-in-practice things in a search, so one number is also the
     * promise — a search spends at most about ten seconds in the database
     * before it answers with whatever it has. They are separate constants
     * because they guard separate work, not because they are expected to
     * differ.
     */
    private const int SEARCH_SEMANTIC_TIMEOUT_MS = 5000;

    public function __construct(
        ManagerRegistry $registry,
        private readonly FreeTextCompiler $freeText = new FreeTextCompiler(),
        /**
         * Injectable so the give-up path can be tested for what it does rather
         * than inspected for what it says. A budget nothing ever exercises is a
         * budget nobody knows the shape of; at 1ms a test can watch the pass
         * expire and check that the cheap results are still standing.
         */
        private readonly int $rescueTimeoutMs = self::SEARCH_RESCUE_TIMEOUT_MS,
        /**
         * The same argument as above, for the other pass that can run long.
         * Separate from $rescueTimeoutMs because the two guard different work
         * and a test that wants to watch one expire must not have to expire
         * the other as well.
         */
        private readonly int $semanticTimeoutMs = self::SEARCH_SEMANTIC_TIMEOUT_MS,
        /**
         * And the third, for the same reason as the first two: a bound nothing
         * exercises is a bound nobody knows the shape of.
         *
         * This one guards WHICH messages are eligible rather than how long they
         * may take, and the bug that made it injectable was invisible at two
         * thousand — the candidate subquery filtered the wrong table alias, so
         * the window was shared with every other account on the installation.
         * A test cannot afford to seed 2,000 messages to see that; at 2 it is a
         * three-row fixture. See buildSearchSql().
         */
        private readonly int $semanticCandidates = self::SEMANTIC_CANDIDATES,
        /**
         * And the fourth, for the reason the other three are injectable: a
         * bound nothing exercises is a bound nobody knows the shape of. A test
         * that had to seed eleven near-neighbours to see this one work would
         * not be written; at 1 it is a two-row fixture.
         */
        private readonly int $semanticHits = self::SEMANTIC_HITS,
        /**
         * The first logger this repository has ever had, and it is here for
         * one call site: the vector arm's give-up path, which discarded the
         * exception it caught and told the person searching that the model had
         * been slow. See cheapRows().
         *
         * Defaulted so that the several tests which build this by hand keep
         * working unchanged; autowiring hands the real one to the application.
         */
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        parent::__construct($registry, MessageThread::class);
    }

    /**
     * Full-text + operator search across messages for a given user — one page
     * of hydrated MessageThread entities, and how many threads match in all.
     *
     * Uses raw DBAL SQL because:
     *  - websearch_to_tsquery / @@ / ts_rank are not native DQL functions
     *  - We need DISTINCT ON which DQL cannot express
     *
     * The ORDER BY comes from the enum rather than being spelled here, because
     * it has to stay in step with the tiebreaker every order needs to survive
     * pagination — see SearchSortOrder::orderBy().
     *
     * The total arrives WITH the page rather than from a countSearch() beside
     * it, and that is the whole reason this returns an object. See searchRows()
     * for the measurement; the short version is that the second statement
     * repeated every scan the first had already done, for a number rendered in
     * the corner of the toolbar.
     */
    public function searchPage(
        UserInterface     $user,
        ParsedSearchQuery $query,
        int               $page = 1,
        int               $perPage = 50,
        SearchSortOrder   $sort = SearchSortOrder::Recent,
        ?SemanticSearch   $semantic = null,
    ): SearchPage {
        $offset = ($page - 1) * $perPage;

        // The vector comes back as well as the rows, because it may not have
        // survived: see cheapRows(). Everything below this line has to use the
        // one that actually answered — running the body pass with a vector the
        // cheap pass just gave up on would spend the budget twice over for the
        // same result.
        [$rows, $semantic] = $this->cheapRows($user, $query, $sort, $perPage, $offset, $semantic);

        // Thin, so the expensive pass earns its keep — see the rescue note in
        // buildSearchSql(). Only ever reached on a page that did not fill,
        // which is the same condition the pass exists to serve.
        //
        // COUNTED OVER THE ROWS THE WORDS FOUND, not over the page. The body
        // pass exists to catch a needle inside a token that the tokenizer split
        // the wrong way — a LEXICAL rescue — and it was being called off by
        // rows that are not lexical at all. Forty-seven vector hits padding a
        // page to exactly fifty made `50 < 50` false, so the one arm that could
        // still have found the mail never ran, and nothing said so. The cap on
        // sem_hits makes that arithmetic much harder to hit; counting the right
        // thing makes it impossible.
        if ($this->lexicalCount($rows) < self::SEARCH_RESCUE_BELOW) {
            $rows = $this->rescueRows(
                fn (): array => $this->searchRows($user, $query, $sort, $perPage, $offset, true, $semantic),
                $rows,
            );
        }

        if ([] === $rows) {
            return new SearchPage([], $this->totalForEmptyPage($user, $query, $sort, $page, $semantic), [], $semantic);
        }

        // Every row carries the same window count, so the first one will do.
        $total = (int) $rows[0]['total_threads'];

        $ids = array_column($rows, 'thread_id');

        // Hydrate via Doctrine so we get full entities (same as other finders).
        // Order does not matter here — it is restored below, from the order the
        // SQL returned.
        $threads = $this->findBy(['id' => $ids]);

        // Re-order to match the sort order from SQL
        $indexed = [];
        foreach ($threads as $thread) {
            $indexed[$thread->id] = $thread;
        }

        $ordered = [];
        foreach ($ids as $id) {
            if (isset($indexed[$id])) {
                $ordered[] = $indexed[$id];
            }
        }

        // Provenance rides out on the same rows it was computed from. Read as
        // ids rather than as a flag on the entity: MessageThread is a mapped
        // entity shared with every list in the application, and "how this
        // particular search found you" is a property of the search, not of the
        // conversation — a thread hydrated here goes into Doctrine's identity
        // map and would carry the answer to somebody else's question around
        // with it for the rest of the request.
        $semanticOnly = [];

        foreach ($rows as $row) {
            if (1 === (int) ($row['semantic_only'] ?? 0)) {
                $semanticOnly[] = (int) $row['thread_id'];
            }
        }

        // $semantic, not the argument: cheapRows() may have given the vector
        // up, and the reader has to be told about the search that ran.
        return new SearchPage($ordered, $total, $semanticOnly, $semantic);
    }

    /**
     * How many results there are when the requested page has none of them.
     *
     * The window count rides on the rows, so a page past the end comes back
     * with nothing to read it from — and answering zero there would tell
     * somebody who followed a stale `?page=12` link that their query matches
     * nothing at all, with the pager gone and no way back to page 1. One row
     * off the front of the same result set says how many there really are.
     *
     * Page 1 needs no such rescue: an empty first page IS an empty result.
     */
    private function totalForEmptyPage(
        UserInterface     $user,
        ParsedSearchQuery $query,
        SearchSortOrder   $sort,
        int               $page,
        ?SemanticSearch   $semantic = null,
    ): int {
        if (1 === $page) {
            return 0;
        }

        [$rows, $semantic] = $this->cheapRows($user, $query, $sort, 1, 0, $semantic);

        // Spelled as "nothing at all" rather than searchPage()'s "fewer than a
        // screenful": with a limit of one, that test would fire on every
        // query and put the body scan back on a path that does not need it.
        if ([] === $rows) {
            $rows = $this->rescueRows(
                fn (): array => $this->searchRows($user, $query, $sort, 1, 0, true, $semantic),
                $rows,
            );
        }

        return [] === $rows ? 0 : (int) $rows[0]['total_threads'];
    }

    /**
     * One page of thread ids, ranked, each carrying the size of the whole
     * result set.
     *
     * `COUNT(*) OVER ()` instead of a second `SELECT COUNT(DISTINCT t.id)` with
     * the same WHERE, which is what the pager used to cost. Measured on the
     * 300,000-message corpus: 620ms on its own and 450-830ms in the request,
     * because it re-ran the whole UNION and the join over it to answer a
     * question this statement was already in a position to answer. The window
     * runs over the grouped rows the GROUP BY has produced anyway — the sort
     * above it already materialises them — and measured 35ms, three per cent.
     *
     * Bounding the count instead (`SELECT count(*) FROM (SELECT … LIMIT 1001)`,
     * rendered as "1000+") was the obvious cheaper move and does not work here:
     * the LIMIT sits above a DISTINCT, Postgres answers that with a
     * HashAggregate, and a HashAggregate has to read all of its input before it
     * emits its first row. Measured at 717ms against the unbounded 620ms —
     * bounding it made it slower AND made the number a lie.
     *
     * @return list<array<string,mixed>>
     */
    private function searchRows(
        UserInterface     $user,
        ParsedSearchQuery $query,
        SearchSortOrder   $sort,
        int               $perPage,
        int               $offset,
        bool              $withBodyRescue,
        ?SemanticSearch   $semantic = null,
    ): array {
        [$sql, $params, $types] = $this->buildSearchSql(
            $user,
            $query,
            $withBodyRescue,
            $semantic,
            SearchSortOrder::Relevance === $sort,
        );

        $sql .= ' ORDER BY ' . $sort->orderBy() . ' LIMIT :limit OFFSET :offset';
        $params['limit']  = $perPage;
        $params['offset'] = $offset;
        $types['limit']   = ParameterType::INTEGER;
        $types['offset']  = ParameterType::INTEGER;

        return $this->getEntityManager()->getConnection()->fetchAllAssociative($sql, $params, $types);
    }

    /**
     * How many of these rows some arm made of words actually found.
     *
     * The distinction the body rescue needs and never had. `semantic_only` is
     * on every row already — zero where no vector ran at all — so this is a
     * count over data in hand rather than a second question to the database.
     *
     * @param list<array<string,mixed>> $rows
     */
    private function lexicalCount(array $rows): int
    {
        $found = 0;

        foreach ($rows as $row) {
            if (1 !== (int) ($row['semantic_only'] ?? 0)) {
                ++$found;
            }
        }

        return $found;
    }

    /**
     * Below this many cheap hits, the body substring pass is worth its 3
     * seconds; at or above it, it is not.
     *
     * One page. If full-text and the sender/subject passes have already filled
     * the screen, scanning every body for an infix is work whose results the
     * reader will not reach — and since the tokenizer split
     * (Version20260818120000) the case that pass exists for is answered by the
     * index anyway, so what is given up is a genuine edge rather than the
     * feature.
     */
    private const int SEARCH_RESCUE_BELOW = 50;

    /**
     * `query_canceled` — what `statement_timeout` raises when it fires.
     *
     * Spelled here because DBAL does not name it. Its PostgreSQL
     * ExceptionConverter maps deadlocks, constraint violations, syntax errors
     * and half a dozen others onto dedicated classes and lets 57014 fall
     * through to a plain DriverException, so "the budget fired" and "the query
     * is broken" arrive at cheapRows() as the same type. The SQLSTATE is the
     * only thing that tells them apart, and the difference is which machine
     * somebody is sent to look at.
     */
    private const string SQLSTATE_QUERY_CANCELED = '57014';

    /**
     * Run the body-substring pass under a time budget, keeping whatever the
     * cheap passes already found if it does not come back in time.
     *
     * WHY THIS EXISTS
     * ───────────────
     * buildSearchSql() has carried a note for a while saying this pass once
     * took a term "from 13 seconds to a 30-second PHP timeout". The note was
     * right and the mitigation was incomplete: making the pass conditional
     * made it RARE, not bounded, and on a live mailbox it duly produced
     *
     *     MaxExecutionTimeError: Maximum execution time of 30 seconds exceeded
     *
     * The pass is a last resort by its own docblock — it exists for a needle
     * full-text cannot see, and since the tokenizer split the index answers
     * that case directly. Giving up on it costs a genuine edge. Spending the
     * whole request on it costs the search.
     *
     * So: bounded, and on expiry the cheap results stand. A search that
     * returns the eleven things full-text found is a search; a search that
     * 500s after thirty seconds is not.
     *
     * @param callable(): list<array<string,mixed>> $rescue
     * @param list<array<string,mixed>>             $fallback
     *
     * @return list<array<string,mixed>>
     */
    private function rescueRows(callable $rescue, array $fallback): array
    {
        try {
            $budget = $this->rescueTimeoutMs;

            /** @var list<array<string,mixed>> $rows */
            $rows = $this->getEntityManager()->getConnection()->transactional(
                static function (Connection $connection) use ($rescue, $budget): array {
                    // SET LOCAL, so it leaves with the transaction. A plain SET
                    // would strand the timeout on a pooled connection and hand
                    // it to whatever ran next — the same reasoning, and the
                    // same wording, as TypeAheadSearch::fetch().
                    $connection->executeStatement(
                        'SET LOCAL statement_timeout = ' . $budget,
                    );

                    return $rescue();
                },
            );

            return $rows;
        } catch (DriverException) {
            return $fallback;
        }
    }

    /**
     * The cheap pass, with its vector under a time budget — and WITHOUT the
     * vector if the budget runs out.
     *
     * WHY THIS EXISTS
     * ───────────────
     * Semantic search took 12.9 seconds mean and 47 seconds worst case in
     * production, and the shape that caused it is fixed above: the distance is
     * computed once, on candidates only. What is not fixed, and cannot be, is
     * that a query plan is a decision somebody else makes at runtime. The
     * arithmetic in buildSearchSql() bounds the work to SEMANTIC_CANDIDATES
     * rows; it does not bind the planner to agree.
     *
     * So the vector gets a budget, and losing it is a DEGRADED SEARCH rather
     * than a failed one. A page of the eleven things the words found is a
     * search. A page that arrives after 47 seconds — or does not arrive,
     * because PHP gave up at 30 — is not.
     *
     * WHAT IS AND IS NOT INSIDE THE TRANSACTION
     * ─────────────────────────────────────────
     * Only the query. The embedding round trip happens in SearchController,
     * before any of this, and it must stay there: a slow model inside a
     * `statement_timeout` transaction would expire the budget and be reported
     * as a database fault, which is the one diagnosis that would send somebody
     * looking in the wrong place entirely.
     *
     * `SET LOCAL`, so the timeout leaves with the transaction — a plain SET
     * would strand it on a pooled connection and hand it to whatever ran next.
     * Same reasoning and same wording as rescueRows() and
     * TypeAheadSearch::fetch().
     *
     * NOT A GUARD ON THE QUERY AS A WHOLE. A search with no vector is not
     * wrapped at all, so it produces the statement it always did, on a
     * connection with the server's own timeout — the same distinction
     * testTheBudgetDoesNotAffectTheCheapPass() draws for the body pass.
     *
     * @return array{list<array<string,mixed>>, ?SemanticSearch} the rows, and
     *         the vector that actually produced them — null when it was dropped
     */
    private function cheapRows(
        UserInterface     $user,
        ParsedSearchQuery $query,
        SearchSortOrder   $sort,
        int               $perPage,
        int               $offset,
        ?SemanticSearch   $semantic,
    ): array {
        $pass = fn (?SemanticSearch $with): array => $this->searchRows(
            $user,
            $query,
            $sort,
            $perPage,
            $offset,
            false,
            $with,
        );

        if (null === $semantic?->literal) {
            return [$pass(null), null];
        }

        $budget = $this->semanticTimeoutMs;

        try {
            /** @var list<array<string,mixed>> $rows */
            $rows = $this->getEntityManager()->getConnection()->transactional(
                static function (Connection $connection) use ($pass, $semantic, $budget): array {
                    $connection->executeStatement(
                        'SET LOCAL statement_timeout = ' . $budget,
                    );

                    return $pass($semantic);
                },
            );

            return [$rows, $semantic];
        } catch (DriverException $exception) {
            // Run again without it, and OUTSIDE the transaction the timeout
            // was set on: the keyword statement is the one this search has
            // always been able to afford, and putting the budget that the
            // vector just exhausted around it as well would turn a degraded
            // search into an empty one.
            //
            // SAYING SO, rather than handing back a bare null. A null here
            // reaches the page as "searching by meaning found nothing the
            // words had not already" — a sentence about a search that ran and
            // came up empty, describing one that never finished. That silent
            // degradation is the exact thing the skip reasons were built to
            // stop, and it would have been reintroduced by the timeout that
            // was added to protect the page.
            //
            // AND SAYING WHICH, WHICH IS THE PART THIS USED TO GET WRONG.
            // It answered TimedOut for everything it caught, and TimedOut
            // prints "the model took too long" — a sentence about the OTHER
            // machine. The model had already answered before this method was
            // entered; SemanticQuery does the embedding in the controller,
            // precisely so that a slow model is never reported as a database
            // fault. This was the same confusion pointing the other way, and
            // it is the worse direction: an operator reading "the model took
            // too long" goes to the model host, where the panel shows every
            // search_query call succeeding in single-digit milliseconds, and
            // there is nothing else for them to find.
            //
            // Worse still, DriverException is not "the statement was
            // cancelled". It is every fault the driver can raise — a
            // plmail_embed_distance() that is not installed, a stored width
            // that disagrees with its vector, a connection dropped mid-query —
            // and each of those is DETERMINISTIC. They do not degrade a search
            // occasionally under load; they degrade EVERY search, instantly,
            // for as long as the cause stands, while reporting a timeout that
            // never happened and logging nothing at all.
            $cancelled = self::SQLSTATE_QUERY_CANCELED === $exception->getSQLState();

            // Both branches log, and both log the SQLSTATE. The give-up path is
            // meant to be rare, and a rare thing that leaves no trace is
            // indistinguishable from a common one — which is exactly how this
            // stayed invisible.
            $this->logger->log(
                $cancelled ? 'warning' : 'error',
                $cancelled
                    ? 'Search: the semantic pass was cancelled at its budget; results are lexical only'
                    : 'Search: the semantic pass failed; results are lexical only',
                [
                    'sqlstate'  => $exception->getSQLState(),
                    'budget_ms' => $budget,
                    'exception' => $exception,
                ],
            );

            return [
                $pass(null),
                SemanticSearch::skipped(
                    $cancelled ? SemanticSkipReason::SearchTooSlow : SemanticSkipReason::SearchFailed,
                ),
            ];
        }
    }

    /**
     * The ids the search may look at, read once and bound as values.
     *
     * Takes the id rather than the user, because the caller has already put it
     * in the parameter bag and UserInterface does not declare `$id` — a second
     * `$user->id` in this file is a second thing for static analysis to be told
     * to ignore, for a value that was right there.
     *
     * @return list<int>
     */
    private function activeAccountIdsFor(int $userId): array
    {
        /** @var list<int> $ids */
        $ids = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT id FROM account WHERE usr_id = ? AND is_active = true',
            [$userId],
        );

        return array_map(intval(...), $ids);
    }

    /**
     * The statement both the page and its total come out of.
     *
     * `$ranked` is whether the sort reads `rank`. Only Relevance does; Recent,
     * the default, orders by date. Scoring is not free: `ts_rank` reads the
     * search_vector of every matching message, and on 200,000 messages it was
     * 125ms of a 310ms search for a word in 8% of them and 0.8s of 2.1s for one
     * in nearly all. The column stays, as MAX(0), so both sorts read the same
     * statement shape.
     *
     * @return array{string, array<string,mixed>, array<string,mixed>}
     */
    private function buildSearchSql(
        UserInterface     $user,
        ParsedSearchQuery $query,
        bool              $withBodyRescue = false,
        ?SemanticSearch   $semantic = null,
        bool              $ranked = true,
    ): array {
        $params = [];
        $types  = [];
        $where  = ['a.usr_id = :userId', 'a.is_active = true'];

        $params['userId'] = $user->id;
        $types['userId']  = ParameterType::INTEGER;

        // MAX(0) rather than 0: this is one of the aggregate columns of a
        // GROUP BY, and a search with no free text at all still has to produce
        // the `rank` alias the sort orders by.
        $rankSelect = 'MAX(0)';

        // Zero rather than absent, for the same reason `rank` is MAX(0) above:
        // the ORDER BY names this column on every search so that words sort
        // ahead of vectors, and a statement that omitted it when no vector ran
        // would be a search that fails only on the installations where the
        // feature is switched off. Overwritten below where there is a vector
        // to have an opinion.
        $semanticColumn = ",\n                0                                                 AS semantic_only";
        $semanticRank   = null;
        $semanticCte    = '';

        // ── Free-text ─────────────────────────────────────────────────────
        // Four passes over one question, and how they are COMBINED is the
        // whole performance story of this method.
        //
        // They used to be OR-ed into the WHERE clause. One OR spanning two
        // index families — GIN over the tsvector, GIN trigram over four text
        // columns — is a shape Postgres answers by abandoning both: measured on
        // 300,000 messages it drove from message_thread, walked all 100,000
        // threads, and applied the whole thing as a row filter. Every index sat
        // unused, and the query cost the same 9-10 SECONDS whether the term was
        // in 0.25% of the mail or 8% of it. Cost independent of selectivity is
        // the signature of an index doing nothing.
        //
        // As a UNION each branch is its own scan and picks its own index:
        // websearch 89ms, prefix 111ms, subject 4ms, the two sender columns
        // 0.2ms.
        //
        // The body substring pass is kept OUT of that union and run only as a
        // rescue — see below.
        if ($query->freeText !== '') {
            $free = $this->freeText->compile($query->freeText);

            $params['freeText'] = $free->websearch;

            // Scoped to the searcher's own accounts. The outer query filters
            // by user anyway, so this is not what makes the answer correct — it
            // is what stops one person's search reading another person's mail
            // off the disk on a shared install, and what keeps the cost
            // proportional to the mailbox being searched.
            //
            // The ids are resolved HERE and bound as a list, rather than left
            // as `account_id IN (SELECT id FROM account WHERE usr_id = …)`, and
            // that is not a style preference — it is worth 18x. With the
            // subquery the planner cannot know how many accounts come back, so
            // it reaches for the account_id index, which on a single-user
            // install selects everything: 2,326ms per branch, the GIN index
            // untouched. Given the ids it costs them properly and uses the
            // tsvector index: 126ms.
            $accountIds = $this->activeAccountIdsFor((int) $params['userId']);

            // No accounts, no mail — and `IN ()` is not valid SQL. Answered as
            // a false predicate inside the normal statement rather than by
            // returning a different one: the callers append ORDER BY and LIMIT
            // to whatever comes back, so a short-circuit SQL string here is a
            // syntax error one frame later. It was: /mail/search 500'd for any
            // user who had not connected a mailbox yet.
            if ([] === $accountIds) {
                $where[]    = 'false';
                $rankSelect = 'MAX(0)';

                $mine       = 'false';
                $mineRecent = 'false';
            } else {
                $params['searchAccounts'] = $accountIds;
                $types['searchAccounts']  = ArrayParameterType::INTEGER;

                $mine = 'm.account_id IN (:searchAccounts)';

                // THE SAME PREDICATE FOR THE OTHER ALIAS, AND IT IS NOT
                // DUPLICATION FOR ITS OWN SAKE.
                //
                // The semantic arm's candidate subquery scans `message` a
                // second time under the alias `m2`, and it used to be handed
                // $mine — which names `m`. That is legal SQL and it means
                // something entirely different: a reference to the OUTER row,
                // which correlates the subquery, and a scan of `m2` with no
                // filter on it whatsoever.
                //
                // Postgres showed both halves of the damage in one plan. The
                // account test became `One-Time Filter: (m.account_id = ANY
                // (...))` — a per-outer-row constant — over a bare
                // `Seq Scan on message m2`, and the whole Limit/Sort landed on
                // the inner side of a nested loop, so the ENTIRE message table
                // was sorted again for every candidate row being tested.
                //
                // The correctness half is the quieter one and it outlives any
                // plan: LIMIT 2000 was taking the 2,000 most recent rows in the
                // TABLE and only then filtering to this user's accounts. The
                // outer WHERE keeps that safe — nobody ever saw another
                // mailbox — but on any installation with more than one busy
                // account, most of the window was spent on mail that was
                // filtered away, and the searcher got a fraction of the 2,000
                // candidates the constant promises.
                $mineRecent = 'm2.account_id IN (:searchAccounts)';
            }

            $cheap = ["SELECT m.id FROM message m WHERE {$mine} AND m.search_vector @@ websearch_to_tsquery('english', :freeText)"];
            $ranks = ["ts_rank(m.search_vector, websearch_to_tsquery('english', :freeText))"];

            // ── What a row would have matched WITHOUT the vector ──────
            // One entry per lexical arm above, as a per-row predicate rather
            // than as an id-producing SELECT. It is the same question the UNION
            // answers, asked of a row that is already in hand: which is the
            // whole point, because asking the UNION again to find out where a
            // row came from would run every scan in it a second time, and those
            // scans are the search.
            //
            // Only ever added to the statement when there is a semantic arm to
            // attribute rows to — see below. A search with the feature off
            // produces byte-for-byte the SQL it always did.
            $lexical = ["m.search_vector @@ websearch_to_tsquery('english', :freeText)"];

            if (null !== $free->prefix) {
                $cheap[]              = "SELECT m.id FROM message m WHERE {$mine} AND m.search_vector @@ to_tsquery('english', :freePrefix)";
                $ranks[]              = "ts_rank(m.search_vector, to_tsquery('english', :freePrefix))";
                $lexical[]            = "m.search_vector @@ to_tsquery('english', :freePrefix)";
                $params['freePrefix'] = $free->prefix;
            }

            // ── The semantic arm ──────────────────────────────────────
            // The one pass that is not made of words, and the one that has to
            // be paid for by the row. Everything about what it costs and why it
            // is shaped this way is inside the branch, next to the SQL it
            // describes — this comment used to carry it, and drifted: it still
            // said 0.11ms and "an inline distance in this arm" a version after
            // both had stopped being true.
            if (null !== $semantic?->literal) {
                // ONE PLACE WHERE THE DISTANCE IS COMPUTED, AND IT IS HERE.
                //
                // This used to be an inline distance in the arm AND a second
                // one in the outer query, where a LEFT JOIN to
                // message_embedding fed plmail_embed_distance() a row at a
                // time. Two things went wrong with that, and they multiplied.
                //
                // The outer join had no candidate restriction, so it scored
                // every embedded message the statement had matched — including
                // every row a KEYWORD had found, which is most of them. And
                // DBAL defeated the sharing the old comment here relied on:
                // one `:queryVector` written twice is expanded into two
                // separate positional parameters, so what Postgres saw was two
                // textually different expressions and it evaluated both.
                // Measured on 20,000 rows of 1024-dimension vectors, a
                // 25%-selective term: 12,616 calls and 3.8 SECONDS. Production
                // ran ~92,000 calls at 0.132ms and took 12.9s mean, 47s max.
                //
                // Scored here instead, the function runs exactly
                // SEMANTIC_CANDIDATES times — 2,000 calls, 0.63s on the same
                // corpus — and `plmail_embed_distance` does not appear in the
                // outer query at all. Same rows, same provenance flags; the
                // measurement is in the commit that introduced this.
                //
                // MATERIALIZED IS LOAD-BEARING, NOT DECORATION. sem_hits is
                // referenced twice below (the UNION arm and the LEFT JOIN), and
                // an inlined CTE is re-evaluated at every reference — which is
                // the bug being fixed, reintroduced by the planner. Spelled
                // NOT MATERIALIZED the same statement measures 4,369 calls and
                // 2.2s, three and a half times the cost, for identical rows.
                //
                // TWO CTEs RATHER THAN ONE, and that is worth 369 calls. With
                // the threshold applied to a derived table inside sem_scores,
                // Postgres flattens the subquery, `similarity` lands in both
                // the filter and the target list, and every row that PASSES is
                // scored a second time. Materialising the scores and filtering
                // the materialised output is one evaluation per candidate,
                // full stop: 2,369 calls became 2,000.
                //
                // NO CALL IS MADE FROM PHP HERE. The vector arrives already
                // computed — buildSearchSql() runs up to four times per search,
                // once inside a statement-timeout transaction, and a round trip
                // to another machine in any of them would be a search that
                // sometimes takes four times as long for no reason a user could
                // see.
                //
                // BOUNDED, AND THE BOUND IS THE WHOLE DESIGN. The distance
                // function costs about 0.13ms a row at 1024 dimensions; over
                // 100,000 messages that is thirteen seconds, which is worse
                // than the pathology the UNION was written to escape. So it
                // only ever runs over the most recent SEMANTIC_CANDIDATES
                // messages, chosen by an ordering that does not change between
                // pages — a bound that moved would make LIMIT/OFFSET return the
                // same conversation twice.
                //
                // THAT BOUND WAS NOT HOLDING, AND THE ALIAS IS WHY. The
                // subquery below scans `message` again as `m2`, and it was
                // given $mine, which names `m`. Postgres read that as a
                // reference to the outer row, correlated the subquery, and put
                // the whole Limit/Sort on the inner side of a nested loop with
                // `Seq Scan on message m2` carrying no filter at all — the
                // entire table re-sorted for every candidate row tested.
                // Measured on 40,000 messages: cancelled at the 5,000 ms
                // budget, every time, which reaches the searcher as
                // SearchTooSlow. With $mineRecent it is a Hash Semi Join
                // evaluated once, 2,000 rows scored, 188 ms.
                //
                // The inner LIMIT is parenthesised because an unparenthesised
                // ORDER BY or LIMIT would bind to the wrong query level.
                // :queryVector is already a PostgreSQL literal, normalised by
                // the caller — DBAL cannot name `real[]` as a parameter type,
                // and the cast is what turns the bound string back into an
                // array. It is now bound EXACTLY ONCE in the whole statement.
                //
                // SCOPED TO ONE MODEL, AND THAT IS NOT TIDINESS. Vectors from
                // two embedding models are not comparable, and neither
                // implementation of plmail_embed_distance() says so: the
                // shipped plpgsql loop compares whatever the two arrays have in
                // common and answers a plausible number, and on an installation
                // with pgvector the cast raises `different vector dimensions` —
                // a 500 on /mail/search for everybody, caused by a dropdown in
                // the admin panel. Matching on model AND width means a mailbox
                // half re-indexed after a model change searches the half that
                // matches and ignores the rest. The index
                // idx_message_embedding_model is (model, dimensions) for this.
                $semanticCte = <<<SQL
                    WITH sem_scores AS MATERIALIZED (
                        SELECT e.message_id,
                               1 - plmail_embed_distance(e.embedding, CAST(:queryVector AS real[])) AS similarity
                          FROM message m
                          JOIN message_embedding e ON e.message_id = m.id
                                                  AND e.model = :semanticModel
                                                  AND e.dimensions = :semanticDimensions
                         WHERE {$mine}
                           AND m.id IN (
                               SELECT recent.id FROM (
                                   SELECT m2.id FROM message m2
                                    WHERE {$mineRecent}
                                    ORDER BY m2.received_at DESC NULLS LAST, m2.id DESC
                                    LIMIT :semanticCandidates
                               ) recent
                           )
                    ), sem_hits AS (
                        SELECT message_id, similarity
                          FROM sem_scores
                         WHERE similarity >= :semanticSimilarity
                         ORDER BY similarity DESC, message_id DESC
                         LIMIT :semanticHits
                    )

                    SQL;

                // An id-producing arm of the same UNION, so a vector hit is a
                // candidate on exactly the same footing as a lexical one: it
                // lands in COUNT(*) OVER (), it pages correctly, and it needs
                // no second statement to reconcile with.
                $cheap[] = 'SELECT message_id FROM sem_hits';

                // A COLUMN NOW, NOT AN EXPRESSION. Similarity, so it is on the
                // same "bigger is better" footing as ts_rank and GREATEST can
                // mix them; a message the vector did not find gives NULL here,
                // which GREATEST ignores.
                //
                // Still held in a variable because the provenance column below
                // binds the SAME text, and Postgres shares identical aggregate
                // calls — but now that is a convenience rather than the thing
                // holding the cost down. There is no arithmetic left in it to
                // share.
                $semanticRank = 'sem.similarity';

                $ranks[] = $semanticRank;

                // The arm's threshold, bound once so the arm, the rank and the
                // provenance column cannot come to disagree about what counts
                // as a hit. They used to be two comparisons — `d <= 0.45` in
                // the arm, `1 - d >= 0.55` in the column — that agreed
                // everywhere except within one ULP of the boundary.
                //
                // A SIMILARITY ALL THE WAY DOWN NOW. It arrives as one from
                // AiSettings, where an administrator reads it as "how close a
                // match has to be" on the same 0-to-1 scale the results show,
                // rather than as a distance they would have to invert in their
                // head to compare with anything.
                $params['semanticSimilarity']  = $semantic->minSimilarity;
                $params['queryVector']         = $semantic->literal;
                $params['semanticModel']       = $semantic->model;
                $params['semanticDimensions']  = $semantic->dimensions;
                $params['semanticCandidates']  = $this->semanticCandidates;
                $params['semanticHits']        = $this->semanticHits;
                $types['semanticDimensions']   = ParameterType::INTEGER;
                $types['semanticCandidates']   = ParameterType::INTEGER;
                $types['semanticHits']         = ParameterType::INTEGER;
            }

            if (null !== $free->substring) {
                // Subject and the two sender columns, but NOT the body: those
                // three are narrow, so the trigram index's recheck reads almost
                // nothing and they cost microseconds.
                foreach (['subject', 'from_name', 'from_address'] as $column) {
                    $cheap[]   = "SELECT m.id FROM message m WHERE {$mine} AND m.{$column} ILIKE :freeLike";
                    $lexical[] = "m.{$column} ILIKE :freeLike";
                }

                $params['freeLike'] = $this->freeText->likePattern($free->substring);
            }

            // ── The rescue ────────────────────────────────────────────
            // `body_text ILIKE '%needle%'` is the one branch that cannot be
            // made cheap. The trigram index is not the problem — it hands back
            // 32,440 candidates out of 300,000 in 7.6ms — the recheck is:
            // `%needle%` is lossy on trigrams, so every candidate body has to
            // be fetched and matched for real, and for a common needle that is
            // 131MB of text and 3.1 seconds. Storage layout does not move it
            // (MAIN 2.9s, EXTERNAL 3.1s), and a parallel sequential scan of
            // every body takes 2.26s — the index path is slower than reading
            // everything. There is no better index for `%needle%`.
            //
            // So it is only run when the cheap passes came back thin, which is
            // the case it was added for: a needle full-text cannot see, like
            // "wirhub" inside "help.wirhub.de". Since the tokenizer split
            // (Version20260818120000) the index answers that one directly, so
            // this is a genuine last resort rather than a trade.
            //
            // A SECOND QUERY rather than one clever statement. The single-query
            // form — a CTE counting the cheap hits and a `(SELECT few …)`
            // InitPlan gating the body scan — worked beautifully with literal
            // values, printing `One-Time Filter` and skipping the scan
            // entirely. Through DBAL's bind parameters it planned differently
            // and one term went from 13 seconds to a 30-second PHP timeout.
            // Two predictable queries beat one whose plan depends on how the
            // values arrived, and the second only runs when the first came up
            // short.
            if (true === $withBodyRescue && null !== $free->substring) {
                // Two arms, one per column and each on its own trigram index:
                // the words of an HTML-only body are in body_html_text (#477).
                $cheap[] = "SELECT m.id FROM message m WHERE {$mine} AND m.body_text ILIKE :freeLike";
                $cheap[] = "SELECT m.id FROM message m WHERE {$mine} AND m.body_html_text ILIKE :freeLike";

                // The one lexical arm whose per-row form is not free: it reads
                // the body of every row the statement is about to return, which
                // is the same detoast the pass itself pays, over the far
                // smaller set that actually matched. Left out, a message the
                // body pass found and the vector also liked would be labelled
                // "found by meaning", which is the one thing this column exists
                // not to get wrong. Only ever in the rescue statement, which is
                // rare by construction and already the expensive one.
                $lexical[] = '(m.body_text ILIKE :freeLike OR m.body_html_text ILIKE :freeLike)';
            }

            // UNION ALL, because `IN` is a semi-join and removes duplicates
            // itself. A plain UNION asked for a second, sorted de-duplication
            // first, and to feed it in id order Postgres walked message_pkey
            // and tested `@@` on every row instead of using the GIN index:
            // 0.9s per full-text arm on 200,000 messages. As UNION ALL each arm
            // is a bitmap scan again and the semi-join hashes the ids, with the
            // same rows out. A word in 8% of the mail went from 519ms to
            // 310ms, one in nearly all of it from 5.2s to 2.1s; a rare word
            // stays at a few milliseconds.
            $where[] = 'm.id IN (' . implode(' UNION ALL ', $cheap) . ')';

            // A row reached only by the substring pass has no ts_rank to give;
            // GREATEST over what did match keeps relevance ordering meaningful
            // instead of letting a NULL sink every such row to the bottom.
            //
            // GREATEST(MAX(a), MAX(b)) rather than MAX(GREATEST(a, b)), which
            // is the same number — the largest of the largests is the largest —
            // and not the same statement. Postgres shares identical aggregate
            // calls, so writing MAX(semantic) here and MAX(semantic) again in
            // the provenance column below computes the distance ONCE per row.
            // Nested inside a GREATEST the two are no longer identical calls,
            // and every candidate row would pay for the distance function
            // twice.
            if (true === $ranked) {
                $rankParts  = array_map(static fn (string $rank): string => "MAX({$rank})", $ranks);
                $rankSelect = 1 === count($rankParts) ? $rankParts[0] : 'GREATEST(' . implode(', ', $rankParts) . ')';
            }

            // ── Where did this row come from ──────────────────────────
            // One column, computed from aggregates the statement is already
            // producing: it matched the vector, and no arm made of words would
            // have found it. That distinction is the entire point — somebody
            // who typed a literal string needs to know why an apparently
            // unrelated message is in the list, and a row the words found needs
            // no explaining.
            //
            // Read per THREAD, over the messages of that thread that matched.
            // A conversation whose first message matched the words and whose
            // fourth happens to sit near the vector was found by the words, and
            // BOOL_OR over the group says so.
            //
            // No extra join and no extra scan: `sem` is already joined for the
            // rank, and the lexical predicates are re-asked of rows that are
            // already in hand rather than by running the UNION again.
            //
            // COALESCE because a lexical arm can be NULL rather than false — a
            // message with no subject ILIKEs to NULL — and BOOL_OR over nothing
            // but NULLs is NULL, which would make the CASE fall through to a
            // row claiming a provenance it never had.
            //
            // The `>= :semanticSimilarity` is now redundant on its face —
            // sem_hits contains nothing below the threshold, so a non-NULL
            // MAX() is already a hit. It is kept because it is what makes the
            // NULL case explicit: a row with no sem_hits match gives NULL, and
            // `NULL >= x` is NULL, which is what sends the CASE to 0. Written
            // as `MAX(...) IS NOT NULL` the reader would have to go and find
            // out where the threshold went.
            if (null !== $semanticRank) {
                $lexicalHit = '(' . implode(' OR ', $lexical) . ')';

                $semanticColumn = ",\n                CASE WHEN MAX({$semanticRank}) >= :semanticSimilarity"
                    . " AND NOT COALESCE(BOOL_OR({$lexicalHit}), false)"
                    . " THEN 1 ELSE 0 END AS semantic_only";
            }
        }

        // ── Operator filters ──────────────────────────────────────────────

        if ($query->from !== null) {
            // Parenthesised: this OR must not bleed into the surrounding
            // AND-joined WHERE clause when other filters are present.
            $where[]            = '(LOWER(m.from_address) LIKE :fromAddr OR LOWER(m.from_name) LIKE :fromAddr)';
            $params['fromAddr'] = '%' . strtolower($query->from) . '%';
        }

        if ($query->to !== null) {
            // to_addresses is a JSON array of {name, address} objects
            $where[]      = "m.to_addresses::text ILIKE :toAddr";
            $params['toAddr'] = '%' . $query->to . '%';
        }

        if ($query->cc !== null) {
            $where[]          = 'm.cc_addresses::text ILIKE :ccAddr';
            $params['ccAddr'] = '%' . $query->cc . '%';
        }

        if ($query->subject !== null) {
            $where[]           = 'LOWER(m.subject) LIKE :subject';
            $params['subject'] = '%' . strtolower($query->subject) . '%';
        }

        if ($query->hasAttachment === true) {
            $where[] = 'm.has_attachments = true';
        }

        if ($query->isUnread) {
            $where[] = 'm.seen_at IS NULL';
        }

        if ($query->isRead) {
            $where[] = 'm.seen_at IS NOT NULL';
        }

        if ($query->isStarred) {
            $where[] = 't.starred_at IS NOT NULL';
        }

        if ($query->after !== null) {
            $where[]          = 'm.received_at >= :after';
            $params['after']  = $query->after->format('Y-m-d H:i:s');
        }

        if ($query->before !== null) {
            $where[]           = 'm.received_at < :before';
            $params['before']  = $query->before->format('Y-m-d H:i:s');
        }

        if ($query->label !== null) {
            $where[]         = 'LOWER(lbl.name) = :labelName AND lbl.role IS NULL';
            $params['labelName'] = strtolower($query->label);
        }
        // ── Mailbox role filter ───────────────────────────────────────────
        // Already a role: the parser resolves what was typed ("junk", "bin")
        // and rejects what it cannot resolve, so a mailbox this install does
        // not have never reaches the SQL as a filter that silently matches
        // nothing — or, as it used to, a filter silently dropped.
        if ($query->mailboxRole !== null) {
            $where[]             = 'lbl.role = :labelRole';
            $params['labelRole'] = $query->mailboxRole;
        }

        // ── Trash stays in Trash ──────────────────────────────────────────
        // Search answers the mailbox somebody is looking through, and a
        // deleted conversation is not part of it until they say `in:trash` —
        // the same rule every list view applies since v0.0.25, arriving here
        // last because search builds its own SQL.
        //
        // NOT EXISTS rather than a condition on `lbl`: that alias exists once
        // per label the thread carries, so `lbl.role <> 'trash'` would keep a
        // deleted thread matching through its OTHER labels — filtering join
        // rows where the question is about the thread.
        if (LabelRole::Trash->value !== $query->mailboxRole) {
            //
            // And "deleted" is all of it, as the lists have it: a thread with
            // a live message is searched like any other (#268). The stored
            // answer, as excludeTrashed() reads it (#223).
            $where[] = 'NOT t.binned';
        }

        // ── Spam stays in Spam ────────────────────────────────────────────
        // The same rule for the same reason (#304): a search for "invoice" or
        // "DHL" turned up the forged ones beside the real ones, which is the
        // one place they do the most harm. `in:spam` asks for them. Any Spam
        // label takes the conversation out, as All Mail has it
        // (allMailQuery()), and NOT EXISTS for the reason given above.
        if (LabelRole::Spam->value !== $query->mailboxRole) {
            $where[] = 'NOT EXISTS ('
                . 'SELECT 1 FROM thread_label stl'
                . ' JOIN label slbl ON slbl.id = stl.label_id'
                . ' WHERE stl.message_thread_id = t.id AND slbl.role = :spamRole'
                . ')';
            $params['spamRole'] = LabelRole::Spam->value;
        }

        $whereClause = implode(' AND ', $where);

        // ── The label joins, only when something asks about a label ───────
        // `lbl` is referenced by exactly two filters — `label:` and the
        // mailbox role behind `in:` — and by nothing else in this statement.
        // Joined unconditionally, as they were, they are not merely idle: both
        // are to-many, so every (thread, matching message) row is multiplied by
        // the number of labels the thread carries before the GROUP BY collapses
        // it back down. A mailbox where threads sit in an inbox, a category and
        // a user label pays three times over, on every free-text search, to
        // produce a set it then throws away.
        //
        // The result was always right, which is why this survived: GROUP BY
        // hides the duplication perfectly and only the clock shows it.
        // One row per message, so this cannot multiply anything — which is the
        // property the label joins above lost and had to be given back in
        // v0.1.40. LEFT, because most messages are not vector hits and every
        // one of them still has to be able to match lexically.
        //
        // TO THE CTE, NOT TO message_embedding. This join used to reach the
        // embedding table directly with no candidate restriction, and the rank
        // above it then ran plmail_embed_distance() over every embedded message
        // the statement had matched — twice, once for `rank` and once for
        // `semantic_only`. That was the search: 12,616 calls where 2,000 were
        // needed. Joining the scored candidates instead means the outer query
        // reads a `real` column, and the only model/width matching left in the
        // statement is the one inside sem_scores, where it belongs — it guards
        // the side that FEEDS the distance function, and an unmatched row there
        // is either a meaningless number or, with pgvector installed, an error
        // that takes the whole search page with it.
        $semanticJoin = null === $semantic?->literal
            ? ''
            : "\n            LEFT JOIN sem_hits sem ON sem.message_id = m.id";

        $labelJoins = '';

        if (null !== $query->label || null !== $query->mailboxRole) {
            $labelJoins = "\n            LEFT JOIN thread_label tl ON tl.message_thread_id = t.id"
                . "\n            LEFT JOIN label lbl ON lbl.id = tl.label_id";
        }

        // The CTE rides in front of the SELECT rather than being spliced into
        // it, so a search with no vector produces byte-for-byte the statement
        // it always did. searchRows() appends ORDER BY / LIMIT / OFFSET to
        // whatever comes back, which a leading WITH does not disturb.
        $sql = $semanticCte . <<<SQL
            SELECT
                t.id                                              AS thread_id,
                {$rankSelect}                                     AS rank,
                MAX(t.last_message_at)                            AS last_message_at,
                COUNT(*) OVER ()                                  AS total_threads{$semanticColumn}
            FROM message_thread t
            JOIN message m ON m.thread_id = t.id
            JOIN account a ON a.id = t.account_id{$semanticJoin}{$labelJoins}
            WHERE {$whereClause}
            GROUP BY t.id
        SQL;

        return [$sql, $params, $types];
    }
}
