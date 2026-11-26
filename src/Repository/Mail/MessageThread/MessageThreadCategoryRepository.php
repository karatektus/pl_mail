<?php

declare(strict_types=1);

namespace App\Repository\Mail\MessageThread;

use App\Domain\Enum\Mail\MessageCategory;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * A thread's inbox category, worked out again from its messages, and pins to a
 * category released.
 */
class MessageThreadCategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MessageThread::class);
    }

    /**
     * The same resolution as recomputeCategoriesForAccount(), for a handful of
     * threads rather than a mailbox.
     *
     * That one is right for a re-sort and wrong for a batch: it resolves every
     * conversation the account has, which is the correct shape once and a
     * scan of a hundred thousand rows if it runs after each batch of arriving
     * mail. This is the same statement with the threads named, so the plan is
     * bounded by the batch instead of by the mailbox.
     *
     * `category_pinned_at IS NULL` is carried over verbatim, and it is the part
     * that must not be lost in the narrowing: a conversation somebody dragged
     * onto a tab keeps it, whatever any model or rule now says.
     *
     * @param list<int> $threadIds
     *
     * @return array<int, list<int>> account id => the conversations that
     *                               changed tab, for the JMAP change log
     */
    public function recomputeCategoriesForThreads(array $threadIds): array
    {
        if ([] === $threadIds) {
            return [];
        }

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            <<<'SQL'
            UPDATE message_thread t
            SET category = sub.category,
                -- The reader's own tab, resolved by the same newest-message
                -- rule in the same pass — and to NULL when that message was
                -- filed nowhere, which is how a conversation leaves it. See
                -- MessageThread::$userCategory.
                user_category_id = sub.rule_category_id,
                category_decided_at = sub.decided_at
            FROM (
                -- The filter's verdict as it stands, on Gmail mail too: a filter
                -- the reader wrote outranks Google's categories whatever they
                -- told plMail about Google's own sorting.
                SELECT DISTINCT ON (m.thread_id) m.thread_id, m.category, m.rule_category_id,
                       CASE WHEN own.filed THEN NULL ELSE COALESCE(m.received_at, m.sent_at, m.created_at) END AS decided_at
                FROM message m
                CROSS JOIN LATERAL (
                    -- Your own mail: filed under Sent or Drafts. Message::isFiledAsSent().
                    SELECT EXISTS (SELECT 1 FROM message_label ml JOIN label l ON l.id = ml.label_id
                                   WHERE ml.message_id = m.id AND l.role IN ('sent', 'drafts')) AS filed
                ) own
                WHERE m.category IS NOT NULL
                  -- A message held for the assistant has no say yet: its
                  -- category is the rules' guess, and adopting it is the
                  -- flicker holding exists to prevent. Message::$categoryHeldAt.
                  AND (m.category_held_at IS NULL OR m.category_released_at IS NOT NULL)
                  AND m.thread_id IN (:threadIds)
                -- Newest RECEIVED message first; your own mail only when the
                -- conversation has nothing else. MessageThread::$categoryDecidedAt.
                ORDER BY m.thread_id, own.filed ASC, COALESCE(m.received_at, m.sent_at, m.created_at) DESC
            ) sub
            WHERE t.id = sub.thread_id
              AND t.category_pinned_at IS NULL
              -- Only what moves: a phone is told about every row returned. The
              -- instant can move without the tab — a newer message filed the
              -- same way — and is written then too, so that the threader
              -- weighs the next arrival against the right message; those few
              -- conversations changed anyway, a message just joined them.
              AND (t.category IS DISTINCT FROM sub.category
                   OR t.user_category_id IS DISTINCT FROM sub.rule_category_id
                   OR t.category_decided_at IS DISTINCT FROM sub.decided_at)
            RETURNING t.id, t.account_id
            SQL,
            ['threadIds' => $threadIds],
            ['threadIds' => ArrayParameterType::INTEGER],
        );

        $changed = [];

        foreach ($rows as $row) {
            $changed[(int) $row['account_id']][] = (int) $row['id'];
        }

        return $changed;
    }

    /**
     * Resolve every thread's category from its own messages, most-recent-wins,
     * in one statement, for one account.
     *
     * Raw SQL because DISTINCT ON is a Postgres extension DQL has no form of,
     * and because this is an UPDATE ... FROM over a derived table: the ORM's
     * write path is per-entity, so the alternative is loading every thread and
     * its messages to compute in PHP what the database can answer in one pass.
     *
     * Threads whose category was chosen by hand are excluded, which is the same
     * rule MessageThreader::adoptCategory() applies one row at a time. It has
     * to be stated in both places: the backfill does not go through the
     * threader, so a run of `app:backfill category` would otherwise wipe every
     * category anybody had ever dragged a conversation into — the whole install
     * at once, which is worse than the arrival-time case the flag was written
     * for.
     *
     * RETURNS THE CONVERSATIONS THAT MOVED, and only writes those. A re-sort
     * used to rewrite every row with its own value and tell nobody, so a JMAP
     * client went on drawing a conversation in the tab it had left until
     * something else happened to it. The caller records these.
     *
     * @return list<int>
     */
    public function recomputeCategoriesForAccount(Account $account): array
    {
        $sql = <<<'SQL'
        UPDATE message_thread t
        SET category = sub.category,
            user_category_id = sub.rule_category_id,
            category_decided_at = sub.decided_at
        FROM (
            SELECT DISTINCT ON (m.thread_id) m.thread_id, m.category, m.rule_category_id,
                   CASE WHEN own.filed THEN NULL ELSE COALESCE(m.received_at, m.sent_at, m.created_at) END AS decided_at
            FROM message m
            CROSS JOIN LATERAL (
                -- Your own mail: filed under Sent or Drafts. Message::isFiledAsSent().
                SELECT EXISTS (SELECT 1 FROM message_label ml JOIN label l ON l.id = ml.label_id
                               WHERE ml.message_id = m.id AND l.role IN ('sent', 'drafts')) AS filed
            ) own
            WHERE m.category IS NOT NULL
              AND (m.category_held_at IS NULL OR m.category_released_at IS NOT NULL)
              -- This account's conversations only. Without it the DISTINCT ON
              -- sorted every message on the installation, every user's, once
              -- per account re-sorted — and switching a tab on or off re-sorts.
              AND m.thread_id IN (SELECT id FROM message_thread WHERE account_id = :accountId)
            ORDER BY m.thread_id, own.filed ASC, COALESCE(m.received_at, m.sent_at, m.created_at) DESC
        ) sub
        WHERE t.id = sub.thread_id
          AND t.account_id = :accountId
          AND t.category_pinned_at IS NULL
          AND (t.category IS DISTINCT FROM sub.category
               OR t.user_category_id IS DISTINCT FROM sub.rule_category_id
               OR t.category_decided_at IS DISTINCT FROM sub.decided_at)
        RETURNING t.id
        SQL;

        return array_map(
            intval(...),
            $this->getEntityManager()->getConnection()->fetchFirstColumn($sql, ['accountId' => $account->id]),
        );
    }

    /**
     * Release this reader's conversations pinned to a built-in category, before
     * that category is switched off.
     *
     * The recompute statements skip a pinned conversation, so one dragged into
     * a category that is then switched off would never be resolved again. Only
     * conversations shown in that category: one in a tab of the reader's own
     * keeps its pin, since that tab is still there.
     */
    public function unpinFromCategory(\App\Entity\User\User $user, MessageCategory $category): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            <<<'SQL'
            UPDATE message_thread t
            SET category_pinned_at = NULL
            FROM account a
            WHERE a.id = t.account_id
              AND a.usr_id = :user
              AND t.category = :category
              AND t.user_category_id IS NULL
              AND t.category_pinned_at IS NOT NULL
            SQL,
            ['user' => $user->id, 'category' => $category->value],
        );
    }

    /**
     * The threads filed under a user's category, as ids.
     *
     * @return list<int>
     */
    public function findIdsInUserCategory(int $categoryId): array
    {
        return array_map(
            intval(...),
            $this->getEntityManager()->getConnection()->fetchFirstColumn(
                'SELECT id FROM message_thread WHERE user_category_id = :id',
                ['id' => $categoryId],
            ),
        );
    }

    /** Release every pin into a user's category, ahead of the category going. */
    public function unpinFromUserCategory(int $categoryId): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE message_thread SET category_pinned_at = NULL WHERE user_category_id = :id',
            ['id' => $categoryId],
        );
    }
}
