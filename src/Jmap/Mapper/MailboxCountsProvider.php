<?php

declare(strict_types=1);

namespace App\Jmap\Mapper;

use Doctrine\DBAL\Connection;

/**
 * Loads every Mailbox count for one account in a single grouped query.
 *
 * Deliberately DBAL rather than DQL: this is a pure aggregate over the
 * message<->label join table, nothing is hydrated, and a Mailbox/get over a
 * large label tree would otherwise be a textbook N+1.
 *
 * Unread is "seen_at IS NULL", not the absence of the \Seen entry in
 * Message::$flags. The two disagree: flags is an IMAP mirror that only the
 * plain-IMAP sync path populates, so it is a strict subset of seen_at. seen_at
 * is the field the web UI reads and writes, so it is the authoritative one.
 */
final class MailboxCountsProvider
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function forAccount(int $accountId): MailboxCounts
    {
        return new MailboxCounts(
            $this->aggregate($accountId, $this->emailSql()),
            $this->aggregate($accountId, $this->threadSql()),
        );
    }

    /**
     * @return array<int,array{total:int,unread:int}>
     */
    private function aggregate(int $accountId, string $sql): array
    {
        $rows = $this->connection
            ->executeQuery($sql, ['accountId' => $accountId])
            ->fetchAllAssociative();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row['label_id']] = [
                'total' => (int) $row['total'],
                'unread' => (int) $row['unread'],
            ];
        }

        return $counts;
    }

    private function emailSql(): string
    {
        return <<<'SQL'
            SELECT ml.label_id,
                   COUNT(*) AS total,
                   COUNT(*) FILTER (WHERE m.seen_at IS NULL) AS unread
            FROM message_label ml
            JOIN message m ON m.id = ml.message_id
            WHERE m.account_id = :accountId
            GROUP BY ml.label_id
            SQL;
    }

    /**
     * Threads are counted through the same join rather than through
     * thread_label, so both grains stay consistent by construction.
     *
     * Note this reads unreadThreads as "threads with an unread Email *in this
     * mailbox*". RFC 8621 defines it slightly more loosely (an unread Email
     * anywhere in the Thread). The stricter reading is what the plMail UI
     * shows, and it cannot exceed totalThreads, which is what clients assert.
     */
    private function threadSql(): string
    {
        return <<<'SQL'
            SELECT ml.label_id,
                   COUNT(DISTINCT m.thread_id) AS total,
                   COUNT(DISTINCT m.thread_id) FILTER (WHERE m.seen_at IS NULL) AS unread
            FROM message_label ml
            JOIN message m ON m.id = ml.message_id
            WHERE m.account_id = :accountId
              AND m.thread_id IS NOT NULL
            GROUP BY ml.label_id
            SQL;
    }
}
