<?php

namespace App\Repository\Mail;

use App\Domain\DTO\ParsedSearchQuery;
use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\ListSortOrder;
use App\Domain\Enum\Mail\MessageCategory;
use App\Domain\Enum\Mail\SearchSortOrder;
use App\Entity\Mail\Account;
use App\Entity\Label\Label;
use App\Entity\Mail\MessageThread;
use App\Service\Search\FreeTextCompiler;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\User\UserInterface;

class MessageThreadRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly FreeTextCompiler $freeText = new FreeTextCompiler(),
    ) {
        parent::__construct($registry, MessageThread::class);
    }

    public function findOneByProviderThreadKeyForAccount(string $providerThreadKey, Account $account): ?MessageThread
    {
        return $this->findOneBy([
            'account'           => $account,
            'providerThreadKey' => $providerThreadKey,
        ]);
    }

    /**
     * Newest thread with this subject that is still recent enough to be a
     * plausible parent. The $since bound is what stops a recurring subject
     * ("Your order has shipped") from accreting into one endless thread.
     *
     * QueryBuilder because of that bound: findOneBy() compares fields to
     * values, and `lastMessageAt >= :since` is a comparison it cannot state.
     */
    public function findMatchingNormalizedSubjectThreadForAccount(
        string             $normalizedSubject,
        Account            $account,
        \DateTimeImmutable $since,
    ): ?MessageThread
    {
        return $this->createQueryBuilder('thread')
            ->where('thread.account = :account')
            ->andWhere('thread.normalizedSubject = :normalizedSubject')
            ->andWhere('thread.lastMessageAt >= :since')
            ->setParameter('account', $account)
            ->setParameter('normalizedSubject', $normalizedSubject)
            ->setParameter('since', $since)
            ->orderBy('thread.lastMessageAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * The inbox as one list across every active account.
     *
     * QueryBuilder because the filter spans three entities: the owner is on
     * Account, the Inbox role is on a Label reached through a to-many, and only
     * the category is on the thread itself. findBy() filters on fields of one
     * entity, so none of the two joins — nor the DISTINCT they make necessary —
     * is available to it.
     */
    public function findForUnifiedInbox(UserInterface $user, MessageCategory $category, int $page = 1, int $perPage = 50, ListSortOrder $sort = ListSortOrder::Newest): array
    {
        $offset = ($page - 1) * $perPage;

        $qb = $this->createQueryBuilder('t')
            ->join('t.account', 'a')
            ->join('t.labels', 'l')
            ->where('a.usr = :user')
            ->andWhere('a.isActive = true')
            ->andWhere('l.role = :inbox')
            ->andWhere('t.category = :category')
            ->setParameter('user', $user)
            ->setParameter('inbox', LabelRole::Inbox)
            ->setParameter('category', $category)
            ->setFirstResult($offset)
            ->setMaxResults($perPage)
            ->distinct();

        $this->excludeTrashed($qb);

        $sort->applyTo($qb);

        return $qb->getQuery()->getResult();
    }

    /** Same two joins as findForUnifiedInbox(), so the same reason to keep it. */
    public function countForUnifiedInbox(UserInterface $user, MessageCategory $category): int
    {
        return $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->join('t.account', 'a')
            ->join('t.labels', 'l')
            ->where('a.usr = :user')
            ->andWhere('a.isActive = true')
            ->andWhere('l.role = :inbox')
            ->andWhere('t.category = :category')
            ->setParameter('user', $user)
            ->setParameter('inbox', LabelRole::Inbox)
            ->setParameter('category', $category)
            ->distinct()
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Unread threads per category in one grouped read.
     *
     * A GROUP BY with an aggregate, which Doctrine's API has no form of at all
     * — count() answers one number, and asking it per category would be one
     * query per tab on every page load.
     */
    /**
     * Threads per category regardless of read state, same grouped shape as the
     * unread count below. This one decides which tabs exist at all: a category
     * with fifty read promotions still deserves its tab, so visibility cannot
     * be derived from the unread numbers.
     *
     * @return array<string, int>
     */
    public function countByCategoryForUnifiedInbox(UserInterface $user): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('t.category AS category', 'COUNT(DISTINCT t.id) AS threadCount')
            ->join('t.account', 'a')
            ->join('t.labels', 'l')
            ->where('a.usr = :user')
            ->andWhere('a.isActive = true')
            ->andWhere('l.role = :inbox')
            ->groupBy('t.category')
            ->setParameter('user', $user)
            ->setParameter('inbox', LabelRole::Inbox)
            ->getQuery()
            ->getResult();

        $counts = [];

        foreach ($rows as $row) {
            $categoryValue = $row['category'];

            if ($categoryValue instanceof MessageCategory) {
                $categoryValue = $categoryValue->value;
            }

            $counts[$categoryValue] = (int) $row['threadCount'];
        }

        return $counts;
    }

    public function countUnreadByCategoryForUnifiedInbox(UserInterface $user): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('t.category AS category', 'COUNT(DISTINCT t.id) AS unreadCount')
            ->join('t.account', 'a')
            ->join('t.labels', 'l')
            ->where('a.usr = :user')
            ->andWhere('a.isActive = true')
            ->andWhere('l.role = :inbox')
            ->andWhere('t.unreadCount > 0')
            ->groupBy('t.category')
            ->setParameter('user', $user)
            ->setParameter('inbox', LabelRole::Inbox)
            ->getQuery()
            ->getResult();

        $counts = [];

        foreach ($rows as $row) {
            $categoryValue = $row['category'];

            if ($categoryValue instanceof MessageCategory) {
                $categoryValue = $categoryValue->value;
            }

            $counts[$categoryValue] = (int) $row['unreadCount'];
        }

        return $counts;
    }

    /**
     * One system mailbox across every active account. Joined for the same
     * reason findForUnifiedInbox() is: the role lives on a Label the thread
     * reaches through a to-many, and the owner on the Account.
     */
    public function findForRole(UserInterface $user, LabelRole $role, int $page = 1, int $perPage = 50, ListSortOrder $sort = ListSortOrder::Newest): array
    {
        $offset = ($page - 1) * $perPage;

        $qb = $this->createQueryBuilder('t')
            ->join('t.account', 'a')
            ->join('t.labels', 'l')
            ->where('a.usr = :user')
            ->andWhere('a.isActive = true')
            ->andWhere('l.role = :role')
            ->setParameter('user', $user)
            ->setParameter('role', $role)
            ->setFirstResult($offset)
            ->setMaxResults($perPage)
            ->distinct();

        // Every system view except the bin itself. Archive and Sent are the
        // ones this matters most for: trashing a sent message left it listed
        // under Sent, because trashing does not take Sent away.
        if (LabelRole::Trash !== $role) {
            $this->excludeTrashed($qb);
        }

        $sort->applyTo($qb);

        return $qb->getQuery()->getResult();
    }
    /** Same joins as findForRole(). */
    public function countForRole(UserInterface $user, LabelRole $role): int
    {
        $qb = $this->createQueryBuilder('t')
            ->select('COUNT(DISTINCT t.id)')
            ->join('t.account', 'a')
            ->join('t.labels', 'l')
            ->where('a.usr = :user')
            ->andWhere('a.isActive = true')
            ->andWhere('l.role = :role')
            ->setParameter('user', $user)
            ->setParameter('role', $role);

        if (LabelRole::Trash !== $role) {
            $this->excludeTrashed($qb);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
    /**
     * Threads carrying a label, optionally narrowed to one account.
     *
     * Labels are user-scoped and can be bound to several accounts at once, so
     * a label on its own spans every account that has it. That is right for the
     * sidebar's own label list — one "Receipts" across the whole mailbox — and
     * wrong under an account, where the same entry is read as "this account's
     * Receipts" and returned everybody's.
     *
     * QueryBuilder because the label is a to-many association: findBy() has no
     * way to say "carries this label".
     */
    public function findForLabel(Label $label, ?Account $account = null, int $page = 1, int $perPage = 50, ListSortOrder $sort = ListSortOrder::Newest): array
    {
        $offset = ($page - 1) * $perPage;

        $qb = $this->createQueryBuilder('t')
            ->join('t.labels', 'l')
            ->where('l = :label')
            ->setParameter('label', $label)
            ->setFirstResult($offset)
            ->setMaxResults($perPage);

        $this->narrowToAccount($qb, $account);
        $this->excludeTrashedUnlessBin($qb, $label);

        $sort->applyTo($qb);

        return $qb->getQuery()->getResult();
    }

    /** Same to-many filter as findForLabel(). */
    public function countForLabel(Label $label, ?Account $account = null): int
    {
        $qb = $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->join('t.labels', 'l')
            ->where('l = :label')
            ->setParameter('label', $label);

        $this->narrowToAccount($qb, $account);
        $this->excludeTrashedUnlessBin($qb, $label);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * The exclusion, skipped for the bin's own row.
     *
     * findForLabel() serves two lists that look unrelated and are not: the
     * sidebar's custom labels, and the per-account folder rows, which are built
     * from the labels bound to that account and therefore include its Trash
     * folder. Excluding trashed threads unconditionally would render that
     * folder permanently empty.
     */
    private function excludeTrashedUnlessBin(QueryBuilder $qb, Label $label): void
    {
        if (LabelRole::Trash === $label->role) {
            return;
        }

        $this->excludeTrashed($qb);
    }

    /**
     * Everything in one account, whatever it is labelled — except what has been
     * thrown away.
     *
     * QueryBuilder rather than findBy() now, and that is the whole reason for
     * the change: "not in the bin" is a condition on a to-many, which findBy()
     * cannot state. This is the account row in the sidebar, and it was the
     * loudest version of the bug — an "everything" list is exactly where a
     * month of deleted mail piles up.
     */
    public function findForAccount(Account $account, int $page = 1, int $perPage = 50, ListSortOrder $sort = ListSortOrder::Newest): array
    {
        $qb = $this->createQueryBuilder('t')
            ->where('t.account = :account')
            ->setParameter('account', $account)
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        $this->excludeTrashed($qb);

        $sort->applyTo($qb);

        return $qb->getQuery()->getResult();
    }

    public function countForAccount(Account $account): int
    {
        $qb = $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.account = :account')
            ->setParameter('account', $account);

        $this->excludeTrashed($qb);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** No-op when no account was asked for, so callers need no branch. */
    private function narrowToAccount(QueryBuilder $qb, ?Account $account): void
    {
        if (null === $account) {
            return;
        }

        $qb->andWhere('t.account = :account')->setParameter('account', $account);
    }

    /**
     * "…and it is not in the bin."
     *
     * Gmail's rule, and the one users expect: a trashed conversation shows in
     * Trash and nowhere else. plMail could not express that before, because
     * every list here filters on thread_label and a thread's labels are the
     * union of its messages' labels (ThreadLabelSynchronizer). Trashing keeps
     * whatever custom labels a message already carried — ThreadStatusUpdater
     * removes Inbox and nothing else — so a thread labelled "Receipts" and
     * then deleted still matched `findForLabel(Receipts)` and sat in the list
     * looking live. Opening it from there is how someone edits a conversation
     * they believe they threw away a month ago.
     *
     * A NOT IN over a sub-query rather than a second join with a negation: the
     * label join is to-many, so `l.role <> :trash` would only say "this thread
     * has SOME label that is not Trash", which every trashed thread also
     * satisfies. The question is about the thread, so it has to be asked of
     * the thread.
     *
     * Deliberately not applied to the Trash view itself, nor to the per-account
     * Trash folder row — see the callers, each of which decides. Applying it
     * everywhere would empty the one list that is supposed to be full.
     */
    private function excludeTrashed(QueryBuilder $qb): void
    {
        $qb->andWhere(
            $qb->expr()->notIn(
                't.id',
                'SELECT binned.id FROM ' . MessageThread::class . ' binned'
                    . ' JOIN binned.labels binnedLabel'
                    . ' WHERE binnedLabel.role = :trashRole',
            ),
        )->setParameter('trashRole', LabelRole::Trash);
    }
    /**
     * QueryBuilder for the join to Account: both the owner and whether the
     * account is still active live there, and neither is a field of the thread.
     */
    public function findForStarred(UserInterface $user, int $page = 1, int $perPage = 50, ListSortOrder $sort = ListSortOrder::Newest): array
    {
        $offset = ($page - 1) * $perPage;

        $qb = $this->createQueryBuilder('t')
            ->join('t.account', 'a')
            ->where('a.usr = :user')
            ->andWhere('a.isActive = true')
            ->andWhere('t.starredAt IS NOT NULL')
            ->setParameter('user', $user)
            ->setFirstResult($offset)
            ->setMaxResults($perPage);

        $this->excludeTrashed($qb);

        $sort->applyTo($qb);

        return $qb->getQuery()->getResult();
    }

    /** Same join as findForStarred(). */
    public function countForStarred(UserInterface $user): int
    {
        $qb = $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->join('t.account', 'a')
            ->where('a.usr = :user')
            ->andWhere('a.isActive = true')
            ->andWhere('t.starredAt IS NOT NULL')
            ->setParameter('user', $user);

        $this->excludeTrashed($qb);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
    /**
     * Grouped SUM per system role — an aggregate over a join, which is two
     * things Doctrine's API cannot do and one query instead of a role's worth.
     *
     * @return array<string,int> role value → unread thread-message sum
     */
    public function countUnreadPerRole(UserInterface $user): array
    {
        $qb = $this->createQueryBuilder('t')
            ->select('l.role AS role', 'SUM(t.unreadCount) AS unreadCount')
            ->join('t.account', 'a')
            ->join('t.labels', 'l')
            ->where('a.usr = :user')
            ->andWhere('a.isActive = true')
            ->andWhere('l.role IS NOT NULL')
            ->setParameter('user', $user)
            ->groupBy('l.role');

        // The badge has to agree with the list under it, so a trashed thread
        // stops counting towards Archive or Sent — but the Trash row is one of
        // the groups being summed here, and it must keep counting its own.
        // Hence the OR rather than a plain exclusion.
        $qb->andWhere(
            $qb->expr()->orX(
                'l.role = :trashRole',
                $qb->expr()->notIn(
                    't.id',
                    'SELECT binned.id FROM ' . MessageThread::class . ' binned'
                        . ' JOIN binned.labels binnedLabel'
                        . ' WHERE binnedLabel.role = :trashRole',
                ),
            ),
        )->setParameter('trashRole', LabelRole::Trash);

        $rows = $qb->getQuery()->getArrayResult();

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
     * The same grouped SUM for custom labels.
     *
     * @return array<int,int> label id → unread thread-message sum
     */
    public function countUnreadPerUserLabel(UserInterface $user, ?Account $account = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->select('l.id AS labelId', 'SUM(t.unreadCount) AS unreadCount')
            ->join('t.account', 'a')
            ->join('t.labels', 'l')
            ->where('a.usr = :user')
            ->andWhere('a.isActive = true')
            ->andWhere('l.role IS NULL')
            ->setParameter('user', $user)
            ->groupBy('l.id');

        // Narrowed for the folder list under one account, where a count across
        // every account is an answer to a question nobody asked: the rows
        // beside it list that account's mail only.
        if (null !== $account) {
            $qb->andWhere('t.account = :account')
                ->setParameter('account', $account);
        }

        // Custom labels only (role IS NULL above), so there is no bin row to
        // spare here — a trashed thread simply stops counting.
        $this->excludeTrashed($qb);

        $rows = $qb->getQuery()->getArrayResult();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row['labelId']] = (int) $row['unreadCount'];
        }

        return $counts;
    }

    /**
     * COUNT DISTINCT over a join to Account — neither expressible through
     * count(), which takes field-to-value criteria on this entity alone.
     */
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

    // ── the new-mail marker ───────────────────────────────────────────────
    //
    // "New" is listed_at IS NULL: the thread has never had its row put in front
    // of the user. It is not "unread", and the two are asked separately
    // everywhere below — a thread scrolled past but never opened answers no to
    // the first and yes to the second.

    /**
     * Retire the marker on the threads that were just rendered.
     *
     * A bulk DQL UPDATE rather than a property write per entity, for the
     * reason it is called at all: this runs on every list render, and fifty
     * managed entities flushed one by one is fifty UPDATEs on the hot path.
     *
     * `listedAt IS NULL` in the WHERE is not redundant with the caller having
     * filtered already. Two tabs rendering the same page race here, and the
     * guard makes the loser a no-op instead of moving a timestamp that already
     * meant something.
     *
     * @param list<int> $threadIds ids from the rendered page, and only those —
     *        never the whole query, or page 1 would retire page 2's badges
     *
     * @return int rows actually retired
     */
    public function markListed(array $threadIds, \DateTimeImmutable $at): int
    {
        if ([] === $threadIds) {
            return 0;
        }

        return (int) $this->createQueryBuilder('t')
            ->update()
            ->set('t.listedAt', ':at')
            ->where('t.id IN (:ids)')
            ->andWhere('t.listedAt IS NULL')
            ->setParameter('at', $at)
            ->setParameter('ids', $threadIds)
            ->getQuery()
            ->execute();
    }

    /**
     * The same retirement, for ids that arrived from the browser.
     *
     * markListed() trusts its caller because its caller just read the threads
     * out of the database itself. This one is reachable by anybody who can POST
     * a JSON array, so ownership is a WHERE clause rather than an assumption:
     * without it, a list of guessed ids would let one account retire another
     * account's badges.
     *
     * A subselect on Account rather than a join, because DQL UPDATE cannot
     * join — and an id-by-id load-and-check would be one SELECT per row for a
     * request that fires on every list view.
     *
     * @param list<int> $threadIds
     *
     * @return int rows actually retired
     */
    public function markListedForUser(array $threadIds, UserInterface $user, \DateTimeImmutable $at): int
    {
        if ([] === $threadIds) {
            return 0;
        }

        return (int) $this->createQueryBuilder('t')
            ->update()
            ->set('t.listedAt', ':at')
            ->where('t.id IN (:ids)')
            ->andWhere('t.listedAt IS NULL')
            ->andWhere(
                't.account IN (SELECT owned.id FROM ' . Account::class . ' owned WHERE owned.usr = :user)',
            )
            ->setParameter('at', $at)
            ->setParameter('ids', $threadIds)
            ->setParameter('user', $user)
            ->getQuery()
            ->execute();
    }

    /**
     * The "is new" predicate, in one place.
     *
     * Never shown AND arrived inside MessageThread::NEW_WINDOW. Every count
     * below calls this instead of writing `t.listedAt IS NULL` itself, because
     * the counts feed dots and the dots have to agree with the badges the list
     * renders — and the list decides with MessageThread::isNewAt(). One rule,
     * two languages; this is the seam where they are kept identical.
     *
     * An aged-out thread keeps listedAt NULL rather than being stamped as
     * shown: the column says WHEN THE ROW WAS PUT IN FRONT OF THE USER, and
     * writing a timestamp there for mail nobody ever saw would be a false
     * statement recorded permanently — one the rethread backfill COALESCEs
     * forward, and one any later feature reading "was this displayed" would
     * inherit. The cost is a range predicate on last_message_at, which is
     * already the list's sort column.
     */
    private function restrictToNew(QueryBuilder $qb, \DateTimeImmutable $now): void
    {
        $qb->andWhere('t.listedAt IS NULL')
            ->andWhere('t.lastMessageAt IS NOT NULL')
            ->andWhere('t.lastMessageAt >= :newSince')
            ->setParameter('newSince', MessageThread::newSince($now));
    }

    /**
     * New threads per inbox category — what puts the dot on a Gmail tab.
     *
     * The same shape as countUnreadByCategoryForUnifiedInbox() and for the same
     * reason: a GROUP BY with an aggregate, which is one query rather than one
     * per tab on every page load.
     *
     * @return array<string,int>
     */
    public function countNewByCategoryForUnifiedInbox(UserInterface $user, \DateTimeImmutable $now): array
    {
        $qb = $this->createQueryBuilder('t')
            ->select('t.category AS category', 'COUNT(DISTINCT t.id) AS newCount')
            ->join('t.account', 'a')
            ->join('t.labels', 'l')
            ->where('a.usr = :user')
            ->andWhere('a.isActive = true')
            ->andWhere('l.role = :inbox')
            ->groupBy('t.category')
            ->setParameter('user', $user)
            ->setParameter('inbox', LabelRole::Inbox);

        $this->restrictToNew($qb, $now);
        $this->excludeTrashed($qb);

        $counts = [];

        foreach ($qb->getQuery()->getResult() as $row) {
            $category = $row['category'];

            if ($category instanceof MessageCategory) {
                $category = $category->value;
            }

            $counts[(string) $category] = (int) $row['newCount'];
        }

        return $counts;
    }

    /**
     * New threads per system role, for the sidebar dots.
     *
     * COUNT of threads, not SUM of unreadCount — the dot says "something
     * arrived here", and a conversation is one arrival however many messages
     * it holds.
     *
     * The Trash-or-not-trashed OR is lifted from countUnreadPerRole(), and is
     * load-bearing for the same reason: the bin has to keep counting its own
     * while every other row stops counting what was thrown away.
     *
     * @return array<string,int> role value → new thread count
     */
    public function countNewPerRole(UserInterface $user, \DateTimeImmutable $now): array
    {
        $qb = $this->createQueryBuilder('t')
            ->select('l.role AS role', 'COUNT(DISTINCT t.id) AS newCount')
            ->join('t.account', 'a')
            ->join('t.labels', 'l')
            ->where('a.usr = :user')
            ->andWhere('a.isActive = true')
            ->andWhere('l.role IS NOT NULL')
            ->setParameter('user', $user)
            ->groupBy('l.role');

        $this->restrictToNew($qb, $now);

        $qb->andWhere(
            $qb->expr()->orX(
                'l.role = :trashRole',
                $qb->expr()->notIn(
                    't.id',
                    'SELECT binned.id FROM ' . MessageThread::class . ' binned'
                        . ' JOIN binned.labels binnedLabel'
                        . ' WHERE binnedLabel.role = :trashRole',
                ),
            ),
        )->setParameter('trashRole', LabelRole::Trash);

        $counts = [];

        foreach ($qb->getQuery()->getArrayResult() as $row) {
            $role = $row['role'];

            if ($role instanceof LabelRole) {
                $role = $role->value;
            }

            $counts[(string) $role] = (int) $row['newCount'];
        }

        return $counts;
    }

    /**
     * The same count for custom labels.
     *
     * @return array<int,int> label id → new thread count
     */
    public function countNewPerUserLabel(UserInterface $user, \DateTimeImmutable $now, ?Account $account = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->select('l.id AS labelId', 'COUNT(DISTINCT t.id) AS newCount')
            ->join('t.account', 'a')
            ->join('t.labels', 'l')
            ->where('a.usr = :user')
            ->andWhere('a.isActive = true')
            ->andWhere('l.role IS NULL')
            ->setParameter('user', $user)
            ->groupBy('l.id');

        $this->restrictToNew($qb, $now);
        $this->narrowToAccount($qb, $account);
        $this->excludeTrashed($qb);

        $counts = [];

        foreach ($qb->getQuery()->getArrayResult() as $row) {
            $counts[(int) $row['labelId']] = (int) $row['newCount'];
        }

        return $counts;
    }

    /** New threads among the starred ones. */
    public function countNewForStarred(UserInterface $user, \DateTimeImmutable $now): int
    {
        $qb = $this->createQueryBuilder('t')
            ->select('COUNT(DISTINCT t.id)')
            ->join('t.account', 'a')
            ->where('a.usr = :user')
            ->andWhere('a.isActive = true')
            ->andWhere('t.starredAt IS NOT NULL')
            ->setParameter('user', $user);

        $this->restrictToNew($qb, $now);
        $this->excludeTrashed($qb);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Threads carrying ANY of the given labels — the merged path-based label
     * view aggregating same-named labels across accounts.
     *
     * QueryBuilder because the labels are a to-many association, and because a
     * thread carrying two of them would otherwise come back twice — the
     * GROUP BY is what makes one row mean one conversation.
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
     * Same to-many membership test as findForLabels().
     *
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
     * There is no Doctrine API for this: the whole method is a fetch mode, and
     * findBy() would honour the mapped one, which is the lazy loading this
     * exists to prevent. The result is deliberately discarded — the effect is
     * on the entities already in the identity map.
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
     * The same trick for the messages collection, which one row reads three
     * times over: thread_participants() walks every sender, `|last` supplies
     * the snippet and the avatar seed, and `|filter(m => m.isDraft)` decides
     * whether the row opens the compose dock instead of the thread. Each of
     * those initialises the collection, so a fifty-row list was fifty
     * `SELECT … FROM message WHERE thread_id = ?`.
     *
     * A batch rather than a denormalised column on MessageThread, and the
     * participants are why. The row does not want "the latest message" — it
     * wants the CAST of the conversation, every distinct sender in the order
     * they joined it (see ThreadParticipants). A lastMessageId stored beside
     * messageCount would leave that walk exactly where it is, the collection
     * would hydrate anyway, and the denormalisation would have bought nothing
     * while adding a second thing that can go stale. One query for the page
     * settles all three readers at once.
     *
     * ORDERED, which the label preload has no need to be: the association
     * carries #[ORM\OrderBy(receivedAt, id)] and a fetch join does not inherit
     * it. Without the order spelled here the collection arrives however
     * Postgres returned it, `|last` stops meaning "newest", and the row's
     * snippet comes off an arbitrary message.
     *
     * @param MessageThread[] $threads
     */
    public function preloadMessages(array $threads): void
    {
        if (count($threads) === 0) {
            return;
        }

        $this->createQueryBuilder('thread')
            ->addSelect('message')
            ->leftJoin('thread.messages', 'message')
            ->where('thread IN (:threads)')
            ->setParameter('threads', $threads)
            ->addOrderBy('message.receivedAt', 'ASC')
            ->addOrderBy('message.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * And the account, which the row reaches for twice — once for the corner
     * wedge that says which mailbox a unified row arrived in, and once inside
     * ThreadParticipants, which needs the account's own addresses to decide
     * which sender is "me".
     *
     * A preload rather than an addSelect on the join the list queries already
     * have, which is the obvious move and does not work here. Those two joins
     * exist beside a to-many label join, so the finders carry DISTINCT — and
     * `SELECT DISTINCT` over Account's columns is a Postgres error, not a
     * slow query: `could not identify an equality operator for type json`,
     * because Account holds json (oauth_last_refresh_error, graph_*). The
     * to-one addSelect is free of extra ROWS, as the usual advice says, but it
     * is not free of DISTINCT. One extra query for the whole page is.
     *
     * @param MessageThread[] $threads
     */
    public function preloadAccounts(array $threads): void
    {
        if (count($threads) === 0) {
            return;
        }

        $this->createQueryBuilder('thread')
            ->addSelect('account')
            ->join('thread.account', 'account')
            ->where('thread IN (:threads)')
            ->setParameter('threads', $threads)
            ->getQuery()
            ->getResult();
    }

    /**
     * Everything one page of thread ROWS reads, in three queries.
     *
     * One entry point rather than three calls at each of the six call sites.
     * The label preload existed and was called from every list view except
     * search — which is exactly why search cost fifty queries more than the
     * same fifty rows in the inbox. A list view that gets one preload and not
     * another is the failure this replaces; adding a fourth thing the row
     * needs should mean editing this method, not auditing every controller.
     *
     * @param MessageThread[] $threads
     */
    public function preloadForRows(array $threads): void
    {
        $this->preloadAccounts($threads);
        $this->preloadLabels($threads);
        $this->preloadMessages($threads);
    }

    /**
     * Full-text + operator search across messages for a given user.
     * Returns hydrated MessageThread entities in the order $sort asks for.
     *
     * Uses raw DBAL SQL because:
     *  - websearch_to_tsquery / @@ / ts_rank are not native DQL functions
     *  - We need DISTINCT ON which DQL cannot express
     *
     * The ORDER BY comes from the enum rather than being spelled here, because
     * it has to stay in step with the tiebreaker every order needs to survive
     * pagination — see SearchSortOrder::orderBy().
     */
    public function search(
        UserInterface     $user,
        ParsedSearchQuery $query,
        int               $page = 1,
        int               $perPage = 50,
        SearchSortOrder   $sort = SearchSortOrder::Recent,
    ): array {
        $offset = ($page - 1) * $perPage;

        [$sql, $params, $types] = $this->buildSearchSql($user, $query, false);

        $sql .= ' ORDER BY ' . $sort->orderBy() . ' LIMIT :limit OFFSET :offset';
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

        // Hydrate via Doctrine so we get full entities (same as other finders).
        // Order does not matter here — it is restored below, from the order the
        // SQL returned.
        $threads = $this->findBy(['id' => $ids]);

        // Re-order to match the sort order from SQL
        $indexed = [];
        foreach ($threads as $thread) {
            $indexed[$thread->id] = $thread;
        }

        $ordered = [];
        foreach ($ids as $id) {
            if (isset($indexed[$id])) {
                $ordered[] = $indexed[$id];
            }
        }

        return $ordered;
    }

    /** The same SQL as search(), counted — see that method for why it is SQL. */
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

        $params['userId'] = $user->id;
        $types['userId']  = ParameterType::INTEGER;

        // ── Free-text: three passes, OR-ed ────────────────────────────────
        // websearch_to_tsquery alone answers only the question "does this mail
        // contain exactly these words", which is not the question anyone types
        // into a search box. The other two passes are additive — nothing that
        // matched before stops matching — and each costs something:
        //
        //   websearch  index-only, GIN on search_vector. Free.
        //   prefix     the same GIN index; `:*` makes it a range over the
        //              lexeme dictionary rather than a point lookup, so it
        //              reads more index entries but no more heap.
        //   substring  ILIKE '%needle%'. Sequential without help, which is why
        //              Version20260811... installs pg_trgm and GIN trigram
        //              indexes over the four columns it touches. Restricted to
        //              a single term of 3+ characters — see FreeTextCompiler,
        //              which is also where the safety of the tsquery text is
        //              established.
        $rankExpr = '0';

        if ($query->freeText !== '') {
            $free = $this->freeText->compile($query->freeText);

            $matches            = ["m.search_vector @@ websearch_to_tsquery('english', :freeText)"];
            $ranks              = ["ts_rank(m.search_vector, websearch_to_tsquery('english', :freeText))"];
            $params['freeText'] = $free->websearch;

            if (null !== $free->prefix) {
                $matches[]            = "m.search_vector @@ to_tsquery('english', :freePrefix)";
                $ranks[]              = "ts_rank(m.search_vector, to_tsquery('english', :freePrefix))";
                $params['freePrefix'] = $free->prefix;
            }

            if (null !== $free->substring) {
                // from_name and from_address as well as the body: a search for
                // part of a sender's domain is the same gesture as a search for
                // part of a word in the text, and fails the same way without it.
                $matches[] = '('
                    . 'm.subject ILIKE :freeLike'
                    . ' OR m.body_text ILIKE :freeLike'
                    . ' OR m.from_name ILIKE :freeLike'
                    . ' OR m.from_address ILIKE :freeLike'
                    . ')';
                $params['freeLike'] = $this->freeText->likePattern($free->substring);
            }

            $where[] = '(' . implode(' OR ', $matches) . ')';

            // A row reached only by the substring pass has no ts_rank to give;
            // GREATEST over what did match keeps relevance ordering meaningful
            // instead of letting a NULL sink every such row to the bottom.
            $rankExpr = 1 === count($ranks) ? $ranks[0] : 'GREATEST(' . implode(', ', $ranks) . ')';
        }

        // ── Operator filters ──────────────────────────────────────────────

        if ($query->from !== null) {
            // Parenthesised: this OR must not bleed into the surrounding
            // AND-joined WHERE clause when other filters are present.
            $where[]            = '(LOWER(m.from_address) LIKE :fromAddr OR LOWER(m.from_name) LIKE :fromAddr)';
            $params['fromAddr'] = '%' . strtolower($query->from) . '%';
        }

        if ($query->to !== null) {
            // to_addresses is a JSON array of {name, address} objects
            $where[]      = "m.to_addresses::text ILIKE :toAddr";
            $params['toAddr'] = '%' . $query->to . '%';
        }

        if ($query->cc !== null) {
            $where[]          = 'm.cc_addresses::text ILIKE :ccAddr';
            $params['ccAddr'] = '%' . $query->cc . '%';
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
        // Already a role: the parser resolves what was typed ("junk", "bin")
        // and rejects what it cannot resolve, so a mailbox this install does
        // not have never reaches the SQL as a filter that silently matches
        // nothing — or, as it used to, a filter silently dropped.
        if ($query->mailboxRole !== null) {
            $where[]             = 'lbl.role = :labelRole';
            $params['labelRole'] = $query->mailboxRole;
        }

        // ── Trash stays in Trash ──────────────────────────────────────────
        // Search answers the mailbox somebody is looking through, and a
        // deleted conversation is not part of it until they say `in:trash` —
        // the same rule every list view applies since v0.0.25, arriving here
        // last because search builds its own SQL.
        //
        // NOT EXISTS rather than a condition on `lbl`: that alias exists once
        // per label the thread carries, so `lbl.role <> 'trash'` would keep a
        // deleted thread matching through its OTHER labels — filtering join
        // rows where the question is about the thread.
        if (LabelRole::Trash->value !== $query->mailboxRole) {
            $where[] = 'NOT EXISTS ('
                . 'SELECT 1 FROM thread_label xtl'
                . ' JOIN label xlbl ON xlbl.id = xtl.label_id'
                . ' WHERE xtl.message_thread_id = t.id AND xlbl.role = :trashRole'
                . ')';
            $params['trashRole'] = LabelRole::Trash->value;
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

    /**
     * Resolve every thread's category from its own messages, most-recent-wins,
     * in one statement.
     *
     * Raw SQL because DISTINCT ON is a Postgres extension DQL has no form of,
     * and because this is an UPDATE ... FROM over a derived table: the ORM's
     * write path is per-entity, so the alternative is loading every thread and
     * its messages to compute in PHP what the database can answer in one pass.
     */
    public function recomputeCategoriesForAccount(Account $account): int
    {
        $sql = <<<'SQL'
        UPDATE message_thread t
        SET category = sub.category
        FROM (
            SELECT DISTINCT ON (m.thread_id) m.thread_id, m.category
            FROM message m
            WHERE m.category IS NOT NULL
            ORDER BY m.thread_id, m.received_at DESC NULLS LAST
        ) sub
        WHERE t.id = sub.thread_id AND t.account_id = :accountId
        SQL;

        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            $sql,
            ['accountId' => $account->id],
        );
    }

    /**
     * How many of the user's conversations are snoozed right now.
     *
     * Counts the column rather than the Snoozed label, because the column is
     * what the wake sweep acts on: a thread whose time has passed but which
     * the sweep has not reached yet is no longer snoozed, and counting the
     * label would keep it in the total for up to a minute. Only used to decide
     * whether the sidebar shows the entry at all.
     *
     * QueryBuilder for the join to Account and for `snoozedUntil > :now`,
     * neither of which count() can state.
     */
    public function countSnoozedForUser(UserInterface $user): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->join('t.account', 'a')
            ->where('a.usr = :user')
            ->andWhere('a.isActive = true')
            ->andWhere('t.snoozedUntil IS NOT NULL')
            ->andWhere('t.snoozedUntil > :now')
            ->setParameter('user', $user)
            ->setParameter('now', new \DateTimeImmutable())
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Snoozed conversations whose wake time has passed.
     *
     * Ordered oldest-first so a backlog drains in the order the user asked
     * for, and capped so one enormous backlog cannot hold the worker.
     *
     * QueryBuilder for the `<= :now` comparison, and for the fetch-join: the
     * sweep touches every message of every thread it wakes, so leaving the
     * collections lazy would be an N+1 across the whole backlog.
     *
     * @return list<MessageThread>
     */
    public function findDueSnoozed(\DateTimeImmutable $now, int $limit): array
    {
        return $this->createQueryBuilder('t')
            ->addSelect('m')
            ->leftJoin('t.messages', 'm')
            ->where('t.snoozedUntil IS NOT NULL')
            ->andWhere('t.snoozedUntil <= :now')
            ->setParameter('now', $now)
            ->orderBy('t.snoozedUntil', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * JMAP Thread/get: fetch by id, scoped to the account. Messages are
     * eager-joined because a Thread object is nothing but its email id list —
     * findBy() would honour the mapped fetch mode and load them one thread at
     * a time.
     *
     * @param list<int> $ids
     *
     * @return list<MessageThread>
     */
    public function findByAccountAndIds(int $accountId, array $ids): array
    {
        if (count($ids) === 0) {
            return [];
        }

        return $this->createQueryBuilder('t')
            ->addSelect('m')
            ->leftJoin('t.messages', 'm')
            ->where('t.account = :account')
            ->andWhere('t.id IN (:ids)')
            ->setParameter('account', $accountId)
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getResult();
    }

    /**
     * Threads of one account with nothing left in them.
     *
     * `messages IS EMPTY` is a collection predicate; findBy() compares fields
     * to values and has no way to ask about the size of an association.
     *
     * @return list<MessageThread>
     */
    public function findEmptyForUser(UserInterface $user): array
    {
        return $this->createQueryBuilder('t')
            ->innerJoin('t.account', 'a')
            ->where('a.usr = :user')
            ->andWhere('t.messages IS EMPTY')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();
    }

    /**
     * Per-thread state worth carrying across a rethread, anchored to each
     * thread's earliest message so it can be found again afterwards.
     *
     * Raw SQL, and scalars rather than entities, because the caller is about to
     * delete every one of these rows: hydrating them would leave a unit of work
     * full of entities whose tables are then truncated out from under it. The
     * MIN(id) anchor is an aggregate the ORM would have to load a whole thread
     * to compute.
     *
     * listed_at comes along for a reason worth stating: a rebuild drops every
     * thread row and makes new ones, and a new row is born null, which is to
     * say NEW. Without carrying it, running this maintenance command would
     * light up the account's entire history as unseen mail — the same failure
     * the migration's backfill exists to prevent, reachable from a console
     * command instead of a deploy.
     *
     * @return list<array{id: int, starred_at: ?string, snoozed_until: ?string, category: ?string, listed_at: ?string, anchor: int}>
     */
    public function findCarriedOverStateForAccount(int $accountId): array
    {
        /** @var list<array{id: int, starred_at: ?string, snoozed_until: ?string, category: ?string, listed_at: ?string, anchor: int}> */
        return $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT t.id, t.starred_at, t.snoozed_until, t.category, t.listed_at, MIN(m.id) AS anchor
             FROM message_thread t
             INNER JOIN message m ON m.thread_id = t.id
             WHERE t.account_id = :accountId
             GROUP BY t.id',
            ['accountId' => $accountId],
        );
    }

    /**
     * Which labels each of these threads carries.
     *
     * Straight off the join table: thread_label has no entity, and reading it
     * through the Label side would hydrate every label of every thread to
     * recover two integers per row.
     *
     * @param list<int> $threadIds
     *
     * @return array<int, list<int>> thread id => label ids
     */
    public function findLabelIdsByThread(array $threadIds): array
    {
        if (0 === count($threadIds)) {
            return [];
        }

        $labelsByThread = [];

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT message_thread_id, label_id FROM thread_label WHERE message_thread_id IN (:threadIds)',
            ['threadIds' => $threadIds],
            ['threadIds' => ArrayParameterType::INTEGER],
        );

        foreach ($rows as $row) {
            $labelsByThread[(int) $row['message_thread_id']][] = (int) $row['label_id'];
        }

        return $labelsByThread;
    }

    /**
     * Drop every thread row of an account without touching its mail.
     *
     * Deliberately not the ORM: MessageThread cascades remove onto its
     * messages, so removing threads through the EntityManager would delete the
     * mail along with them. Callers detach the messages first — see
     * MessageRepository::detachAllFromThreadsForAccount().
     */
    public function deleteAllForAccount(int $accountId): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM message_thread WHERE account_id = :accountId',
            ['accountId' => $accountId],
        );
    }

    /**
     * Write a snapshot's starring, snoozing and category back onto a rebuilt
     * thread.
     *
     * COALESCE, not assignment, and that is why this cannot be a property
     * write: several old threads can collapse into one rebuilt thread, and a
     * value already restored by an earlier snapshot must not be blanked by a
     * later one that happened to be empty. The values arrive as the strings the
     * snapshot read, so they go back the way they came rather than through a
     * round trip that would have to guess their timezone.
     */
    public function restoreCarriedOverState(
        int     $threadId,
        ?string $starredAt,
        ?string $snoozedUntil,
        ?string $category,
        ?string $listedAt = null,
    ): void {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE message_thread
             SET starred_at    = COALESCE(starred_at, :starredAt),
                 snoozed_until = COALESCE(snoozed_until, :snoozedUntil),
                 category      = COALESCE(category, :category),
                 listed_at     = COALESCE(listed_at, :listedAt)
             WHERE id = :threadId',
            [
                'starredAt'    => $starredAt,
                'snoozedUntil' => $snoozedUntil,
                'category'     => $category,
                // COALESCE cuts the right way here too: several old threads can
                // collapse into one rebuilt thread, and if ANY of them had been
                // shown then the conversation is not news to anybody. The first
                // non-null wins and later nulls cannot take it back.
                'listedAt'     => $listedAt,
                'threadId'     => $threadId,
            ],
        );
    }

    /**
     * Put a label back on a rebuilt thread, tolerating the one that is already
     * there.
     *
     * ON CONFLICT DO NOTHING because collapsed threads restore the same label
     * more than once, and the join table has no entity to check for first.
     */
    public function addLabelIfAbsent(int $threadId, int $labelId): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO thread_label (message_thread_id, label_id)
             VALUES (:threadId, :labelId)
             ON CONFLICT DO NOTHING',
            ['threadId' => $threadId, 'labelId' => $labelId],
        );
    }

    /**
     * Rebuild every thread's attachment counter from its messages.
     *
     * The counter is derived, so it is recomputed rather than patched by
     * deltas — a repair that has to be right whatever state it starts from.
     * One correlated UPDATE rather than loading every thread: nothing here
     * needs an entity, and the alternative is the whole mailbox in memory.
     */
    public function recomputeAttachmentCounts(): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(<<<'SQL'
            UPDATE message_thread t
            SET attachment_count = COALESCE((
                SELECT COUNT(*) FROM message m
                WHERE m.thread_id = t.id AND m.has_attachments = true
            ), 0)
        SQL);
    }
}
