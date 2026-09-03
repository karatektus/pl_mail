<?php

namespace App\Repository\Mail;

use App\Domain\DTO\Ai\SemanticSearch;
use App\Domain\DTO\Mail\SearchPage;
use App\Domain\DTO\ParsedSearchQuery;
use App\Domain\Enum\Ai\SemanticSkipReason;
use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\ListSortOrder;
use App\Domain\Enum\Mail\MessageCategory;
use App\Domain\Enum\Mail\SearchSortOrder;
use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Service\Search\FreeTextCompiler;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Security\Core\User\UserInterface;

class MessageThreadRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly FreeTextCompiler $freeText = new FreeTextCompiler(),
        /**
         * Injectable so the give-up path can be tested for what it does rather
         * than inspected for what it says. A budget nothing ever exercises is a
         * budget nobody knows the shape of; at 1ms a test can watch the pass
         * expire and check that the cheap results are still standing.
         */
        private readonly int $rescueTimeoutMs = self::SEARCH_RESCUE_TIMEOUT_MS,
        /**
         * The same argument as above, for the other pass that can run long.
         * Separate from $rescueTimeoutMs because the two guard different work
         * and a test that wants to watch one expire must not have to expire
         * the other as well.
         */
        private readonly int $semanticTimeoutMs = self::SEARCH_SEMANTIC_TIMEOUT_MS,
        /**
         * And the third, for the same reason as the first two: a bound nothing
         * exercises is a bound nobody knows the shape of.
         *
         * This one guards WHICH messages are eligible rather than how long they
         * may take, and the bug that made it injectable was invisible at two
         * thousand — the candidate subquery filtered the wrong table alias, so
         * the window was shared with every other account on the installation.
         * A test cannot afford to seed 2,000 messages to see that; at 2 it is a
         * three-row fixture. See buildSearchSql().
         */
        private readonly int $semanticCandidates = self::SEMANTIC_CANDIDATES,
        /**
         * The first logger this repository has ever had, and it is here for
         * one call site: the vector arm's give-up path, which discarded the
         * exception it caught and told the person searching that the model had
         * been slow. See cheapRows().
         *
         * Defaulted so that the several tests which build this by hand keep
         * working unchanged; autowiring hands the real one to the application.
         */
        private readonly LoggerInterface $logger = new NullLogger(),
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
            // The tiebreaker ListSortOrder explains for the folder lists, for
            // the same reason in a different shape: this takes ONE row, so two
            // candidate threads sharing a lastMessageAt made "which
            // conversation does this message join" a question the database
            // answered however it liked. Nothing is lost either way, but the
            // same mailbox could thread the same message differently on two
            // runs — and a batch landing with one timestamp is ordinary, not an
            // edge case.
            ->addOrderBy('thread.id', 'DESC')
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

        // The same exclusion findForUnifiedInbox() applies, and it was missing
        // here. A thread keeps its Inbox label when it goes to the bin — the
        // Trash label is added, not swapped — so without this the total counted
        // conversations the list beside it refuses to show. That is the failure
        // the DISTINCT above is written out to prevent, arriving by the other
        // road: a paginator offering a page that does not exist.
        $this->excludeTrashed($qb);
        $this->narrowToUnread($qb, $unreadOnly);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

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
        $qb = $this->createQueryBuilder('t')
            ->select('t.category AS category', 'COUNT(DISTINCT t.id) AS threadCount')
            ->join('t.account', 'a')
            ->join('t.labels', 'l')
            ->where('a.usr = :user')
            ->andWhere('a.isActive = true')
            ->andWhere('l.role = :inbox')
            ->groupBy('t.category')
            ->setParameter('user', $user)
            ->setParameter('inbox', LabelRole::Inbox);

        $this->excludeTrashed($qb);

        $rows = $qb->getQuery()->getResult();

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
     * Unread threads per category in one grouped read.
     *
     * A GROUP BY with an aggregate, which Doctrine's API has no form of at all
     * — count() answers one number, and asking it per category would be one
     * query per tab on every page load.
     *
     * Same shape and the same joins as countByCategoryForUnifiedInbox() above,
     * narrowed to unread, so the number here and the list a tab opens are
     * answering one question. That agreement is the whole point: this feeds the
     * tab strip in the unread-only view, where a tab that lights up and then
     * opens on "No messages in this tab" would be worse than no mark at all.
     *
     * @return array<string, int>
     */
    public function countUnreadByCategoryForUnifiedInbox(UserInterface $user): array
    {
        $qb = $this->createQueryBuilder('t')
            ->select('t.category AS category', 'COUNT(DISTINCT t.id) AS threadCount')
            ->join('t.account', 'a')
            ->join('t.labels', 'l')
            ->where('a.usr = :user')
            ->andWhere('a.isActive = true')
            ->andWhere('l.role = :inbox')
            ->andWhere('t.unreadCount > 0')
            ->groupBy('t.category')
            ->setParameter('user', $user)
            ->setParameter('inbox', LabelRole::Inbox);

        $this->excludeTrashed($qb);

        $rows = $qb->getQuery()->getResult();

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
     * Never shown, still unread, AND arrived inside MessageThread::NEW_WINDOW.
     * Every count below calls this instead of writing `t.listedAt IS NULL`
     * itself, because
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
            // Read is seen. Not because listedAt is per client — it is
            // account-wide, and plMail's own surfaces already agree through it
            // — but because mail read in something that is NOT plMail arrives
            // already \Seen with no plMail surface having drawn its row. That
            // is how a dot could sit over a list with nothing bold in it.
            ->andWhere('t.unreadCount > 0')
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
            // This one is paginated, which is the case the rule exists for:
            // LIMIT/OFFSET over a non-deterministic sort can show one
            // conversation on two pages and another on none. The folder lists
            // get this from ListSortOrder::applyTo(); this method predates that
            // and spells its own order, so it has to spell the tiebreaker too.
            ->addOrderBy('thread.id', 'DESC')
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
        ?SemanticSearch   $semantic = null,
    ): SearchPage {
        $offset = ($page - 1) * $perPage;

        // The vector comes back as well as the rows, because it may not have
        // survived: see cheapRows(). Everything below this line has to use the
        // one that actually answered — running the body pass with a vector the
        // cheap pass just gave up on would spend the budget twice over for the
        // same result.
        [$rows, $semantic] = $this->cheapRows($user, $query, $sort, $perPage, $offset, $semantic);

        // Thin, so the expensive pass earns its keep — see the rescue note in
        // buildSearchSql(). Only ever reached on a page that did not fill,
        // which is the same condition the pass exists to serve.
        if (count($rows) < self::SEARCH_RESCUE_BELOW) {
            $rows = $this->rescueRows(
                fn (): array => $this->searchRows($user, $query, $sort, $perPage, $offset, true, $semantic),
                $rows,
            );
        }

        if ([] === $rows) {
            return new SearchPage([], $this->totalForEmptyPage($user, $query, $sort, $page, $semantic), [], $semantic);
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

        // Provenance rides out on the same rows it was computed from. Read as
        // ids rather than as a flag on the entity: MessageThread is a mapped
        // entity shared with every list in the application, and "how this
        // particular search found you" is a property of the search, not of the
        // conversation — a thread hydrated here goes into Doctrine's identity
        // map and would carry the answer to somebody else's question around
        // with it for the rest of the request.
        $semanticOnly = [];

        foreach ($rows as $row) {
            if (1 === (int) ($row['semantic_only'] ?? 0)) {
                $semanticOnly[] = (int) $row['thread_id'];
            }
        }

        // $semantic, not the argument: cheapRows() may have given the vector
        // up, and the reader has to be told about the search that ran.
        return new SearchPage($ordered, $total, $semanticOnly, $semantic);
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
        ?SemanticSearch   $semantic = null,
    ): int {
        if (1 === $page) {
            return 0;
        }

        [$rows, $semantic] = $this->cheapRows($user, $query, $sort, 1, 0, $semantic);

        // Spelled as "nothing at all" rather than searchPage()'s "fewer than a
        // screenful": with a limit of one, that test would fire on every
        // query and put the body scan back on a path that does not need it.
        if ([] === $rows) {
            $rows = $this->rescueRows(
                fn (): array => $this->searchRows($user, $query, $sort, 1, 0, true, $semantic),
                $rows,
            );
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
        ?SemanticSearch   $semantic = null,
    ): array {
        [$sql, $params, $types] = $this->buildSearchSql($user, $query, $withBodyRescue, $semantic);

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
     * How many recent messages the semantic arm is allowed to look at.
     *
     * The distance function costs about 0.13ms a row at 1024 dimensions, so
     * this is roughly 260ms of arithmetic — measured at 200ms for the whole CTE
     * on a 20,000-message corpus, in the same range as the full-text pass
     * beside it (89ms) and the prefix pass (111ms). Over a whole
     * 100,000-message mailbox the same loop is thirteen seconds, which is worse
     * than the pathology the UNION shape exists to avoid, so this number is not
     * a tuning knob so much as the thing that keeps the feature affordable at
     * all.
     *
     * It is now also exactly how many times the function runs, which it was
     * not: the outer join used to score every matched row again, twice. See the
     * CTE in buildSearchSql().
     *
     * Recency is the bound because it is what people search within, and because
     * it does not change between pages — a bound that moved would make
     * LIMIT/OFFSET return the same conversation twice.
     *
     * NOT because an index serves it, which this used to claim. There is no
     * index on (account_id, received_at) and adding one changes nothing
     * measurable: on 40,000 messages of 1024 dimensions the arm runs in 188 ms,
     * and building that index moves it to 186 ms. Picking the candidates is a
     * bitmap scan and a top-N heapsort of a few hundred buffers; the 2,000
     * distance calls are the entire cost. The claim was plausible, unmeasured
     * and load-bearing for nothing, and it would have sent somebody off to
     * build an index that buys 1%.
     */
    private const int SEMANTIC_CANDIDATES = 2000;

    /**
     * How far apart two unit vectors may be and still count as a hit.
     *
     * Cosine distance, so 0 is identical and 1 is unrelated. Deliberately a
     * THRESHOLD rather than "the nearest k": the total rides on the same
     * statement as `COUNT(*) OVER ()`, and a top-k arm would make that total
     * "lexical matches plus up to k", which is only true on the first page.
     *
     * Erring tight. A semantic search that quietly widens every result set is
     * indistinguishable from a search that has stopped working.
     */
    private const float SEMANTIC_MAX_DISTANCE = 0.45;

    /**
     * How long the body-substring pass gets before it is abandoned.
     *
     * The pass is measured at about 3 seconds on a 300,000-message corpus —
     * see the rescue note in buildSearchSql() — so this is generous for a
     * healthy mailbox and a hard stop for one where it is not. It has to be a
     * DATABASE timeout rather than a PHP one: `max_execution_time` kills the
     * PHP process and leaves Postgres still running the scan, so the work
     * continues after the person who asked for it has already been shown a
     * 500. `statement_timeout` stops the actual query.
     *
     * Higher than the type-ahead's 1500ms because the two are different
     * promises. A dropdown that misses a keystroke costs nothing; a search
     * somebody typed and pressed enter on has earned a few seconds.
     */
    private const int SEARCH_RESCUE_TIMEOUT_MS = 5000;

    /**
     * How long a search carrying a vector gets before the vector is dropped.
     *
     * WHY A SEARCH WITH A VECTOR NEEDS ITS OWN STOP
     * ─────────────────────────────────────────────
     * The semantic arm is bounded by SEMANTIC_CANDIDATES, and that bound is
     * arithmetic the planner is free to ignore: it can decide the CTE is cheap,
     * push the join the wrong way round, or lose the index that serves the
     * recency ordering, and then plmail_embed_distance() runs over the mailbox
     * instead of over two thousand rows. That is not hypothetical — it is what
     * 47 seconds on /mail/search was, and no amount of correct SQL prevents a
     * plan nobody can see from a production request.
     *
     * Measured at 0.63s for the whole statement over 2,000 candidates of 1024
     * dimensions, so this is eight times the healthy cost and a hard stop for a
     * plan that has gone wrong.
     *
     * The same 5000 as the body pass, and deliberately: the two are the only
     * unbounded-in-practice things in a search, so one number is also the
     * promise — a search spends at most about ten seconds in the database
     * before it answers with whatever it has. They are separate constants
     * because they guard separate work, not because they are expected to
     * differ.
     */
    private const int SEARCH_SEMANTIC_TIMEOUT_MS = 5000;

    /**
     * `query_canceled` — what `statement_timeout` raises when it fires.
     *
     * Spelled here because DBAL does not name it. Its PostgreSQL
     * ExceptionConverter maps deadlocks, constraint violations, syntax errors
     * and half a dozen others onto dedicated classes and lets 57014 fall
     * through to a plain DriverException, so "the budget fired" and "the query
     * is broken" arrive at cheapRows() as the same type. The SQLSTATE is the
     * only thing that tells them apart, and the difference is which machine
     * somebody is sent to look at.
     */
    private const string SQLSTATE_QUERY_CANCELED = '57014';

    /**
     * Run the body-substring pass under a time budget, keeping whatever the
     * cheap passes already found if it does not come back in time.
     *
     * WHY THIS EXISTS
     * ───────────────
     * buildSearchSql() has carried a note for a while saying this pass once
     * took a term "from 13 seconds to a 30-second PHP timeout". The note was
     * right and the mitigation was incomplete: making the pass conditional
     * made it RARE, not bounded, and on a live mailbox it duly produced
     *
     *     MaxExecutionTimeError: Maximum execution time of 30 seconds exceeded
     *
     * The pass is a last resort by its own docblock — it exists for a needle
     * full-text cannot see, and since the tokenizer split the index answers
     * that case directly. Giving up on it costs a genuine edge. Spending the
     * whole request on it costs the search.
     *
     * So: bounded, and on expiry the cheap results stand. A search that
     * returns the eleven things full-text found is a search; a search that
     * 500s after thirty seconds is not.
     *
     * @param callable(): list<array<string,mixed>> $rescue
     * @param list<array<string,mixed>>             $fallback
     *
     * @return list<array<string,mixed>>
     */
    private function rescueRows(callable $rescue, array $fallback): array
    {
        try {
            $budget = $this->rescueTimeoutMs;

            /** @var list<array<string,mixed>> $rows */
            $rows = $this->getEntityManager()->getConnection()->transactional(
                static function (Connection $connection) use ($rescue, $budget): array {
                    // SET LOCAL, so it leaves with the transaction. A plain SET
                    // would strand the timeout on a pooled connection and hand
                    // it to whatever ran next — the same reasoning, and the
                    // same wording, as TypeAheadSearch::fetch().
                    $connection->executeStatement(
                        'SET LOCAL statement_timeout = ' . $budget,
                    );

                    return $rescue();
                },
            );

            return $rows;
        } catch (DriverException) {
            return $fallback;
        }
    }

    /**
     * The cheap pass, with its vector under a time budget — and WITHOUT the
     * vector if the budget runs out.
     *
     * WHY THIS EXISTS
     * ───────────────
     * Semantic search took 12.9 seconds mean and 47 seconds worst case in
     * production, and the shape that caused it is fixed above: the distance is
     * computed once, on candidates only. What is not fixed, and cannot be, is
     * that a query plan is a decision somebody else makes at runtime. The
     * arithmetic in buildSearchSql() bounds the work to SEMANTIC_CANDIDATES
     * rows; it does not bind the planner to agree.
     *
     * So the vector gets a budget, and losing it is a DEGRADED SEARCH rather
     * than a failed one. A page of the eleven things the words found is a
     * search. A page that arrives after 47 seconds — or does not arrive,
     * because PHP gave up at 30 — is not.
     *
     * WHAT IS AND IS NOT INSIDE THE TRANSACTION
     * ─────────────────────────────────────────
     * Only the query. The embedding round trip happens in SearchController,
     * before any of this, and it must stay there: a slow model inside a
     * `statement_timeout` transaction would expire the budget and be reported
     * as a database fault, which is the one diagnosis that would send somebody
     * looking in the wrong place entirely.
     *
     * `SET LOCAL`, so the timeout leaves with the transaction — a plain SET
     * would strand it on a pooled connection and hand it to whatever ran next.
     * Same reasoning and same wording as rescueRows() and
     * TypeAheadSearch::fetch().
     *
     * NOT A GUARD ON THE QUERY AS A WHOLE. A search with no vector is not
     * wrapped at all, so it produces the statement it always did, on a
     * connection with the server's own timeout — the same distinction
     * testTheBudgetDoesNotAffectTheCheapPass() draws for the body pass.
     *
     * @return array{list<array<string,mixed>>, ?SemanticSearch} the rows, and
     *         the vector that actually produced them — null when it was dropped
     */
    private function cheapRows(
        UserInterface     $user,
        ParsedSearchQuery $query,
        SearchSortOrder   $sort,
        int               $perPage,
        int               $offset,
        ?SemanticSearch   $semantic,
    ): array {
        $pass = fn (?SemanticSearch $with): array => $this->searchRows(
            $user,
            $query,
            $sort,
            $perPage,
            $offset,
            false,
            $with,
        );

        if (null === $semantic?->literal) {
            return [$pass(null), null];
        }

        $budget = $this->semanticTimeoutMs;

        try {
            /** @var list<array<string,mixed>> $rows */
            $rows = $this->getEntityManager()->getConnection()->transactional(
                static function (Connection $connection) use ($pass, $semantic, $budget): array {
                    $connection->executeStatement(
                        'SET LOCAL statement_timeout = ' . $budget,
                    );

                    return $pass($semantic);
                },
            );

            return [$rows, $semantic];
        } catch (DriverException $exception) {
            // Run again without it, and OUTSIDE the transaction the timeout
            // was set on: the keyword statement is the one this search has
            // always been able to afford, and putting the budget that the
            // vector just exhausted around it as well would turn a degraded
            // search into an empty one.
            //
            // SAYING SO, rather than handing back a bare null. A null here
            // reaches the page as "searching by meaning found nothing the
            // words had not already" — a sentence about a search that ran and
            // came up empty, describing one that never finished. That silent
            // degradation is the exact thing the skip reasons were built to
            // stop, and it would have been reintroduced by the timeout that
            // was added to protect the page.
            //
            // AND SAYING WHICH, WHICH IS THE PART THIS USED TO GET WRONG.
            // It answered TimedOut for everything it caught, and TimedOut
            // prints "the model took too long" — a sentence about the OTHER
            // machine. The model had already answered before this method was
            // entered; SemanticQuery does the embedding in the controller,
            // precisely so that a slow model is never reported as a database
            // fault. This was the same confusion pointing the other way, and
            // it is the worse direction: an operator reading "the model took
            // too long" goes to the model host, where the panel shows every
            // search_query call succeeding in single-digit milliseconds, and
            // there is nothing else for them to find.
            //
            // Worse still, DriverException is not "the statement was
            // cancelled". It is every fault the driver can raise — a
            // plmail_embed_distance() that is not installed, a stored width
            // that disagrees with its vector, a connection dropped mid-query —
            // and each of those is DETERMINISTIC. They do not degrade a search
            // occasionally under load; they degrade EVERY search, instantly,
            // for as long as the cause stands, while reporting a timeout that
            // never happened and logging nothing at all.
            $cancelled = self::SQLSTATE_QUERY_CANCELED === $exception->getSQLState();

            // Both branches log, and both log the SQLSTATE. The give-up path is
            // meant to be rare, and a rare thing that leaves no trace is
            // indistinguishable from a common one — which is exactly how this
            // stayed invisible.
            $this->logger->log(
                $cancelled ? 'warning' : 'error',
                $cancelled
                    ? 'Search: the semantic pass was cancelled at its budget; results are lexical only'
                    : 'Search: the semantic pass failed; results are lexical only',
                [
                    'sqlstate'  => $exception->getSQLState(),
                    'budget_ms' => $budget,
                    'exception' => $exception,
                ],
            );

            return [
                $pass(null),
                SemanticSearch::skipped(
                    $cancelled ? SemanticSkipReason::SearchTooSlow : SemanticSkipReason::SearchFailed,
                ),
            ];
        }
    }

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
        ?SemanticSearch   $semantic = null,
    ): array {
        $params = [];
        $types  = [];
        $where  = ['a.usr_id = :userId', 'a.is_active = true'];

        $params['userId'] = $user->id;
        $types['userId']  = ParameterType::INTEGER;

        // MAX(0) rather than 0: this is one of the aggregate columns of a
        // GROUP BY, and a search with no free text at all still has to produce
        // the `rank` alias the sort orders by.
        $rankSelect     = 'MAX(0)';
        $semanticColumn = '';
        $semanticRank   = null;
        $semanticCte    = '';

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
                $where[]    = 'false';
                $rankSelect = 'MAX(0)';

                $mine       = 'false';
                $mineRecent = 'false';
            } else {
                $params['searchAccounts'] = $accountIds;
                $types['searchAccounts']  = ArrayParameterType::INTEGER;

                $mine = 'm.account_id IN (:searchAccounts)';

                // THE SAME PREDICATE FOR THE OTHER ALIAS, AND IT IS NOT
                // DUPLICATION FOR ITS OWN SAKE.
                //
                // The semantic arm's candidate subquery scans `message` a
                // second time under the alias `m2`, and it used to be handed
                // $mine — which names `m`. That is legal SQL and it means
                // something entirely different: a reference to the OUTER row,
                // which correlates the subquery, and a scan of `m2` with no
                // filter on it whatsoever.
                //
                // Postgres showed both halves of the damage in one plan. The
                // account test became `One-Time Filter: (m.account_id = ANY
                // (...))` — a per-outer-row constant — over a bare
                // `Seq Scan on message m2`, and the whole Limit/Sort landed on
                // the inner side of a nested loop, so the ENTIRE message table
                // was sorted again for every candidate row being tested.
                //
                // The correctness half is the quieter one and it outlives any
                // plan: LIMIT 2000 was taking the 2,000 most recent rows in the
                // TABLE and only then filtering to this user's accounts. The
                // outer WHERE keeps that safe — nobody ever saw another
                // mailbox — but on any installation with more than one busy
                // account, most of the window was spent on mail that was
                // filtered away, and the searcher got a fraction of the 2,000
                // candidates the constant promises.
                $mineRecent = 'm2.account_id IN (:searchAccounts)';
            }

            $cheap = ["SELECT m.id FROM message m WHERE {$mine} AND m.search_vector @@ websearch_to_tsquery('english', :freeText)"];
            $ranks = ["ts_rank(m.search_vector, websearch_to_tsquery('english', :freeText))"];

            // ── What a row would have matched WITHOUT the vector ──────
            // One entry per lexical arm above, as a per-row predicate rather
            // than as an id-producing SELECT. It is the same question the UNION
            // answers, asked of a row that is already in hand: which is the
            // whole point, because asking the UNION again to find out where a
            // row came from would run every scan in it a second time, and those
            // scans are the search.
            //
            // Only ever added to the statement when there is a semantic arm to
            // attribute rows to — see below. A search with the feature off
            // produces byte-for-byte the SQL it always did.
            $lexical = ["m.search_vector @@ websearch_to_tsquery('english', :freeText)"];

            if (null !== $free->prefix) {
                $cheap[]              = "SELECT m.id FROM message m WHERE {$mine} AND m.search_vector @@ to_tsquery('english', :freePrefix)";
                $ranks[]              = "ts_rank(m.search_vector, to_tsquery('english', :freePrefix))";
                $lexical[]            = "m.search_vector @@ to_tsquery('english', :freePrefix)";
                $params['freePrefix'] = $free->prefix;
            }

            // ── The semantic arm ──────────────────────────────────────
            // The one pass that is not made of words, and the one that has to
            // be paid for by the row. Everything about what it costs and why it
            // is shaped this way is inside the branch, next to the SQL it
            // describes — this comment used to carry it, and drifted: it still
            // said 0.11ms and "an inline distance in this arm" a version after
            // both had stopped being true.
            if (null !== $semantic?->literal) {
                // ONE PLACE WHERE THE DISTANCE IS COMPUTED, AND IT IS HERE.
                //
                // This used to be an inline distance in the arm AND a second
                // one in the outer query, where a LEFT JOIN to
                // message_embedding fed plmail_embed_distance() a row at a
                // time. Two things went wrong with that, and they multiplied.
                //
                // The outer join had no candidate restriction, so it scored
                // every embedded message the statement had matched — including
                // every row a KEYWORD had found, which is most of them. And
                // DBAL defeated the sharing the old comment here relied on:
                // one `:queryVector` written twice is expanded into two
                // separate positional parameters, so what Postgres saw was two
                // textually different expressions and it evaluated both.
                // Measured on 20,000 rows of 1024-dimension vectors, a
                // 25%-selective term: 12,616 calls and 3.8 SECONDS. Production
                // ran ~92,000 calls at 0.132ms and took 12.9s mean, 47s max.
                //
                // Scored here instead, the function runs exactly
                // SEMANTIC_CANDIDATES times — 2,000 calls, 0.63s on the same
                // corpus — and `plmail_embed_distance` does not appear in the
                // outer query at all. Same rows, same provenance flags; the
                // measurement is in the commit that introduced this.
                //
                // MATERIALIZED IS LOAD-BEARING, NOT DECORATION. sem_hits is
                // referenced twice below (the UNION arm and the LEFT JOIN), and
                // an inlined CTE is re-evaluated at every reference — which is
                // the bug being fixed, reintroduced by the planner. Spelled
                // NOT MATERIALIZED the same statement measures 4,369 calls and
                // 2.2s, three and a half times the cost, for identical rows.
                //
                // TWO CTEs RATHER THAN ONE, and that is worth 369 calls. With
                // the threshold applied to a derived table inside sem_scores,
                // Postgres flattens the subquery, `similarity` lands in both
                // the filter and the target list, and every row that PASSES is
                // scored a second time. Materialising the scores and filtering
                // the materialised output is one evaluation per candidate,
                // full stop: 2,369 calls became 2,000.
                //
                // NO CALL IS MADE FROM PHP HERE. The vector arrives already
                // computed — buildSearchSql() runs up to four times per search,
                // once inside a statement-timeout transaction, and a round trip
                // to another machine in any of them would be a search that
                // sometimes takes four times as long for no reason a user could
                // see.
                //
                // BOUNDED, AND THE BOUND IS THE WHOLE DESIGN. The distance
                // function costs about 0.13ms a row at 1024 dimensions; over
                // 100,000 messages that is thirteen seconds, which is worse
                // than the pathology the UNION was written to escape. So it
                // only ever runs over the most recent SEMANTIC_CANDIDATES
                // messages, chosen by an ordering that does not change between
                // pages — a bound that moved would make LIMIT/OFFSET return the
                // same conversation twice.
                //
                // THAT BOUND WAS NOT HOLDING, AND THE ALIAS IS WHY. The
                // subquery below scans `message` again as `m2`, and it was
                // given $mine, which names `m`. Postgres read that as a
                // reference to the outer row, correlated the subquery, and put
                // the whole Limit/Sort on the inner side of a nested loop with
                // `Seq Scan on message m2` carrying no filter at all — the
                // entire table re-sorted for every candidate row tested.
                // Measured on 40,000 messages: cancelled at the 5,000 ms
                // budget, every time, which reaches the searcher as
                // SearchTooSlow. With $mineRecent it is a Hash Semi Join
                // evaluated once, 2,000 rows scored, 188 ms.
                //
                // The inner LIMIT is parenthesised because an unparenthesised
                // ORDER BY or LIMIT would bind to the wrong query level.
                // :queryVector is already a PostgreSQL literal, normalised by
                // the caller — DBAL cannot name `real[]` as a parameter type,
                // and the cast is what turns the bound string back into an
                // array. It is now bound EXACTLY ONCE in the whole statement.
                //
                // SCOPED TO ONE MODEL, AND THAT IS NOT TIDINESS. Vectors from
                // two embedding models are not comparable, and neither
                // implementation of plmail_embed_distance() says so: the
                // shipped plpgsql loop compares whatever the two arrays have in
                // common and answers a plausible number, and on an installation
                // with pgvector the cast raises `different vector dimensions` —
                // a 500 on /mail/search for everybody, caused by a dropdown in
                // the admin panel. Matching on model AND width means a mailbox
                // half re-indexed after a model change searches the half that
                // matches and ignores the rest. The index
                // idx_message_embedding_model is (model, dimensions) for this.
                $semanticCte = <<<SQL
                    WITH sem_scores AS MATERIALIZED (
                        SELECT e.message_id,
                               1 - plmail_embed_distance(e.embedding, CAST(:queryVector AS real[])) AS similarity
                          FROM message m
                          JOIN message_embedding e ON e.message_id = m.id
                                                  AND e.model = :semanticModel
                                                  AND e.dimensions = :semanticDimensions
                         WHERE {$mine}
                           AND m.id IN (
                               SELECT recent.id FROM (
                                   SELECT m2.id FROM message m2
                                    WHERE {$mineRecent}
                                    ORDER BY m2.received_at DESC NULLS LAST, m2.id DESC
                                    LIMIT :semanticCandidates
                               ) recent
                           )
                    ), sem_hits AS (
                        SELECT message_id, similarity FROM sem_scores WHERE similarity >= :semanticSimilarity
                    )

                    SQL;

                // An id-producing arm of the same UNION, so a vector hit is a
                // candidate on exactly the same footing as a lexical one: it
                // lands in COUNT(*) OVER (), it pages correctly, and it needs
                // no second statement to reconcile with.
                $cheap[] = 'SELECT message_id FROM sem_hits';

                // A COLUMN NOW, NOT AN EXPRESSION. Similarity, so it is on the
                // same "bigger is better" footing as ts_rank and GREATEST can
                // mix them; a message the vector did not find gives NULL here,
                // which GREATEST ignores.
                //
                // Still held in a variable because the provenance column below
                // binds the SAME text, and Postgres shares identical aggregate
                // calls — but now that is a convenience rather than the thing
                // holding the cost down. There is no arithmetic left in it to
                // share.
                $semanticRank = 'sem.similarity';

                $ranks[] = $semanticRank;

                // The arm's threshold, said the way the arm reads it. The
                // constant is a DISTANCE and this is a similarity, and 1 - d is
                // the conversion — done once, here, so the arm, the rank and
                // the provenance column below cannot come to disagree about
                // what counts as a hit. Before this they were two comparisons
                // (`d <= 0.45` in the arm, `1 - d >= 0.55` in the column) that
                // agreed everywhere except within one ULP of the boundary.
                $params['semanticSimilarity']  = 1 - self::SEMANTIC_MAX_DISTANCE;
                $params['queryVector']         = $semantic->literal;
                $params['semanticModel']       = $semantic->model;
                $params['semanticDimensions']  = $semantic->dimensions;
                $params['semanticCandidates']  = $this->semanticCandidates;
                $types['semanticDimensions']   = ParameterType::INTEGER;
                $types['semanticCandidates']   = ParameterType::INTEGER;
            }

            if (null !== $free->substring) {
                // Subject and the two sender columns, but NOT the body: those
                // three are narrow, so the trigram index's recheck reads almost
                // nothing and they cost microseconds.
                foreach (['subject', 'from_name', 'from_address'] as $column) {
                    $cheap[]   = "SELECT m.id FROM message m WHERE {$mine} AND m.{$column} ILIKE :freeLike";
                    $lexical[] = "m.{$column} ILIKE :freeLike";
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

                // The one lexical arm whose per-row form is not free: it reads
                // the body of every row the statement is about to return, which
                // is the same detoast the pass itself pays, over the far
                // smaller set that actually matched. Left out, a message the
                // body pass found and the vector also liked would be labelled
                // "found by meaning", which is the one thing this column exists
                // not to get wrong. Only ever in the rescue statement, which is
                // rare by construction and already the expensive one.
                $lexical[] = 'm.body_text ILIKE :freeLike';
            }

            $where[] = 'm.id IN (' . implode(' UNION ', $cheap) . ')';

            // A row reached only by the substring pass has no ts_rank to give;
            // GREATEST over what did match keeps relevance ordering meaningful
            // instead of letting a NULL sink every such row to the bottom.
            //
            // GREATEST(MAX(a), MAX(b)) rather than MAX(GREATEST(a, b)), which
            // is the same number — the largest of the largests is the largest —
            // and not the same statement. Postgres shares identical aggregate
            // calls, so writing MAX(semantic) here and MAX(semantic) again in
            // the provenance column below computes the distance ONCE per row.
            // Nested inside a GREATEST the two are no longer identical calls,
            // and every candidate row would pay for the distance function
            // twice.
            $rankParts  = array_map(static fn (string $rank): string => "MAX({$rank})", $ranks);
            $rankSelect = 1 === count($rankParts) ? $rankParts[0] : 'GREATEST(' . implode(', ', $rankParts) . ')';

            // ── Where did this row come from ──────────────────────────
            // One column, computed from aggregates the statement is already
            // producing: it matched the vector, and no arm made of words would
            // have found it. That distinction is the entire point — somebody
            // who typed a literal string needs to know why an apparently
            // unrelated message is in the list, and a row the words found needs
            // no explaining.
            //
            // Read per THREAD, over the messages of that thread that matched.
            // A conversation whose first message matched the words and whose
            // fourth happens to sit near the vector was found by the words, and
            // BOOL_OR over the group says so.
            //
            // No extra join and no extra scan: `sem` is already joined for the
            // rank, and the lexical predicates are re-asked of rows that are
            // already in hand rather than by running the UNION again.
            //
            // COALESCE because a lexical arm can be NULL rather than false — a
            // message with no subject ILIKEs to NULL — and BOOL_OR over nothing
            // but NULLs is NULL, which would make the CASE fall through to a
            // row claiming a provenance it never had.
            //
            // The `>= :semanticSimilarity` is now redundant on its face —
            // sem_hits contains nothing below the threshold, so a non-NULL
            // MAX() is already a hit. It is kept because it is what makes the
            // NULL case explicit: a row with no sem_hits match gives NULL, and
            // `NULL >= x` is NULL, which is what sends the CASE to 0. Written
            // as `MAX(...) IS NOT NULL` the reader would have to go and find
            // out where the threshold went.
            if (null !== $semanticRank) {
                $lexicalHit = '(' . implode(' OR ', $lexical) . ')';

                $semanticColumn = ",\n                CASE WHEN MAX({$semanticRank}) >= :semanticSimilarity"
                    . " AND NOT COALESCE(BOOL_OR({$lexicalHit}), false)"
                    . " THEN 1 ELSE 0 END AS semantic_only";
            }
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

        // ── The label joins, only when something asks about a label ───────
        // `lbl` is referenced by exactly two filters — `label:` and the
        // mailbox role behind `in:` — and by nothing else in this statement.
        // Joined unconditionally, as they were, they are not merely idle: both
        // are to-many, so every (thread, matching message) row is multiplied by
        // the number of labels the thread carries before the GROUP BY collapses
        // it back down. A mailbox where threads sit in an inbox, a category and
        // a user label pays three times over, on every free-text search, to
        // produce a set it then throws away.
        //
        // The result was always right, which is why this survived: GROUP BY
        // hides the duplication perfectly and only the clock shows it.
        // One row per message, so this cannot multiply anything — which is the
        // property the label joins above lost and had to be given back in
        // v0.1.40. LEFT, because most messages are not vector hits and every
        // one of them still has to be able to match lexically.
        //
        // TO THE CTE, NOT TO message_embedding. This join used to reach the
        // embedding table directly with no candidate restriction, and the rank
        // above it then ran plmail_embed_distance() over every embedded message
        // the statement had matched — twice, once for `rank` and once for
        // `semantic_only`. That was the search: 12,616 calls where 2,000 were
        // needed. Joining the scored candidates instead means the outer query
        // reads a `real` column, and the only model/width matching left in the
        // statement is the one inside sem_scores, where it belongs — it guards
        // the side that FEEDS the distance function, and an unmatched row there
        // is either a meaningless number or, with pgvector installed, an error
        // that takes the whole search page with it.
        $semanticJoin = null === $semantic?->literal
            ? ''
            : "\n            LEFT JOIN sem_hits sem ON sem.message_id = m.id";

        $labelJoins = '';

        if (null !== $query->label || null !== $query->mailboxRole) {
            $labelJoins = "\n            LEFT JOIN thread_label tl ON tl.message_thread_id = t.id"
                . "\n            LEFT JOIN label lbl ON lbl.id = tl.label_id";
        }

        // The CTE rides in front of the SELECT rather than being spliced into
        // it, so a search with no vector produces byte-for-byte the statement
        // it always did. searchRows() appends ORDER BY / LIMIT / OFFSET to
        // whatever comes back, which a leading WITH does not disturb.
        $sql = $semanticCte . <<<SQL
            SELECT
                t.id                                              AS thread_id,
                {$rankSelect}                                     AS rank,
                MAX(t.last_message_at)                            AS last_message_at,
                COUNT(*) OVER ()                                  AS total_threads{$semanticColumn}
            FROM message_thread t
            JOIN message m ON m.thread_id = t.id
            JOIN account a ON a.id = t.account_id{$semanticJoin}{$labelJoins}
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
     *
     * Threads whose category was chosen by hand are excluded, which is the same
     * rule MessageThreader::adoptCategory() applies one row at a time. It has
     * to be stated in both places: the backfill does not go through the
     * threader, so a run of `app:backfill category` would otherwise wipe every
     * category anybody had ever dragged a conversation into — the whole install
     * at once, which is worse than the arrival-time case the flag was written
     * for.
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
        WHERE t.id = sub.thread_id
          AND t.account_id = :accountId
          AND t.category_pinned_at IS NULL
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
            // Snoozing a selection gives every thread in it the same wake time,
            // so ties here are the norm rather than the exception. Everything
            // due is woken eventually whatever the order, but with a cap on the
            // batch the boundary is arbitrary without this — and a sweep that
            // processes the same backlog in a different order each time is
            // needlessly hard to reason about when one of them goes wrong.
            ->addOrderBy('t.id', 'ASC')
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
