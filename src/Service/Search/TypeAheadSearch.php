<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Domain\DTO\Search\TypeAheadHit;
use App\Domain\Enum\Mail\LabelRole;
use App\Entity\User\User;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\ParameterType;

/**
 * The search that runs while you are still typing.
 *
 * This is NOT a cheaper spelling of MessageThreadRepository::search(). It
 * answers a different question, and the difference is the whole design:
 *
 *   Enter asks "what matches, all of it, ranked and paged".
 *   A keystroke asks "is any of it worth stopping to look at, in ten rows,
 *   before I finish the word".
 *
 * The second question has a budget the first does not — a suggestion that
 * arrives after the next keystroke has been thrown away by definition — so
 * this runs only the passes that are cheap enough to spend on every keystroke,
 * and gives up the ones that are not. What it gives up it gives up openly:
 * pressing Enter still runs the complete search, which is where the expensive
 * passes live.
 *
 * The costs below were measured on a 300,000-message mailbox and are what every
 * decision in here is arguing with:
 *
 *   search_vector @@ to_tsquery('term:*')     ~110ms (13ms for a rare term)
 *   search_vector @@ websearch_to_tsquery()   ~ 90ms
 *   subject / from_name / from_address ILIKE  microseconds, usually
 *   body_text ILIKE '%term%'                  3,100ms   ← never, not here
 *
 * The body pass is not omitted for tidiness. `%needle%` is lossy on trigrams,
 * so every candidate body has to be re-read and re-matched, and on a common
 * needle that is 131MB of text — three seconds, per keystroke, for rows the
 * reader has already typed past. It stays in the full search, where a person
 * has committed to waiting.
 */
final class TypeAheadSearch
{
    /**
     * Ten rows is the list; these are the rows the SQL fetches to fill it.
     *
     * More than ten, because two things thin the candidates out after the
     * database has ranked them: several messages in one conversation collapse
     * to one row, and deleted conversations are dropped. Four times over is
     * enough that both have to be unusually unlucky to leave the list short,
     * and short is survivable — this is a preview, not the answer.
     */
    private const int CANDIDATE_FACTOR = 4;

    /**
     * A circuit breaker, not a target.
     *
     * Every pass in here is meant to answer in a fraction of this. The point is
     * that a type-ahead query is issued by a keypress, so a pathological one —
     * a mailbox shaped unlike anything measured, a plan that flipped — must not
     * be able to hold a connection while the user keeps typing more of them. On
     * timeout the box simply shows no suggestions, which is what it shows for a
     * word nobody has written to you anyway.
     */
    private const int STATEMENT_TIMEOUT_MS = 1500;

    public function __construct(
        private readonly Connection       $connection,
        private readonly FreeTextCompiler $freeText = new FreeTextCompiler(),
    ) {
    }

    /**
     * @return list<TypeAheadHit>
     */
    public function suggest(User $user, string $text, int $limit = 10): array
    {
        $free = $this->freeText->compile($text);

        if ('' === $free->websearch) {
            return [];
        }

        // Bound as VALUES, and this is the single most expensive line in the
        // file to get wrong. Left as `account_id IN (SELECT id FROM account
        // WHERE usr_id = …)` the planner cannot estimate how many accounts come
        // back, reaches for the account_id index, and on a single-user install
        // that selects everything: 2,326ms per branch with the GIN index
        // untouched, against 126ms when it is handed the ids. Measured, twice,
        // and reintroduced once in between.
        $accountIds = $this->activeAccountIdsFor($user);

        if ([] === $accountIds) {
            return [];
        }

        $candidates = $limit * self::CANDIDATE_FACTOR;

        $params = [
            'accounts'   => $accountIds,
            'candidates' => $candidates,
        ];
        $types = [
            'accounts'   => ArrayParameterType::INTEGER,
            'candidates' => ParameterType::INTEGER,
        ];

        $branches = [];

        if (null !== $free->prefix) {
            // The prefix form only. `'invoice':*` subsumes what
            // websearch_to_tsquery('invoice') matches — to_tsquery stems the
            // quoted lexeme exactly as websearch does, then widens it — so
            // running both would buy the same rows for a second ~110ms scan of
            // the same index, which is more than half the budget for nothing.
            //
            // The exception is a term the compiler's sanitiser changed, where
            // the two queries genuinely differ. That is what Enter is for: the
            // full search still runs both passes.
            $branches[]         = "m.search_vector @@ to_tsquery('english', :prefix)";
            $params['prefix']   = $free->prefix;
        } else {
            // No prefix means the text used websearch syntax — a quoted phrase,
            // OR, a negation — and those mean what they say. Widening them is
            // the opposite of what they were typed for, so this is the one pass
            // that can answer.
            $branches[]         = "m.search_vector @@ websearch_to_tsquery('english', :websearch)";
            $params['websearch'] = $free->websearch;
        }

        if (null !== $free->substring) {
            // Subject and the two sender columns, and NOT body_text. These
            // three are narrow, so the trigram index's recheck reads almost
            // nothing; the body is where that same pattern costs three seconds.
            foreach (['subject', 'from_name', 'from_address'] as $column) {
                $branches[] = "m.{$column} ILIKE :needle";
            }

            $params['needle'] = $this->freeText->likePattern($free->substring);
        }

        $rows = $this->fetch($this->buildSql($branches), $params, $types);

        return $this->toHits($rows, $limit);
    }

