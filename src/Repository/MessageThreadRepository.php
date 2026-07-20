<?php

namespace App\Repository;

use App\Domain\DTO\ParsedSearchQuery;
use App\Domain\Enum\LabelRole;
use App\Domain\Enum\MailboxSpecialUse;
use App\Domain\Enum\MessageTab;
use App\Entity\Account;
use App\Entity\Label;
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
            ->join('t.labels', 'l')
            ->where('a.usr = :user')
            ->andWhere('a.isActive = true')
            ->andWhere('l.role = :inbox')
            ->andWhere('t.tab = :tab')
            ->setParameter('user', $user)
            ->setParameter('inbox', LabelRole::Inbox)
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
            ->join('t.labels', 'l')
            ->where('a.usr = :user')
            ->andWhere('a.isActive = true')
            ->andWhere('l.role = :inbox')
            ->andWhere('t.tab = :tab')
            ->setParameter('user', $user)
            ->setParameter('inbox', LabelRole::Inbox)
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
            ->join('t.labels', 'l')
            ->where('a.usr = :user')
            ->andWhere('a.isActive = true')
            ->andWhere('l.role = :inbox')
            ->andWhere('t.unreadCount > 0')
            ->groupBy('t.tab')
            ->setParameter('user', $user)
            ->setParameter('inbox', LabelRole::Inbox)
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

    public function findForRole(UserInterface $user, LabelRole $role, int $page = 1, int $perPage = 50): array
    {
        $offset = ($page - 1) * $perPage;

        return $this->createQueryBuilder('t')
            ->join('t.account', 'a')
            ->join('t.labels', 'l')
            ->where('a.usr = :user')
            ->andWhere('a.isActive = true')
            ->andWhere('l.role = :role')
            ->setParameter('user', $user)
            ->setParameter('role', $role)
            ->orderBy('t.lastMessageAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($perPage)
            ->distinct()
            ->getQuery()
            ->getResult();
    }
    public function countForRole(UserInterface $user, LabelRole $role): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(DISTINCT t.id)')
            ->join('t.account', 'a')
            ->join('t.labels', 'l')
            ->where('a.usr = :user')
            ->andWhere('a.isActive = true')
            ->andWhere('l.role = :role')
            ->setParameter('user', $user)
            ->setParameter('role', $role)
            ->getQuery()
            ->getSingleScalarResult();
    }
    public function findForLabel(Label $label, int $page = 1, int $perPage = 50): array
    {
        $offset = ($page - 1) * $perPage;

        return $this->createQueryBuilder('t')
            ->join('t.labels', 'l')
            ->where('l = :label')
            ->setParameter('label', $label)
            ->orderBy('t.lastMessageAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    public function countForLabel(Label $label): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->join('t.labels', 'l')
            ->where('l = :label')
            ->setParameter('label', $label)
            ->getQuery()
            ->getSingleScalarResult();
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
    /**
     * @return array<string,int> role value → unread thread-message sum
     */
    public function countUnreadPerRole(UserInterface $user): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('l.role AS role', 'SUM(t.unreadCount) AS unreadCount')
            ->join('t.account', 'a')
            ->join('t.labels', 'l')
            ->where('a.usr = :user')
            ->andWhere('a.isActive = true')
            ->andWhere('l.role IS NOT NULL')
            ->setParameter('user', $user)
            ->groupBy('l.role')
            ->getQuery()
            ->getArrayResult();

        $counts = [];

        foreach ($rows as $row) {
            $role = $row['role'];

            if ($role instanceof LabelRole) {
                $role = $role->value;
            }

            $counts[(string) $role] = (int) $row['unreadCount'];
        }

        return $counts;
    }

    /**
     * @return array<int,int> label id → unread thread-message sum
     */
    public function countUnreadPerUserLabel(UserInterface $user): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('l.id AS labelId', 'SUM(t.unreadCount) AS unreadCount')
            ->join('t.account', 'a')
            ->join('t.labels', 'l')
            ->where('a.usr = :user')
            ->andWhere('a.isActive = true')
            ->andWhere('l.role IS NULL')
            ->setParameter('user', $user)
            ->groupBy('l.id')
            ->getQuery()
            ->getArrayResult();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row['labelId']] = (int) $row['unreadCount'];
        }

        return $counts;
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
     * Threads carrying ANY of the given labels — the merged path-based label
     * view aggregating same-named labels across accounts.
     *
     * @param Label[] $labels
     * @return MessageThread[]
     */
    public function findForLabels(array $labels, int $page, int $perPage = 50): array
    {
        return $this->createQueryBuilder('thread')
            ->innerJoin('thread.labels', 'label')
            ->where('label IN (:labels)')
            ->setParameter('labels', $labels)
            ->groupBy('thread.id')
            ->orderBy('thread.lastMessageAt', 'DESC')
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery()
            ->getResult();
    }

    /**
     * @param Label[] $labels
     */
    public function countForLabels(array $labels): int
    {
        return (int) $this->createQueryBuilder('thread')
            ->select('COUNT(DISTINCT thread.id)')
            ->innerJoin('thread.labels', 'label')
            ->where('label IN (:labels)')
            ->setParameter('labels', $labels)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Initialize the labels collection of every given thread in ONE query so
     * the list view's label chips don't lazy-load per row. Fetch-joining onto
     * already-managed entities marks their collections initialized.
     *
     * @param MessageThread[] $threads
     */
    public function preloadLabels(array $threads): void
    {
        if (count($threads) === 0) {
            return;
        }

        $this->createQueryBuilder('thread')
            ->addSelect('label')
            ->leftJoin('thread.labels', 'label')
            ->where('thread IN (:threads)')
            ->setParameter('threads', $threads)
            ->getQuery()
            ->getResult();
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

        if ($query->label !== null) {
            $where[]         = 'LOWER(lbl.name) = :labelName AND lbl.role IS NULL';
            $params['labelName'] = strtolower($query->label);
        }
        // ── Mailbox role filter ───────────────────────────────────────────
        $roleMap = [
            'inbox'   => 'inbox',
            'sent'    => 'sent',
            'drafts'  => 'drafts',
            'draft'   => 'drafts',
            'trash'   => 'trash',
            'junk'    => 'spam',
            'spam'    => 'spam',
        ];

        if ($query->mailboxRole !== null && true === isset($roleMap[$query->mailboxRole])) {
            $where[]        = 'lbl.role = :labelRole';
            $params['labelRole'] = $roleMap[$query->mailboxRole];
        }

        $whereClause = implode(' AND ', $where);

        if ($countOnly) {
            $sql = <<<SQL
                SELECT COUNT(DISTINCT t.id)
                FROM message_thread t
                JOIN message m ON m.thread_id = t.id
                JOIN account a ON a.id = t.account_id
                LEFT JOIN thread_label tl ON tl.message_thread_id = t.id
                LEFT JOIN label lbl ON lbl.id = tl.label_id
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
           JOIN account a ON a.id = t.account_id
           LEFT JOIN thread_label tl ON tl.message_thread_id = t.id
           LEFT JOIN label lbl ON lbl.id = tl.label_id
            WHERE {$whereClause}
            GROUP BY t.id
        SQL;

        return [$sql, $params, $types];
    }
}
