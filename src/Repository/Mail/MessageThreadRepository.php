<?php

namespace App\Repository\Mail;

use App\Domain\DTO\Mail\SearchPage;
use App\Domain\DTO\ParsedSearchQuery;
use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\ListSortOrder;
use App\Domain\Enum\Mail\MessageCategory;
use App\Domain\Enum\Mail\SearchSortOrder;
use App\Entity\Mail\Account;
use App\Entity\Label\Label;
use App\Entity\Mail\Message;
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
    public function findForUnifiedInbox(UserInterface $user, MessageCategory $category, int $page = 1, int $perPage = 50, ListSortOrder $sort = ListSortOrder::Newest, bool $unreadOnly = false): array
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
        $this->narrowToUnread($qb, $unreadOnly);

        $sort->applyTo($qb);

        return $qb->getQuery()->getResult();
    }

    /** Same two joins as findForUnifiedInbox(), so the same reason to keep it. */
    public function countForUnifiedInbox(UserInterface $user, MessageCategory $category, bool $unreadOnly = false): int
    {
        // COUNT(DISTINCT t.id), not select-DISTINCT: the label join is to-many,
        // and ->distinct() would put the DISTINCT on the aggregate rather than
        // on the rows being aggregated. A thread carrying two labels of the
        // inbox role would then be counted twice while findForUnifiedInbox()
        // -- which dedupes properly -- returns it once, and the paginator would
        // offer a page that does not exist. countForRole() below has always
        // spelled it this way.
        $qb = $this->createQueryBuilder('t')
            ->select('COUNT(DISTINCT t.id)')
            ->join('t.account', 'a')
            ->join('t.labels', 'l')
            ->where('a.usr = :user')
            ->andWhere('a.isActive = true')
            ->andWhere('l.role = :inbox')
            ->andWhere('t.category = :category')
            ->setParameter('user', $user)
            ->setParameter('inbox', LabelRole::Inbox)
            ->setParameter('category', $category);

        $this->narrowToUnread($qb, $unreadOnly);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Unread threads per category in one grouped read.
     *
     * A GROUP BY with an aggregate, which Doctrine's API has no form of at all
     * — count() answers one number, and asking it per category would be one
     * query per tab on every page load.
     */
    /**
     * Threads per category regardless of read state, same grouped shape as
     * countNewByCategoryForUnifiedInbox(). This one decides which tabs exist
     * at all: a category with fifty read promotions still deserves its tab,
     * so visibility cannot be derived from the new-mail numbers.
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

    /**
     * One system mailbox across every active account. Joined for the same
     * reason findForUnifiedInbox() is: the role lives on a Label the thread
     * reaches through a to-many, and the owner on the Account.
     */
    public function findForRole(UserInterface $user, LabelRole $role, int $page = 1, int $perPage = 50, ListSortOrder $sort = ListSortOrder::Newest, bool $unreadOnly = false): array
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

        $this->narrowToUnread($qb, $unreadOnly);

        $sort->applyTo($qb);

        return $qb->getQuery()->getResult();
    }
    /** Same joins as findForRole(). */
    public function countForRole(UserInterface $user, LabelRole $role, bool $unreadOnly = false): int
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

        $this->narrowToUnread($qb, $unreadOnly);

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
    public function findForLabel(Label $label, ?Account $account = null, int $page = 1, int $perPage = 50, ListSortOrder $sort = ListSortOrder::Newest, bool $unreadOnly = false): array
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
        $this->narrowToUnread($qb, $unreadOnly);

        $sort->applyTo($qb);

        return $qb->getQuery()->getResult();
    }

    /** Same to-many filter as findForLabel(). */
    public function countForLabel(Label $label, ?Account $account = null, bool $unreadOnly = false): int
    {
        $qb = $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->join('t.labels', 'l')
            ->where('l = :label')
            ->setParameter('label', $label);

        $this->narrowToAccount($qb, $account);
        $this->excludeTrashedUnlessBin($qb, $label);
        $this->narrowToUnread($qb, $unreadOnly);

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
     * One account's inbox — findForUnifiedInbox() narrowed to a single account
     * and widened across every category.
     *
     * This is the account row in the sidebar, and it used to list EVERYTHING in
     * the account whatever it was labelled. That is not what clicking a mailbox
     * means to anybody: the list opened with your own sent mail interleaved
     * through it, plus drafts and spam, and the one thing a person clicking
     * their address is looking for — what has arrived — was buried in it. The
     * bin was already excluded, which was the first half of this same
     * realisation.
     *
     * Deliberately NOT filtered by category, unlike the unified inbox above.
     * The tabs are a property of that one screen; here the account's Inbox
     * FOLDER is what is being shown, and it is the same list the account's own
     * "Inbox" row in the expanded folder tree gives — which is exactly what a
     * reader comparing the two would expect.
     *
     * No `isActive` filter either, again unlike the unified list. A switched-off
     * account is still in the sidebar and still clickable; answering with an
     * empty list because it is asleep would read as lost mail.
     */
    public function findForAccountInbox(Account $account, int $page = 1, int $perPage = 50, ListSortOrder $sort = ListSortOrder::Newest): array
    {
        $qb = $this->createQueryBuilder('t')
            ->join('t.labels', 'l')
            ->where('t.account = :account')
            ->andWhere('l.role = :inbox')
            ->setParameter('account', $account)
            ->setParameter('inbox', LabelRole::Inbox)
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->distinct();

        $this->excludeTrashed($qb);

        $sort->applyTo($qb);

        return $qb->getQuery()->getResult();
    }

    /** Same two conditions as findForAccountInbox(), so the same reason to keep it. */
    public function countForAccountInbox(Account $account): int
    {
        // COUNT(DISTINCT t.id) for the reason countForUnifiedInbox() spells
        // out: the label join is to-many, and a thread carrying two labels of
        // the inbox role would otherwise be counted twice while the listing
        // returns it once — and the paginator would offer a page that is not
        // there.
        $qb = $this->createQueryBuilder('t')
            ->select('COUNT(DISTINCT t.id)')
            ->join('t.labels', 'l')
            ->where('t.account = :account')
            ->andWhere('l.role = :inbox')
            ->setParameter('account', $account)
            ->setParameter('inbox', LabelRole::Inbox);

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
    /**
     * Narrow a thread list to the conversations a badge counted.
     *
     * `unreadCount` is per-thread and counts MESSAGES, so "> 0" is "this
     * conversation holds something unread" — which is exactly what the badges
     * count now (see countUnreadPerRole). One predicate shared by every list
     * that can be filtered, so a badge and the list it opens cannot drift into
     * two different ideas of unread.
     *
     * A no-op when the flag is false, so every caller passes it unconditionally
     * rather than branching around it.
     */
    private function narrowToUnread(QueryBuilder $qb, bool $unreadOnly): void
    {
        if (false === $unreadOnly) {
            return;
        }

        $qb->andWhere('t.unreadCount > 0');
    }

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
    public function findForStarred(UserInterface $user, int $page = 1, int $perPage = 50, ListSortOrder $sort = ListSortOrder::Newest, bool $unreadOnly = false): array
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
        $this->narrowToUnread($qb, $unreadOnly);

        $sort->applyTo($qb);

        return $qb->getQuery()->getResult();
    }

    /** Same join as findForStarred(). */
    public function countForStarred(UserInterface $user, bool $unreadOnly = false): int
    {
        $qb = $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->join('t.account', 'a')
            ->where('a.usr = :user')
            ->andWhere('a.isActive = true')
            ->andWhere('t.starredAt IS NOT NULL')
            ->setParameter('user', $user);

        $this->excludeTrashed($qb);
        $this->narrowToUnread($qb, $unreadOnly);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
    /**
     * Grouped COUNT per system role — an aggregate over a join, which is two
     * things Doctrine's API cannot do and one query instead of a role's worth.
     *
     * CONVERSATIONS with unread mail, not unread messages. It used to be
     * SUM(t.unreadCount), which answered a question the list beside it could
     * not: the badge said how many unread MESSAGES a role held, the list under
     * it draws one row per conversation, and a thread holding three unread
     * replies made those two numbers differ by two. Harmless while the badge
     * was only ever read, and not harmless once it became the thing you click
     * to see exactly that mail — a "4" that opens three rows is the Trash
     * "188 unread against a list of 193" all over again.
     *
     * countUnreadForStarred() had already been written this way, so this also
     * ends a disagreement between the badges themselves: Starred counted
     * conversations while every other badge counted messages.
     *
     * @return array<string,int> role value → conversations holding unread mail
     */
    public function countUnreadPerRole(UserInterface $user): array
    {
        $qb = $this->createQueryBuilder('t')
            ->select('l.role AS role', 'COUNT(DISTINCT t.id) AS unreadCount')
            ->join('t.account', 'a')
            ->join('t.labels', 'l')
            ->where('a.usr = :user')
            ->andWhere('a.isActive = true')
            ->andWhere('l.role IS NOT NULL')
            ->andWhere('t.unreadCount > 0')
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
     * The same grouped COUNT for custom labels — conversations holding unread
     * mail, for the reason countUnreadPerRole() gives.
     *
     * @return array<int,int> label id → conversations holding unread mail
     */
    public function countUnreadPerUserLabel(UserInterface $user, ?Account $account = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->select('l.id AS labelId', 'COUNT(DISTINCT t.id) AS unreadCount')
            ->join('t.account', 'a')
            ->join('t.labels', 'l')
            ->where('a.usr = :user')
            ->andWhere('a.isActive = true')
            ->andWhere('l.role IS NULL')
            ->andWhere('t.unreadCount > 0')
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
     * One number for everything filed under a custom label — what the LABELS
     * heading shows while the section is collapsed.
     *
     * NOT a sum of countUnreadPerUserLabel(), and that is the whole reason it
     * is a query of its own. Those counts are per label and a thread may carry
     * several, so adding them up reports one conversation two or three times:
     * the section would claim more unread than expanding it could show, which
     * is the specific way a rolled-up number loses people's trust.
     *
     * Stated as "threads carrying at least one custom label" via a subquery
     * instead, so each is counted once however many labels are on it.
     *
     * A COUNT of those threads rather than a SUM over them, matching the
     * per-label badges it stands in for: the heading has to be the number of
     * rows expanding the section would show you, and those rows are
     * conversations.
     */
    public function countUnreadInUserLabels(UserInterface $user): int
    {
        $qb = $this->createQueryBuilder('t')
            ->select('COUNT(DISTINCT t.id)')
            ->join('t.account', 'a')
            ->where('a.usr = :user')
            ->andWhere('a.isActive = true')
            ->andWhere('t.unreadCount > 0')
            ->andWhere(
                't.id IN ('
                    . 'SELECT labelled.id FROM ' . MessageThread::class . ' labelled'
                    . ' JOIN labelled.labels userLabel'
                    . ' WHERE userLabel.role IS NULL)',
            )
            ->setParameter('user', $user);

        // Same rule the per-label counts follow: a trashed thread stops
        // counting, so the heading cannot be louder than the rows under it.
        $this->excludeTrashed($qb);

        return (int) $qb->getQuery()->getSingleScalarResult();
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
     * The same shape as countByCategoryForUnifiedInbox() and for the same
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
     * Who the new mail on each tab is from — the names behind the Gmail-style
     * sender hint under a category tab.
     *
     * One query for every category, like the counts above, but this one has to
     * look INSIDE the threads: the hint names the newest message's sender, and
     * the thread row does not carry one. So every message of every new thread
     * comes back flat — bounded, because "new" is capped at the 24-hour window
     * — and the newest-per-thread pick happens in PHP, where it is a
     * comparison rather than a correlated subquery per row.
     *
     * Newest thread first, one name per sender, capped: the hint is a teaser,
     * not a roster, and the count beside it already says how much the names
     * stand in for.
     *
     * @return array<string, list<string>> category value → sender display
     *         names, newest arrival first, at most $perCategory
     */
    public function newSendersByCategoryForUnifiedInbox(
        UserInterface $user,
        \DateTimeImmutable $now,
        int $perCategory = 3,
    ): array {
        $qb = $this->createQueryBuilder('t')
            ->select(
                't.id AS threadId',
                't.category AS category',
                't.lastMessageAt AS arrivedAt',
                'm.fromName AS fromName',
                'm.fromAddress AS fromAddress',
                // The same chain ThreadParticipants::newest() walks: createdAt
                // closes it, so every message has an instant to compare on.
                'COALESCE(m.receivedAt, m.sentAt, m.createdAt) AS messageAt',
            )
            ->join('t.account', 'a')
            ->join('t.labels', 'l')
            ->join(Message::class, 'm', 'WITH', 'm.thread = t')
            ->where('a.usr = :user')
            ->andWhere('a.isActive = true')
            ->andWhere('l.role = :inbox')
            ->setParameter('user', $user)
            ->setParameter('inbox', LabelRole::Inbox);

        $this->restrictToNew($qb, $now);
        $this->excludeTrashed($qb);

        // Newest message per thread. COALESCE hydrates as a "Y-m-d H:i:s"
        // string, which orders chronologically as a plain string compare.
        /** @var array<int, array{category: string, arrivedAt: \DateTimeImmutable|null, name: string, at: string}> $threads */
        $threads = [];

        foreach ($qb->getQuery()->getResult() as $row) {
            $id = (int) $row['threadId'];
            $at = (string) $row['messageAt'];

            if (true === isset($threads[$id]) && $threads[$id]['at'] >= $at) {
                continue;
            }

            $category = $row['category'];

            if ($category instanceof MessageCategory) {
                $category = $category->value;
            }

            $name = trim((string) ($row['fromName'] ?? ''));

            if ('' === $name) {
                $name = trim((string) ($row['fromAddress'] ?? ''));
            }

            $threads[$id] = [
                'category'  => (string) $category,
                'arrivedAt' => $row['arrivedAt'],
                'name'      => $name,
                'at'        => $at,
            ];
        }

        $entries = array_values(array_filter(
            $threads,
            static fn (array $entry): bool => '' !== $entry['name'],
        ));

        usort(
            $entries,
            static fn (array $a, array $b): int => $b['arrivedAt'] <=> $a['arrivedAt'],
        );

        $senders = [];

        foreach ($entries as $entry) {
            $list = $senders[$entry['category']] ?? [];

            if (count($list) >= $perCategory || true === in_array($entry['name'], $list, true)) {
                continue;
            }

            $list[] = $entry['name'];

            $senders[$entry['category']] = $list;
        }

        return $senders;
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
     * Full-text + operator search across messages for a given user — one page
     * of hydrated MessageThread entities, and how many threads match in all.
     *
     * Uses raw DBAL SQL because:
     *  - websearch_to_tsquery / @@ / ts_rank are not native DQL functions
     *  - We need DISTINCT ON which DQL cannot express
     *
     * The ORDER BY comes from the enum rather than being spelled here, because
     * it has to stay in step with the tiebreaker every order needs to survive
     * pagination — see SearchSortOrder::orderBy().
     *
     * The total arrives WITH the page rather than from a countSearch() beside
     * it, and that is the whole reason this returns an object. See searchRows()
     * for the measurement; the short version is that the second statement
     * repeated every scan the first had already done, for a number rendered in
     * the corner of the toolbar.
     */
    public function searchPage(
        UserInterface     $user,
        ParsedSearchQuery $query,
        int               $page = 1,
        int               $perPage = 50,
        SearchSortOrder   $sort = SearchSortOrder::Recent,
    ): SearchPage {
        $offset = ($page - 1) * $perPage;

        $rows = $this->searchRows($user, $query, $sort, $perPage, $offset, false);

        // Thin, so the expensive pass earns its keep — see the rescue note in
        // buildSearchSql(). Only ever reached on a page that did not fill,
        // which is the same condition the pass exists to serve.
        if (count($rows) < self::SEARCH_RESCUE_BELOW) {
            $rows = $this->searchRows($user, $query, $sort, $perPage, $offset, true);
        }

        if ([] === $rows) {
            return new SearchPage([], $this->totalForEmptyPage($user, $query, $sort, $page));
        }

        // Every row carries the same window count, so the first one will do.
        $total = (int) $rows[0]['total_threads'];

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

        return new SearchPage($ordered, $total);
    }

    /**
     * How many results there are when the requested page has none of them.
     *
     * The window count rides on the rows, so a page past the end comes back
     * with nothing to read it from — and answering zero there would tell
     * somebody who followed a stale `?page=12` link that their query matches
     * nothing at all, with the pager gone and no way back to page 1. One row
     * off the front of the same result set says how many there really are.
     *
     * Page 1 needs no such rescue: an empty first page IS an empty result.
     */
    private function totalForEmptyPage(
        UserInterface     $user,
        ParsedSearchQuery $query,
        SearchSortOrder   $sort,
        int               $page,
    ): int {
        if (1 === $page) {
            return 0;
        }

        $rows = $this->searchRows($user, $query, $sort, 1, 0, false);

        // Spelled as "nothing at all" rather than searchPage()'s "fewer than a
        // screenful": with a limit of one, that test would fire on every
        // query and put the body scan back on a path that does not need it.
        if ([] === $rows) {
            $rows = $this->searchRows($user, $query, $sort, 1, 0, true);
        }

        return [] === $rows ? 0 : (int) $rows[0]['total_threads'];
    }

    /**
     * One page of thread ids, ranked, each carrying the size of the whole
     * result set.
     *
     * `COUNT(*) OVER ()` instead of a second `SELECT COUNT(DISTINCT t.id)` with
     * the same WHERE, which is what the pager used to cost. Measured on the
     * 300,000-message corpus: 620ms on its own and 450-830ms in the request,
     * because it re-ran the whole UNION and the join over it to answer a
     * question this statement was already in a position to answer. The window
     * runs over the grouped rows the GROUP BY has produced anyway — the sort
     * above it already materialises them — and measured 35ms, three per cent.
     *
     * Bounding the count instead (`SELECT count(*) FROM (SELECT … LIMIT 1001)`,
     * rendered as "1000+") was the obvious cheaper move and does not work here:
     * the LIMIT sits above a DISTINCT, Postgres answers that with a
     * HashAggregate, and a HashAggregate has to read all of its input before it
     * emits its first row. Measured at 717ms against the unbounded 620ms —
     * bounding it made it slower AND made the number a lie.
     *
     * @return list<array<string,mixed>>
     */
    private function searchRows(
        UserInterface     $user,
        ParsedSearchQuery $query,
        SearchSortOrder   $sort,
        int               $perPage,
        int               $offset,
        bool              $withBodyRescue,
    ): array {
        [$sql, $params, $types] = $this->buildSearchSql($user, $query, $withBodyRescue);

        $sql .= ' ORDER BY ' . $sort->orderBy() . ' LIMIT :limit OFFSET :offset';
        $params['limit']  = $perPage;
        $params['offset'] = $offset;
        $types['limit']   = ParameterType::INTEGER;
        $types['offset']  = ParameterType::INTEGER;

        return $this->getEntityManager()->getConnection()->fetchAllAssociative($sql, $params, $types);
    }

    /**
     * Below this many cheap hits, the body substring pass is worth its 3
     * seconds; at or above it, it is not.
     *
     * One page. If full-text and the sender/subject passes have already filled
     * the screen, scanning every body for an infix is work whose results the
     * reader will not reach — and since the tokenizer split
     * (Version20260818120000) the case that pass exists for is answered by the
     * index anyway, so what is given up is a genuine edge rather than the
     * feature.
     */
    private const int SEARCH_RESCUE_BELOW = 50;

    /**
     * The ids the search may look at, read once and bound as values.
     *
     * Takes the id rather than the user, because the caller has already put it
     * in the parameter bag and UserInterface does not declare `$id` — a second
     * `$user->id` in this file is a second thing for static analysis to be told
     * to ignore, for a value that was right there.
     *
     * @return list<int>
     */
    private function activeAccountIdsFor(int $userId): array
    {
        /** @var list<int> $ids */
        $ids = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            'SELECT id FROM account WHERE usr_id = ? AND is_active = true',
            [$userId],
        );

        return array_map(intval(...), $ids);
    }

    /**
     * The statement both the page and its total come out of.
     *
     * @return array{string, array<string,mixed>, array<string,mixed>}
     */
    private function buildSearchSql(
        UserInterface     $user,
        ParsedSearchQuery $query,
        bool              $withBodyRescue = false,
    ): array {
        $params = [];
        $types  = [];
        $where  = ['a.usr_id = :userId', 'a.is_active = true'];

        $params['userId'] = $user->id;
        $types['userId']  = ParameterType::INTEGER;

        $rankExpr = '0';

        // ── Free-text ─────────────────────────────────────────────────────
        // Four passes over one question, and how they are COMBINED is the
        // whole performance story of this method.
        //
        // They used to be OR-ed into the WHERE clause. One OR spanning two
        // index families — GIN over the tsvector, GIN trigram over four text
        // columns — is a shape Postgres answers by abandoning both: measured on
        // 300,000 messages it drove from message_thread, walked all 100,000
        // threads, and applied the whole thing as a row filter. Every index sat
        // unused, and the query cost the same 9-10 SECONDS whether the term was
        // in 0.25% of the mail or 8% of it. Cost independent of selectivity is
        // the signature of an index doing nothing.
        //
        // As a UNION each branch is its own scan and picks its own index:
        // websearch 89ms, prefix 111ms, subject 4ms, the two sender columns
        // 0.2ms.
        //
        // The body substring pass is kept OUT of that union and run only as a
        // rescue — see below.
        if ($query->freeText !== '') {
            $free = $this->freeText->compile($query->freeText);

            $params['freeText'] = $free->websearch;

            // Scoped to the searcher's own accounts. The outer query filters
            // by user anyway, so this is not what makes the answer correct — it
            // is what stops one person's search reading another person's mail
            // off the disk on a shared install, and what keeps the cost
            // proportional to the mailbox being searched.
            //
            // The ids are resolved HERE and bound as a list, rather than left
            // as `account_id IN (SELECT id FROM account WHERE usr_id = …)`, and
            // that is not a style preference — it is worth 18x. With the
            // subquery the planner cannot know how many accounts come back, so
            // it reaches for the account_id index, which on a single-user
            // install selects everything: 2,326ms per branch, the GIN index
            // untouched. Given the ids it costs them properly and uses the
            // tsvector index: 126ms.
            $accountIds = $this->activeAccountIdsFor((int) $params['userId']);

            // No accounts, no mail — and `IN ()` is not valid SQL. Answered as
            // a false predicate inside the normal statement rather than by
            // returning a different one: the callers append ORDER BY and LIMIT
            // to whatever comes back, so a short-circuit SQL string here is a
            // syntax error one frame later. It was: /mail/search 500'd for any
            // user who had not connected a mailbox yet.
            if ([] === $accountIds) {
                $where[]  = 'false';
                $rankExpr = '0';

                $mine = 'false';
            } else {
                $params['searchAccounts'] = $accountIds;
                $types['searchAccounts']  = ArrayParameterType::INTEGER;

                $mine = 'm.account_id IN (:searchAccounts)';
            }

            $cheap = ["SELECT m.id FROM message m WHERE {$mine} AND m.search_vector @@ websearch_to_tsquery('english', :freeText)"];
            $ranks = ["ts_rank(m.search_vector, websearch_to_tsquery('english', :freeText))"];

            if (null !== $free->prefix) {
                $cheap[]              = "SELECT m.id FROM message m WHERE {$mine} AND m.search_vector @@ to_tsquery('english', :freePrefix)";
                $ranks[]              = "ts_rank(m.search_vector, to_tsquery('english', :freePrefix))";
                $params['freePrefix'] = $free->prefix;
            }

            if (null !== $free->substring) {
                // Subject and the two sender columns, but NOT the body: those
                // three are narrow, so the trigram index's recheck reads almost
                // nothing and they cost microseconds.
                foreach (['subject', 'from_name', 'from_address'] as $column) {
                    $cheap[] = "SELECT m.id FROM message m WHERE {$mine} AND m.{$column} ILIKE :freeLike";
                }

                $params['freeLike'] = $this->freeText->likePattern($free->substring);
            }

            // ── The rescue ────────────────────────────────────────────
            // `body_text ILIKE '%needle%'` is the one branch that cannot be
            // made cheap. The trigram index is not the problem — it hands back
            // 32,440 candidates out of 300,000 in 7.6ms — the recheck is:
            // `%needle%` is lossy on trigrams, so every candidate body has to
            // be fetched and matched for real, and for a common needle that is
            // 131MB of text and 3.1 seconds. Storage layout does not move it
            // (MAIN 2.9s, EXTERNAL 3.1s), and a parallel sequential scan of
            // every body takes 2.26s — the index path is slower than reading
            // everything. There is no better index for `%needle%`.
            //
            // So it is only run when the cheap passes came back thin, which is
            // the case it was added for: a needle full-text cannot see, like
            // "wirhub" inside "help.wirhub.de". Since the tokenizer split
            // (Version20260818120000) the index answers that one directly, so
            // this is a genuine last resort rather than a trade.
            //
            // A SECOND QUERY rather than one clever statement. The single-query
            // form — a CTE counting the cheap hits and a `(SELECT few …)`
            // InitPlan gating the body scan — worked beautifully with literal
            // values, printing `One-Time Filter` and skipping the scan
            // entirely. Through DBAL's bind parameters it planned differently
            // and one term went from 13 seconds to a 30-second PHP timeout.
            // Two predictable queries beat one whose plan depends on how the
            // values arrived, and the second only runs when the first came up
            // short.
            if (true === $withBodyRescue && null !== $free->substring) {
                $cheap[] = "SELECT m.id FROM message m WHERE {$mine} AND m.body_text ILIKE :freeLike";
            }

            $where[] = 'm.id IN (' . implode(' UNION ', $cheap) . ')';

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

        $sql = <<<SQL
            SELECT
                t.id                                              AS thread_id,
                MAX({$rankExpr})                                  AS rank,
                MAX(t.last_message_at)                            AS last_message_at,
                COUNT(*) OVER ()                                  AS total_threads
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
