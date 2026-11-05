<?php

declare(strict_types=1);

namespace App\Service\Search;

use App\Domain\Helper\ReadableBody;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Indexes the messages whose search_vector is still NULL, newest first.
 *
 * Version20261105120000 turned search_vector from a generated column into one
 * a trigger keeps, so that the migration would not rewrite the table, and
 * every row it found started out NULL. This is what fills them: from the
 * schedule (`app:search:index-pending`, a short pass every minute) and from
 * `app:backfill html-body-text` for an administrator who wants it done now.
 * NULL is the cursor, so a pass that is cut off loses nothing — the next one
 * starts where the rows say, not where a counter does.
 *
 * NEWEST FIRST, because that is what people search for. A mailbox of a
 * hundred thousand messages takes a while, and while it runs, last week's
 * mail being findable matters more than 2014's. Until a row is reached it is
 * not found by full-text; the substring pass still finds it.
 *
 * PER ROW, TWO THINGS:
 *
 *   1. body_html_text, for mail sent as HTML alone (#477) — the same test as
 *      ReadableBody::htmlWords(), whose answer is written, and only for rows
 *      that do not have one yet. The bodies are read for those rows only; the
 *      rest are not even detoasted.
 *   2. search_vector set to an empty vector. That is not the value it ends up
 *      with: it is what tells the trigger to build it. The trigger leaves a
 *      NULL row alone on any other update, because a vector built by an
 *      unrelated update — a flag, a move — would be built without
 *      body_html_text and would take the row off this list for good.
 *
 * The embedding of a row whose body_html_text was written here is dropped: it
 * was made from the subject and sender alone, and the embedding passes only
 * look for mail that has none. The nightly sweep and the next search make it
 * again (see EmbeddingCatchUp). A thread summary needs nothing; it is seen to
 * be out of date the next time the thread is opened.
 *
 * FOR UPDATE SKIP LOCKED, so two passes at once — the schedule and an
 * administrator's backfill — share the work instead of waiting on each other,
 * and a sync holding a row it is writing is simply passed by until next time.
 */
final readonly class SearchIndexRebuilder
{
    /**
     * The rows whose HTML has to be read: no body_html_text yet, an HTML body,
     * and a text part that does not hold the words — none, nothing but
     * whitespace (`!~ '[^[:space:]]'`), or Graph's preview. octet_length()
     * reads a TOASTed value's size without fetching it. The PHP side decides
     * the rest; this only has to be no narrower than it.
     */
    private const string NEEDS_WORDS = <<<'SQL'
        (m.body_html_text IS NULL
         AND octet_length(coalesce(m.body_html_safe, m.body_html, '')) > 0
         AND (m.graph_id IS NOT NULL OR m.body_text IS NULL OR m.body_text !~ '[^[:space:]]'))
        SQL;

    /** @param int $batchSize rows per transaction; a parameter so a test can watch the order */
    public function __construct(
        private Connection $connection,
        private int        $batchSize = 500,
    ) {}

    /** How many messages are still waiting. One probe of a partial index once there are none. */
    public function pending(): int
    {
        return (int) $this->connection->fetchOne('SELECT count(*) FROM message WHERE search_vector IS NULL');
    }

    /**
     * Index waiting messages until there are none or the time is up.
     *
     * The budget is checked between batches, so a pass runs over it by at most
     * one batch.
     *
     * @param float|null                  $seconds  null: until there are none
     * @param (callable(int): void)|null  $progress told how many rows each batch did
     *
     * @return array{indexed: int, filled: int} rows indexed, and of those how many got body_html_text
     */
    public function rebuild(?float $seconds = null, ?callable $progress = null): array
    {
        $deadline = null === $seconds ? null : microtime(true) + $seconds;
        $indexed  = 0;
        $filled   = 0;

        do {
            [$batch, $words] = $this->batch();

            $indexed += $batch;
            $filled  += $words;

            if (null !== $progress && $batch > 0) {
                $progress($batch);
            }
        } while ($batch > 0 && (null === $deadline || microtime(true) < $deadline));

        return ['indexed' => $indexed, 'filled' => $filled];
    }

    /** @return array{int, int} rows indexed, rows given body_html_text */
    private function batch(): array
    {
        $this->connection->beginTransaction();

        try {
            /** @var list<array{id: int|string, needs_words: bool, graph_id: ?string, body_text: ?string, body_html: ?string, body_html_safe: ?string}> $rows */
            $rows = $this->connection->fetchAllAssociative(
                'SELECT m.id, m.graph_id, ' . self::NEEDS_WORDS . ' AS needs_words,'
                . ' CASE WHEN ' . self::NEEDS_WORDS . ' THEN m.body_text END AS body_text,'
                . ' CASE WHEN ' . self::NEEDS_WORDS . ' THEN m.body_html END AS body_html,'
                . ' CASE WHEN ' . self::NEEDS_WORDS . ' THEN m.body_html_safe END AS body_html_safe'
                . ' FROM message m WHERE m.search_vector IS NULL'
                . ' ORDER BY m.id DESC LIMIT :limit FOR UPDATE SKIP LOCKED',
                ['limit' => $this->batchSize],
                ['limit' => ParameterType::INTEGER],
            );

            $filled = [];
            $plain  = [];

            foreach ($rows as $row) {
                $id    = (int) $row['id'];
                $words = true === $row['needs_words']
                    ? ReadableBody::htmlWords($row['body_text'], $row['body_html'], $row['body_html_safe'], null !== $row['graph_id'])
                    : null;

                if (null === $words) {
                    $plain[] = $id;

                    continue;
                }

                $this->connection->executeStatement(
                    "UPDATE message SET body_html_text = :words, search_vector = ''::tsvector WHERE id = :id",
                    ['words' => $words, 'id' => $id],
                    ['id' => ParameterType::INTEGER],
                );

                $filled[] = $id;
            }

            if ([] !== $plain) {
                $this->connection->executeStatement(
                    "UPDATE message SET search_vector = ''::tsvector WHERE id IN (:ids)",
                    ['ids' => $plain],
                    ['ids' => ArrayParameterType::INTEGER],
                );
            }

            if ([] !== $filled) {
                $this->connection->executeStatement(
                    'DELETE FROM message_embedding WHERE message_id IN (:ids)',
                    ['ids' => $filled],
                    ['ids' => ArrayParameterType::INTEGER],
                );
            }

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();

            throw $e;
        }

        return [count($rows), count($filled)];
    }
}
