<?php

namespace App\Repository;

use App\Domain\DTO\ParsedSearchQuery;
use App\Domain\Enum\MailboxSpecialUse;
use App\Domain\Enum\MessageTab;
use App\Entity\Account;
use App\Entity\MessageThread;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\User\UserInterface;

class MessageThreadRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MessageThread::class);
    }

    public function findMatchingNormalizedSubjectThreadForAccount(string $normalizedSubject, Account $account): ?MessageThread
    {
        return $this->createQueryBuilder('thread')
            ->where('thread.account = :account')
            ->andWhere('thread.normalizedSubject = :normalizedSubject')
            ->setParameter('account', $account)
            ->setParameter('normalizedSubject', $normalizedSubject)
            ->orderBy('thread.lastMessageAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findForUnifiedInbox(UserInterface $user, MessageTab $tab, int $page = 1, int $perPage = 50): array
    {
        $offset = ($page - 1) * $perPage;

        return $this->createQueryBuilder('t')
            ->join('t.account', 'a')
            ->join('t.mailboxes', 'm')
            ->where('a.usr = :user')
            ->andWhere('a.isActive = true')
            ->andWhere('m.specialUse = :inbox')
            ->andWhere('t.tab = :tab')
            ->setParameter('user', $user)
            ->setParameter('inbox', '\\Inbox')
            ->setParameter('tab', $tab)
            ->orderBy('t.lastMessageAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($perPage)
            ->distinct()
            ->getQuery()
            ->getResult();
    }

    public function countForUnifiedInbox(UserInterface $user, MessageTab $tab): int
    {
        return $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->join('t.account', 'a')
            ->join('t.mailboxes', 'm')
            ->where('a.usr = :user')
            ->andWhere('a.isActive = true')
            ->andWhere('m.specialUse = :inbox')
            ->andWhere('t.tab = :tab')
            ->setParameter('user', $user)
            ->setParameter('inbox', '\\Inbox')
            ->setParameter('tab', $tab)
            ->distinct()
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countUnreadByTabForUnifiedInbox(UserInterface $user): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('t.tab AS tab', 'COUNT(DISTINCT t.id) AS unreadCount')
            ->join('t.account', 'a')
            ->join('t.mailboxes', 'm')
            ->where('a.usr = :user')
            ->andWhere('a.isActive = true')
            ->andWhere('m.specialUse = :inbox')
            ->andWhere('t.unreadCount > 0')
            ->groupBy('t.tab')
            ->setParameter('user', $user)
            ->setParameter('inbox', '\\Inbox')
            ->getQuery()
            ->getResult();

        $counts = [];

        foreach ($rows as $row) {
            $tabValue = $row['tab'];

            if ($tabValue instanceof MessageTab) {
                $tabValue = $tabValue->value;
            }

            $counts[$tabValue] = (int) $row['unreadCount'];
        }

        return $counts;
    }

    public function findForStarred(UserInterface $user, int $page = 1, int $perPage = 50): array
    {
        $offset = ($page - 1) * $perPage;

        return $this->createQueryBuilder('t')
            ->join('t.account', 'a')
            ->where('a.usr = :user')
            ->andWhere('a.isActive = true')
            ->andWhere('t.starredAt IS NOT NULL')
            ->setParameter('user', $user)
            ->orderBy('t.lastMessageAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    public function countForStarred(UserInterface $user): int
    {
        return $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->join('t.account', 'a')
            ->where('a.usr = :user')
            ->andWhere('a.isActive = true')
            ->andWhere('t.starredAt IS NOT NULL')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findForSpecialUse(UserInterface $user, MailboxSpecialUse $specialUse, int $page = 1, int $perPage = 50): array
    {
        $offset = ($page - 1) * $perPage;

        return $this->createQueryBuilder('t')
            ->join('t.account', 'a')
            ->join('t.mailboxes', 'm')
            ->where('a.usr = :user')
            ->andWhere('a.isActive = true')
            ->andWhere('m.specialUse = :specialUse')
            ->setParameter('user', $user)
            ->setParameter('specialUse', $specialUse)
            ->orderBy('t.lastMessageAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($perPage)
            ->distinct()
            ->getQuery()
            ->getResult();
    }

    public function countForSpecialUse(UserInterface $user, MailboxSpecialUse $specialUse): int
    {
        return $this->createQueryBuilder('t')
            ->select('COUNT(DISTINCT t.id)')
            ->join('t.account', 'a')
            ->join('t.mailboxes', 'm')
            ->where('a.usr = :user')
            ->andWhere('a.isActive = true')
            ->andWhere('m.specialUse = :specialUse')
            ->setParameter('user', $user)
            ->setParameter('specialUse', $specialUse)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countUnreadPerSpecialUse(UserInterface $user): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('m.specialUse AS specialUse', 'COUNT(DISTINCT t.id) AS unreadCount')
            ->join('t.account', 'a')
            ->join('t.mailboxes', 'm')
            ->where('a.usr = :user')
            ->andWhere('a.isActive = true')
            ->andWhere('t.unreadCount > 0')
            ->andWhere('m.specialUse IS NOT NULL')
            ->groupBy('m.specialUse')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();

        $counts = [];

        foreach ($rows as $row) {
            $counts[$row['specialUse']->value] = $row['unreadCount'];
        }

        return $rows;
    }

    public function countUnreadForStarred(UserInterface $user): int
    {
        return $this->createQueryBuilder('t')
            ->select('COUNT(DISTINCT t.id)')
            ->join('t.account', 'a')
            ->where('a.usr = :user')
            ->andWhere('a.isActive = true')
            ->andWhere('t.starredAt IS NOT NULL')
            ->andWhere('t.unreadCount > 0')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }


    /**
     * Full-text + operator search across messages for a given user.
     * Returns hydrated MessageThread entities ordered by relevance then date.
     *
     * Uses raw DBAL SQL because:
     *  - websearch_to_tsquery / @@ / ts_rank are not native DQL functions
     *  - We need DISTINCT ON which DQL cannot express
     */
    public function search(
        UserInterface     $user,
        ParsedSearchQuery $query,
        int               $page = 1,
        int               $perPage = 50,
    ): array {
        $offset = ($page - 1) * $perPage;

        [$sql, $params, $types] = $this->buildSearchSql($user, $query, false);

        $sql .= ' ORDER BY rank DESC, last_message_at DESC LIMIT :limit OFFSET :offset';
        $params['limit']  = $perPage;
        $params['offset'] = $offset;
        $types['limit']   = ParameterType::INTEGER;
        $types['offset']  = ParameterType::INTEGER;

        $conn = $this->getEntityManager()->getConnection();
        $rows = $conn->fetchAllAssociative($sql, $params, $types);

        if (empty($rows)) {
            return [];
        }

        $ids = array_column($rows, 'thread_id');

        // Hydrate via Doctrine so we get full entities (same as other finders)
        $threads = $this->createQueryBuilder('t')
            ->where('t.id IN (:ids)')
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();

        // Re-order to match relevance order from SQL
        $indexed = [];
        foreach ($threads as $thread) {
            $indexed[$thread->getId()] = $thread;
        }

        $ordered = [];
        foreach ($ids as $id) {
            if (isset($indexed[$id])) {
                $ordered[] = $indexed[$id];
            }
        }

        return $ordered;
    }

    public function countSearch(
        UserInterface     $user,
        ParsedSearchQuery $query,
    ): int {
        [$sql, $params, $types] = $this->buildSearchSql($user, $query, true);

        $conn = $this->getEntityManager()->getConnection();

        return (int) $conn->fetchOne($sql, $params, $types);
    }

    /**
     * @return array{string, array<string,mixed>, array<string,mixed>}
     */
    private function buildSearchSql(
        UserInterface     $user,
        ParsedSearchQuery $query,
        bool              $countOnly,
    ): array {
        $params = [];
        $types  = [];
        $where  = ['a.usr_id = :userId', 'a.is_active = true'];

        $params['userId'] = $user->getId();
        $types['userId']  = ParameterType::INTEGER;

        // ── Free-text via tsvector ────────────────────────────────────────
        $rankExpr = '0';

        if ($query->freeText !== '') {
            $where[]            = "m.search_vector @@ websearch_to_tsquery('english', :freeText)";
            $params['freeText'] = $query->freeText;
            $rankExpr           = "ts_rank(m.search_vector, websearch_to_tsquery('english', :freeText))";
        }

        // ── Operator filters ──────────────────────────────────────────────

        if ($query->from !== null) {
            $where[]          = 'LOWER(m.from_address) LIKE :fromAddr OR LOWER(m.from_name) LIKE :fromAddr';
            $params['fromAddr'] = '%' . strtolower($query->from) . '%';
        }

        if ($query->to !== null) {
            // to_addresses is a JSON array of {name, address} objects
            $where[]      = "m.to_addresses::text ILIKE :toAddr";
            $params['toAddr'] = '%' . $query->to . '%';
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

        // ── Mailbox role filter ───────────────────────────────────────────
        $roleMap = [
            'inbox'   => '\\\\Inbox',
            'sent'    => '\\\\Sent',
            'drafts'  => '\\\\Drafts',
            'draft'   => '\\\\Drafts',
            'trash'   => '\\\\Trash',
            'archive' => '\\\\Archive',
            'junk'    => '\\\\Junk',
            'spam'    => '\\\\Junk',
        ];

        if ($query->mailboxRole !== null && isset($roleMap[$query->mailboxRole])) {
            $where[]              = 'mb.special_use = :specialUse';
            $params['specialUse'] = $roleMap[$query->mailboxRole];
        }

        $whereClause = implode(' AND ', $where);

        if ($countOnly) {
            $sql = <<<SQL
                SELECT COUNT(DISTINCT t.id)
                FROM message_thread t
                JOIN message m ON m.thread_id = t.id
                JOIN mailbox mb ON mb.id = m.mailbox_id
                JOIN account a ON a.id = mb.account_id
                WHERE {$whereClause}
            SQL;

            return [$sql, $params, $types];
        }

        $sql = <<<SQL
            SELECT
                t.id                                              AS thread_id,
                MAX({$rankExpr})                                  AS rank,
                MAX(t.last_message_at)                            AS last_message_at
            FROM message_thread t
            JOIN message m ON m.thread_id = t.id
            JOIN mailbox mb ON mb.id = m.mailbox_id
            JOIN account a ON a.id = mb.account_id
            WHERE {$whereClause}
            GROUP BY t.id
        SQL;

        return [$sql, $params, $types];
    }
}
