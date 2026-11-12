<?php

declare(strict_types=1);

namespace App\Repository\Mail\Account;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * The statements that remove one account's mail from this database, and only
 * from this database (#575).
 *
 * Not MessagePurger, which is the obvious thing to reuse and the one thing
 * that must not be: emptying the bin deletes on the server as well, and queues
 * exactly that (PurgeRemoteMessagesMessage). Nothing here dispatches anything
 * or calls anything that does, so it cannot reach a mail server whatever state
 * the account's switches are in. Raw DBAL for the bulk: a hundred thousand
 * rows hydrated to be removed one by one is a worker's memory spent on
 * nothing, and no listener needs to see them go. The rows that hang off a
 * thread or a message (messages, parts, labels, locations, embeddings,
 * summaries, extracted events) go with them through ON DELETE CASCADE, the same
 * foreign keys that deleting an account relies on; files on disk are left for
 * app:prune:blobs, which removes what no row points at any more.
 *
 * In batches by id rather than one DELETE per account: every removed Email and
 * Thread is also written to the JMAP change log, so the apps drop them too, and
 * a mailbox of a hundred thousand messages is not one transaction or one array.
 */
final readonly class AccountMailPurgeRepository
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * The next batch of the account's thread ids, lowest first.
     *
     * @return list<int>
     */
    public function threadIdBatch(int $accountId, int $limit): array
    {
        return array_map(intval(...), $this->connection->fetchFirstColumn(
            'SELECT id FROM message_thread WHERE account_id = ? ORDER BY id LIMIT ?',
            [$accountId, $limit],
        ));
    }

    /**
     * @param list<int> $threadIds
     *
     * @return list<int>
     */
    public function messageIdsInThreads(array $threadIds): array
    {
        if ([] === $threadIds) {
            return [];
        }

        return array_map(intval(...), $this->connection->fetchFirstColumn(
            'SELECT id FROM message WHERE thread_id IN (?)',
            [$threadIds],
            [ArrayParameterType::INTEGER],
        ));
    }

    /**
     * Messages of the account that belong to no thread — a row caught between
     * ingest and threading. Rare, and removed so "the account holds no mail"
     * is true afterwards.
     *
     * @return list<int>
     */
    public function threadlessMessageIdBatch(int $accountId, int $limit): array
    {
        return array_map(intval(...), $this->connection->fetchFirstColumn(
            'SELECT id FROM message WHERE account_id = ? AND thread_id IS NULL ORDER BY id LIMIT ?',
            [$accountId, $limit],
        ));
    }

    /**
     * @param list<int> $threadIds
     */
    public function deleteThreads(array $threadIds): int
    {
        if ([] === $threadIds) {
            return 0;
        }

        // Messages first, by thread: message.thread_id has carried ON DELETE
        // CASCADE since a later migration, but the explicit delete does not
        // depend on which constraint an old install ended up with.
        $this->connection->executeStatement(
            'DELETE FROM message WHERE thread_id IN (?)',
            [$threadIds],
            [ArrayParameterType::INTEGER],
        );

        return (int) $this->connection->executeStatement(
            'DELETE FROM message_thread WHERE id IN (?)',
            [$threadIds],
            [ArrayParameterType::INTEGER],
        );
    }

    /**
     * @param list<int> $messageIds
     */
    public function deleteMessages(array $messageIds): int
    {
        if ([] === $messageIds) {
            return 0;
        }

        return (int) $this->connection->executeStatement(
            'DELETE FROM message WHERE id IN (?)',
            [$messageIds],
            [ArrayParameterType::INTEGER],
        );
    }
}
