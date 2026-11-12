<?php

declare(strict_types=1);

namespace App\Repository\Mail\Message;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * The rows SearchIndexRebuilder fills: messages whose search_vector is still
 * NULL, and the writes that hand them to the trigger.
 *
 * Raw DBAL on the connection, because the batch has to lock its rows (FOR
 * UPDATE SKIP LOCKED) inside a transaction the rebuilder holds open while it
 * works out each row's words.
 */
final readonly class MessageSearchIndexRepository
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

    public function __construct(
        private Connection $connection,
    ) {
    }

    public function countPending(): int
    {
        return (int) $this->connection->fetchOne('SELECT count(*) FROM message WHERE search_vector IS NULL');
    }

    /**
     * Run $work in one transaction on this connection, rolled back if it throws.
     *
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    public function inTransaction(callable $work): mixed
    {
        $this->connection->beginTransaction();

        try {
            $result = $work();
            $this->connection->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->connection->rollBack();

            throw $e;
        }
    }

    /**
     * The newest pending rows, locked for this transaction and skipping any
     * another pass holds. The bodies come back only for rows that need their
     * words read; the rest are not even detoasted.
     *
     * @param list<int> $except rows this pass already failed to index
     *
     * @return list<array{id: int|string, graph_id: ?string, needs_words: bool, body_text: ?string, body_html: ?string, body_html_safe: ?string}>
     */
    public function lockPendingBatch(int $limit, array $except = []): array
    {
        /** @var list<array{id: int|string, graph_id: ?string, needs_words: bool, body_text: ?string, body_html: ?string, body_html_safe: ?string}> */
        return $this->connection->fetchAllAssociative(
            'SELECT m.id, m.graph_id, ' . self::NEEDS_WORDS . ' AS needs_words,'
            . ' CASE WHEN ' . self::NEEDS_WORDS . ' THEN m.body_text END AS body_text,'
            . ' CASE WHEN ' . self::NEEDS_WORDS . ' THEN m.body_html END AS body_html,'
            . ' CASE WHEN ' . self::NEEDS_WORDS . ' THEN m.body_html_safe END AS body_html_safe'
            . ' FROM message m WHERE m.search_vector IS NULL'
            . ([] === $except ? '' : ' AND m.id NOT IN (:except)')
            . ' ORDER BY m.id DESC LIMIT :limit FOR UPDATE SKIP LOCKED',
            ['limit' => $limit] + ([] === $except ? [] : ['except' => $except]),
            ['limit' => ParameterType::INTEGER] + ([] === $except ? [] : ['except' => ArrayParameterType::INTEGER]),
        );
    }

    /** Store an HTML-only message's words and hand the row to the trigger. */
    public function fillWords(int $id, string $words): void
    {
        $this->connection->executeStatement(
            "UPDATE message SET body_html_text = :words, search_vector = ''::tsvector WHERE id = :id",
            ['words' => $words, 'id' => $id],
            ['id' => ParameterType::INTEGER],
        );
    }

    /**
     * Hand these rows to the trigger as they are.
     *
     * @param non-empty-list<int> $ids
     */
    public function markForIndexing(array $ids): void
    {
        $this->connection->executeStatement(
            "UPDATE message SET search_vector = ''::tsvector WHERE id IN (:ids)",
            ['ids' => $ids],
            ['ids' => ArrayParameterType::INTEGER],
        );
    }
}
