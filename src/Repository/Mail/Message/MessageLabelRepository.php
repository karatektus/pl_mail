<?php

declare(strict_types=1);

namespace App\Repository\Mail\Message;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Messages by label: per-label totals and the keyset walks a label's folder or a
 * relabelling pass goes through.
 */
class MessageLabelRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Message::class);
    }

    /**
     * Per-label message totals and unread counts for one account, in one
     * grouped query — the numbers behind a Mailbox/get.
     *
     * DBAL rather than DQL: this is a pure aggregate over the message↔label
     * join table, nothing is hydrated, and counting per label through the ORM
     * over a large label tree is a textbook N+1.
     *
     * Unread is "seen_at IS NULL", not the absence of the \Seen entry in
     * Message::$flags. The two disagree: flags is an IMAP mirror that only the
     * plain-IMAP sync path populates, so it is a strict subset of seen_at.
     * seen_at is the field the web UI reads and writes, so it is authoritative.
     *
     * $labelIds narrows the aggregate to those labels, for a Mailbox/get that
     * named its ids; null counts every label of the account.
     *
     * @param list<int>|null $labelIds
     *
     * @return array<int,array{total:int,unread:int}> label id => counts
     */
    public function countEmailsPerLabelForAccount(int $accountId, ?array $labelIds = null): array
    {
        return $this->labelCounts($accountId, $labelIds, <<<'SQL'
            SELECT ml.label_id,
                   COUNT(*) AS total,
                   COUNT(*) FILTER (WHERE m.seen_at IS NULL) AS unread
            FROM message_label ml
            JOIN message m ON m.id = ml.message_id
            WHERE m.account_id = :accountId
              %s
            GROUP BY ml.label_id
            SQL);
    }

    /**
     * The same per label, counting distinct threads instead of messages.
     *
     * Counted through message_label rather than thread_label so both grains
     * stay consistent by construction — a thread is in a mailbox exactly when
     * one of its messages is.
     *
     * Note this reads unreadThreads as "threads with an unread Email *in this
     * mailbox*". RFC 8621 defines it slightly more loosely (an unread Email
     * anywhere in the Thread). The stricter reading is what the plMail UI
     * shows, and it cannot exceed totalThreads, which is what clients assert.
     *
     * @param list<int>|null $labelIds as countEmailsPerLabelForAccount()
     *
     * @return array<int,array{total:int,unread:int}> label id => counts
     */
    public function countThreadsPerLabelForAccount(int $accountId, ?array $labelIds = null): array
    {
        return $this->labelCounts($accountId, $labelIds, <<<'SQL'
            SELECT ml.label_id,
                   COUNT(DISTINCT m.thread_id) AS total,
                   COUNT(DISTINCT m.thread_id) FILTER (WHERE m.seen_at IS NULL) AS unread
            FROM message_label ml
            JOIN message m ON m.id = ml.message_id
            WHERE m.account_id = :accountId
              AND m.thread_id IS NOT NULL
              %s
            GROUP BY ml.label_id
            SQL);
    }

    /**
     * @param list<int>|null $labelIds
     *
     * @return array<int,array{total:int,unread:int}>
     */
    private function labelCounts(int $accountId, ?array $labelIds, string $sql): array
    {
        if ([] === $labelIds) {
            return [];
        }

        $parameters = ['accountId' => $accountId];
        $types      = [];
        $narrow     = '';

        if (null !== $labelIds) {
            $narrow               = 'AND ml.label_id IN (:labelIds)';
            $parameters['labelIds'] = $labelIds;
            $types['labelIds']      = ArrayParameterType::INTEGER;
        }

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(sprintf($sql, $narrow), $parameters, $types);
        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row['label_id']] = [
                'total'  => (int) $row['total'],
                'unread' => (int) $row['unread'],
            ];
        }

        return $counts;
    }

    /**
     * Ids of one account's messages carrying one label, by keyset.
     *
     * For re-tagging after an Exchange master category is renamed: Exchange
     * stores a category on each message as a string, so the messages have to be
     * pushed again or they go on carrying the old name. See
     * ApplyLabelStructureHandler.
     *
     * Ids rather than entities, and keyset rather than OFFSET: the population is
     * "every message that has ever had this label", which on a busy mailbox is
     * unbounded, and both hydrating it and paging it by offset degrade as it
     * grows. The caller walks with the last id it saw.
     *
     * Only messages the provider knows about — a message with no graphId has
     * never been at Exchange and there is nothing there to re-tag.
     *
     * @return list<int>
     */
    public function findIdsWithLabelForAccount(
        int $accountId,
        int $labelId,
        int $afterId = 0,
        int $limit = 200,
    ): array {
        $rows = $this->createQueryBuilder('m')
            ->select('m.id')
            ->innerJoin('m.labels', 'l')
            ->where('m.account = :account')
            ->andWhere('l.id = :label')
            ->andWhere('m.id > :afterId')
            ->andWhere('m.graphId IS NOT NULL')
            ->setParameter('account', $accountId)
            ->setParameter('label', $labelId)
            ->setParameter('afterId', $afterId)
            ->orderBy('m.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    /**
     * The mail a label's new folder takes in on one account: what wears the
     * label, less what is in the bin, in spam, or sent or drafted, which is not
     * being filed by giving a tag a folder.
     *
     * @return list<int>
     */
    public function findIdsToFileIntoFolder(int $accountId, int $labelId, int $afterId = 0, int $limit = 100): array
    {
        $rows = $this->toFileIntoFolder($accountId, $labelId)
            ->select('m.id')
            ->andWhere('m.id > :afterId')
            ->setParameter('afterId', $afterId)
            ->orderBy('m.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    public function countToFileIntoFolder(int $accountId, int $labelId): int
    {
        return (int) $this->toFileIntoFolder($accountId, $labelId)
            ->select('COUNT(m.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function toFileIntoFolder(int $accountId, int $labelId): QueryBuilder
    {
        return $this->createQueryBuilder('m')
            ->innerJoin('m.labels', 'l')
            ->where('m.account = :account')
            ->andWhere('l.id = :label')
            ->andWhere('NOT EXISTS (SELECT 1 FROM ' . Message::class . ' stays JOIN stays.labels staysIn WHERE stays = m AND staysIn.role IN (:staying))')
            ->setParameter('account', $accountId)
            ->setParameter('label', $labelId)
            ->setParameter('staying', [LabelRole::Trash, LabelRole::Spam, LabelRole::Sent, LabelRole::Drafts]);
    }

    /**
     * Ids of one account's messages carrying one label, by keyset — every one
     * of them, not only those Exchange knows.
     *
     * For emptying a folder-backed label before it is deleted: the walk is
     * findIdsWithLabelForAccount()'s, without its graphId filter, because the
     * mail that has to leave an IMAP folder never had a graphId at all.
     *
     * @return list<int>
     */
    public function findAllIdsWithLabelForAccount(
        int $accountId,
        int $labelId,
        int $afterId = 0,
        int $limit = 200,
    ): array {
        $rows = $this->createQueryBuilder('m')
            ->select('m.id')
            ->innerJoin('m.labels', 'l')
            ->where('m.account = :account')
            ->andWhere('l.id = :label')
            ->andWhere('m.id > :afterId')
            ->setParameter('account', $accountId)
            ->setParameter('label', $labelId)
            ->setParameter('afterId', $afterId)
            ->orderBy('m.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    /**
     * How many of one account's messages carry one label.
     *
     * What the delete dialog asks before it offers a destination: a folder
     * with nothing in it on this account needs no move.
     */
    public function countWithLabelForAccount(int $accountId, int $labelId): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->innerJoin('m.labels', 'l')
            ->where('m.account = :account')
            ->andWhere('l.id = :label')
            ->setParameter('account', $accountId)
            ->setParameter('label', $labelId)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
