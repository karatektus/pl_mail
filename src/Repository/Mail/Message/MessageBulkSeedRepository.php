<?php

declare(strict_types=1);

namespace App\Repository\Mail\Message;

use Doctrine\DBAL\Connection;

/**
 * The writes app:test:seed-bulk makes: hundreds of thousands of rows, a chunk
 * at a time, into one account of its own.
 *
 * Raw SQL and one INSERT per chunk, deliberately. The ORM would need an entity,
 * a UnitOfWork slot and a flush per message; at this size that is the
 * difference between seconds and an afternoon, and none of what it buys —
 * events, threading, label derivation — is what a plan measurement is about.
 */
final readonly class MessageBulkSeedRepository
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * Run $work in one transaction on this connection, rolled back if it throws.
     *
     * @param callable(): void $work
     */
    public function inTransaction(callable $work): void
    {
        $this->connection->transactional(static fn () => $work());
    }

    /**
     * @param non-empty-list<list<mixed>> $rows account_id, subject, normalized_subject, threading_method, last_message_at,
     *                                          unread_count, message_count, attachment_count, category, listed_at
     */
    public function insertThreads(array $rows): void
    {
        $this->insertRows(
            'message_thread',
            'account_id, subject, normalized_subject, threading_method, last_message_at,'
            . ' unread_count, message_count, attachment_count, category, listed_at',
            $rows,
        );
    }

    /**
     * The newest thread ids of an account, as many as were just inserted.
     *
     * @return list<int>
     */
    public function latestThreadIds(int $accountId, int $count): array
    {
        return array_map(intval(...), $this->connection->fetchFirstColumn(
            'SELECT id FROM message_thread WHERE account_id = ? ORDER BY id DESC LIMIT ?',
            [$accountId, $count],
        ));
    }

    /**
     * Put one label on every one of these threads.
     *
     * @param non-empty-list<int> $threadIds
     */
    public function labelThreads(array $threadIds, int $labelId): void
    {
        $this->insertRows(
            'thread_label',
            'message_thread_id, label_id',
            array_map(static fn (int $id): array => [$id, $labelId], $threadIds),
            ' ON CONFLICT DO NOTHING',
        );
    }

    /**
     * @param non-empty-list<list<mixed>> $rows account_id, thread_id, subject, from_address, from_name, to_addresses,
     *                                          body_text, flags, has_attachments, cancelled, sent_at, received_at,
     *                                          seen_at, created_at, updated_at
     */
    public function insertMessages(array $rows): void
    {
        $this->insertRows(
            'message',
            'account_id, thread_id, subject, from_address, from_name, to_addresses, body_text,'
            . ' flags, has_attachments, cancelled, sent_at, received_at, seen_at, created_at, updated_at',
            $rows,
        );
    }

    /** Everything the seeder has made on its own account, and nothing else. */
    public function wipeAccount(int $accountId): void
    {
        $this->connection->executeStatement('DELETE FROM message WHERE account_id = ?', [$accountId]);
        $this->connection->executeStatement('DELETE FROM message_thread WHERE account_id = ?', [$accountId]);
    }

    /**
     * Statistics, or every plan drawn afterwards is drawn against an empty
     * table's guesses.
     */
    public function analyze(): void
    {
        $this->connection->executeStatement('ANALYZE message');
        $this->connection->executeStatement('ANALYZE message_thread');
        $this->connection->executeStatement('ANALYZE thread_label');
    }

    /** @param non-empty-list<list<mixed>> $rows */
    private function insertRows(string $table, string $columns, array $rows, string $suffix = ''): void
    {
        $placeholders = '(' . implode(', ', array_fill(0, count($rows[0]), '?')) . ')';

        $this->connection->executeStatement(
            sprintf('INSERT INTO %s (%s) VALUES %s%s', $table, $columns, implode(', ', array_fill(0, count($rows), $placeholders)), $suffix),
            array_merge(...$rows),
        );
    }
}