    /**
     * One SELECT per pass, UNION ALL, each already cut to the candidates it can
     * contribute.
     *
     * Two things here are load-bearing and both were arrived at from a plan.
     *
     * The columns are selected INSIDE each branch rather than the branches
     * producing ids for an outer `WHERE m.id IN (…)`. The id form reads well
     * and plans terribly: Postgres hash-joins the id set back against the
     * message table and gets there by SEQUENTIALLY SCANNING all 300,000 rows —
     * 533ms for a common term, of which 93ms was the scan that the union had
     * already made unnecessary.
     *
     * Every branch carries its own ORDER BY and LIMIT, and the ORDER BY is what
     * keeps the plan honest. Without it the planner sees a bare LIMIT, decides
     * a sequential scan will stumble over 40 matching rows soon enough, and
     * abandons the index — which is true for a word in 8% of the mail and a
     * catastrophe for a word in none of it, where it reads the whole table to
     * find that out. Sorted, every branch is a bitmap index scan with a top-N
     * heapsort on top, and the cost tracks how rare the word is instead of
     * which side of a planner's guess it fell on.
     *
     * @param list<string> $branches
     */
    private function buildSql(array $branches): string
    {
        $selects = [];

        foreach ($branches as $predicate) {
            $selects[] = <<<SQL
                (SELECT m.thread_id, m.subject, m.from_name, m.from_address, m.received_at
                 FROM message m
                 WHERE m.account_id IN (:accounts) AND {$predicate}
                 ORDER BY m.received_at DESC NULLS LAST
                 LIMIT :candidates)
            SQL;
        }

        $union = implode(' UNION ALL ', $selects);

        return <<<SQL
            SELECT hits.thread_id, hits.subject, hits.from_name, hits.from_address, hits.received_at
            FROM ({$union}) hits
            ORDER BY hits.received_at DESC NULLS LAST
            LIMIT :candidates
        SQL;
    }

    /**
     * @param array<string,mixed> $params
     * @param array<string,mixed> $types
     *
     * @return list<array<string,mixed>>
     */
    private function fetch(string $sql, array $params, array $types): array
    {
        try {
            /** @var list<array<string,mixed>> $rows */
            $rows = $this->connection->transactional(
                function (Connection $connection) use ($sql, $params, $types): array {
                    // SET LOCAL, so it is undone with the transaction. A plain
                    // SET would leave the timeout on a pooled connection and
                    // hand it to whatever ran next — a sync writing a thousand
                    // messages does not deserve a type-ahead's patience.
                    $connection->executeStatement(
                        'SET LOCAL statement_timeout = ' . self::STATEMENT_TIMEOUT_MS,
                    );

                    return $connection->fetchAllAssociative($sql, $params, $types);
                },
            );

            return $rows;
        } catch (DriverException) {
            // Including the timeout above. A dropdown that fails to appear is a
            // dropdown that has not appeared yet, as far as anyone typing can
            // tell; a 500 from a keystroke is a broken search box.
            return [];
        }
    }

    /**
     * Rows to conversations: one row per thread, deleted ones dropped.
     *
     * The trash filter is a second query over the forty candidates rather than
     * a NOT EXISTS inside the search, because inside it the label lookup runs
     * once per matching MESSAGE — twenty thousand probes for a common word, to
     * decide the fate of ten rows. Out here it is one indexed read of a list
     * this long.
     *
     * @param list<array<string,mixed>> $rows
     *
     * @return list<TypeAheadHit>
     */
    private function toHits(array $rows, int $limit): array
    {
        if ([] === $rows) {
            return [];
        }

        $threadIds = array_values(array_unique(array_map(
            static fn (array $row): int => (int) $row['thread_id'],
            $rows,
        )));

        $trashed = array_flip($this->trashedAmong($threadIds));

        $hits = [];
        $seen = [];

        foreach ($rows as $row) {
            $threadId = (int) $row['thread_id'];

            if (isset($seen[$threadId]) || isset($trashed[$threadId])) {
                continue;
            }

            $seen[$threadId] = true;

            $hits[] = new TypeAheadHit(
                threadId:    $threadId,
                subject:     null !== $row['subject'] ? (string) $row['subject'] : null,
                fromName:    null !== $row['from_name'] ? (string) $row['from_name'] : null,
                fromAddress: null !== $row['from_address'] ? (string) $row['from_address'] : null,
                receivedAt:  null !== $row['received_at']
                    ? new \DateTimeImmutable((string) $row['received_at'])
                    : null,
            );

            if (count($hits) >= $limit) {
                break;
            }
        }

        return $hits;
    }

    /**
     * @param list<int> $threadIds
     *
     * @return list<int>
     */
    private function trashedAmong(array $threadIds): array
    {
        /** @var list<int|string> $ids */
        $ids = $this->connection->fetchFirstColumn(
            <<<SQL
                SELECT DISTINCT tl.message_thread_id
                FROM thread_label tl
                JOIN label l ON l.id = tl.label_id
                WHERE tl.message_thread_id IN (:threads) AND l.role = :trash
            SQL,
            ['threads' => $threadIds, 'trash' => LabelRole::Trash->value],
            ['threads' => ArrayParameterType::INTEGER],
        );

        return array_map(intval(...), $ids);
    }

    /**
     * @return list<int>
     */
    private function activeAccountIdsFor(User $user): array
    {
        /** @var list<int|string> $ids */
        $ids = $this->connection->fetchFirstColumn(
            'SELECT id FROM account WHERE usr_id = ? AND is_active = true',
            [$user->id],
        );

        return array_map(intval(...), $ids);
    }
}
