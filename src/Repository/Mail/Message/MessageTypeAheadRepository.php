<?php

declare(strict_types=1);

namespace App\Repository\Mail\Message;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\ParameterType;

/**
 * The SQL of the search that runs while somebody is still typing: one cheap
 * pass per kind of match, each cut to the candidates it can contribute, under
 * a statement timeout of its own. TypeAheadSearch decides which passes run and
 * turns the rows into suggestions.
 */
final readonly class MessageTypeAheadRepository
{
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
        private Connection $connection,
    ) {
    }

    /**
     * The newest candidate rows across these accounts: a prefix match when
     * there is a prefix, the websearch match otherwise, and the subject and
     * sender columns when there is a substring pattern.
     *
     * @param non-empty-list<int> $accountIds
     *
     * @return list<array<string,mixed>>
     */
    public function findCandidates(array $accountIds, ?string $prefix, string $websearch, ?string $likePattern, int $candidates): array
    {
        $params = [
            'accounts'   => $accountIds,
            'candidates' => $candidates,
        ];
        $types = [
            'accounts'   => ArrayParameterType::INTEGER,
            'candidates' => ParameterType::INTEGER,
        ];

        $branches = [];

        if (null !== $prefix) {
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
            $params['prefix']   = $prefix;
        } else {
            // No prefix means the text used websearch syntax — a quoted phrase,
            // OR, a negation — and those mean what they say. Widening them is
            // the opposite of what they were typed for, so this is the one pass
            // that can answer.
            $branches[]         = "m.search_vector @@ websearch_to_tsquery('english', :websearch)";
            $params['websearch'] = $websearch;
        }

        if (null !== $likePattern) {
            // Subject and the two sender columns, and NOT body_text. These
            // three are narrow, so the trigram index's recheck reads almost
            // nothing; the body is where that same pattern costs three seconds.
            foreach (['subject', 'from_name', 'from_address'] as $column) {
                $branches[] = "m.{$column} ILIKE :needle";
            }

            $params['needle'] = $likePattern;
        }

        return $this->fetch($this->buildSql($branches), $params, $types);
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
}
