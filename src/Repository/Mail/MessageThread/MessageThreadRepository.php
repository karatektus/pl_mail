<?php

namespace App\Repository\Mail\MessageThread;


use App\Domain\DTO\Ai\SemanticSearch;
use App\Domain\DTO\Mail\InboxTab;
use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\ListSortOrder;
use App\Domain\Enum\Mail\MessageCategory;
use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Doctrine's repository for MessageThread: the thread lists, one per view (the
 * inboxes, a role, a label, All Mail, Starred, Unread), their counts, and the
 * preloads a page of rows needs.
 *
 * The rest sits beside it in this folder, one class per topic: badges, search,
 * categories, snooze, rebuilding and JMAP. MessageThreadListCriteria holds the
 * conditions the lists and their badges share. Not final, because tests
 * double it.
 */
class MessageThreadRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MessageThread::class);
    }

    /**
     * The inbox as one list across every active account.
     *
     * QueryBuilder because the filter spans three entities: the owner is on
     * Account, the Inbox role is on a Label reached through a to-many, and only
     * the category is on the thread itself. findBy() filters on fields of one
     * entity, so none of the two joins — nor the DISTINCT they make necessary —
     * is available to it.
     *
     * A null category is the whole inbox, for somebody who has switched the
     * tabs off — see CategorySorting::$tabs. Null and not a default argument,
     * so that every caller has to say which of the two it means.
     *
     * $accountIds narrows it to one of the reader's unified inboxes (#265) —
     * UnifiedInboxes::accountIdsOf(). Null is every account, which is what
     * JMAP and anything else that is not drawing one of those inboxes means.
     *
     * @param list<int>|null $accountIds
     */
    public function findForUnifiedInbox(UserInterface $user, MessageCategory|InboxTab|null $category, int $page = 1, int $perPage = 50, ListSortOrder $sort = ListSortOrder::Newest, bool $unreadOnly = false, ?array $accountIds = null): array
    {
        $offset = ($page - 1) * $perPage;

        $qb = $this->createQueryBuilder('t')
            ->join('t.account', 'a')
            ->join('t.labels', 'l')
            ->where('a.usr = :user')
            ->andWhere('a.isActive = true')
            ->andWhere('l.role = :inbox')
            ->setParameter('user', $user)
            ->setParameter('inbox', LabelRole::Inbox)
            ->setFirstResult($offset)
            ->setMaxResults($perPage)
            ->distinct();

        $this->narrowToCategory($qb, $category, MessageThreadListCriteria::switchedOffBy($user));
        MessageThreadListCriteria::narrowToAccounts($qb, $accountIds);

        MessageThreadListCriteria::excludeTrashed($qb);
        $this->narrowToUnread($qb, $unreadOnly);

        $sort->applyTo($qb);

        return $qb->getQuery()->getResult();
    }

    /**
     * One tab of the inbox, or all of it for null. Shared by the list and its
     * count so the two cannot come to mean different things — a pager offering
     * a page the list does not have is what that looks like.
     */
    /**
     * @param list<string> $absorbed built-in values switched off — their
     *                               conversations are listed under Primary
     */
    private function narrowToCategory(QueryBuilder $qb, MessageCategory|InboxTab|null $category, array $absorbed = []): void
    {
        if (null === $category) {
            return;
        }

        $tab = $category instanceof MessageCategory ? InboxTab::builtIn($category) : $category;

        // A thread is in exactly ONE tab: the reader's own when it has been
        // filed in one, else the built-in one. So a custom tab is a plain match
        // on the reader's column, and a built-in tab must also exclude whatever
        // has left it for one of the reader's — otherwise a carrier's notice is
        // listed in Promotions AND in Shipping, and the tab counts stop adding
        // up to the inbox.
        if (true === $tab->isCustom()) {
            $qb->andWhere('IDENTITY(t.userCategory) = :userCategory')
                ->setParameter('userCategory', $tab->categoryId);

            return;
        }

        // Primary also holds whatever is in a category the reader switched
        // off. The re-sort a switch queues moves those messages for good, but
        // it takes minutes on a big mailbox, and a conversation somebody
        // pinned there is not its to move: until then they would be in no tab
        // at all.
        if (MessageCategory::Primary === $tab->builtIn && [] !== $absorbed) {
            $qb->andWhere('t.userCategory IS NULL')
                ->andWhere('t.category IN (:categories)')
                ->setParameter('categories', [MessageCategory::Primary->value, ...$absorbed]);

            return;
        }

        $qb->andWhere('t.userCategory IS NULL')
            ->andWhere('t.category = :category')
            ->setParameter('category', $tab->builtIn);
    }

    /**
     * Same two joins as findForUnifiedInbox(), so the same reason to keep it.
     *
     * @param list<int>|null $accountIds see findForUnifiedInbox()
     */
    public function countForUnifiedInbox(UserInterface $user, MessageCategory|InboxTab|null $category, bool $unreadOnly = false, ?array $accountIds = null): int
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
            ->setParameter('user', $user)
            ->setParameter('inbox', LabelRole::Inbox);

        $this->narrowToCategory($qb, $category, MessageThreadListCriteria::switchedOffBy($user));
        MessageThreadListCriteria::narrowToAccounts($qb, $accountIds);

        // The same exclusion findForUnifiedInbox() applies, and it was missing
        // here. A thread keeps its Inbox label when it goes to the bin — the
        // Trash label is added, not swapped — so without this the total counted
        // conversations the list beside it refuses to show. That is the failure
        // the DISTINCT above is written out to prevent, arriving by the other
        // road: a paginator offering a page that does not exist.
        MessageThreadListCriteria::excludeTrashed($qb);
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
     * @param list<int>|null $accountIds one unified inbox's accounts, see findForUnifiedInbox()
     */
    public function countByCategoryForUnifiedInbox(UserInterface $user, ?array $accountIds = null): array
    {
        return $this->countTabsForUnifiedInbox($user, $accountIds)['threads'];
    }

    /**
     * Every tab's thread count and unread count, in one read of the inbox.
     *
     * The inbox page used to ask three questions that each read the reader's
     * whole inbox: this tab's total for the pager, every tab's total for which
     * tabs exist, and every tab's unread for the icon tint. All three are the
     * same rows grouped the same way, so one pass answers them: the pager's
     * number is one entry of the totals (or of the unread, behind the unread
     * filter). On a 16,700-thread inbox that was 27 ms in three queries and is
     * 7 ms in this one; on the NAS plMail runs on, every millisecond here is
     * four or five.
     *
     * The Inbox label is tested with EXISTS rather than joined. The join is
     * to-many, which is why the other counts say COUNT(DISTINCT t.id), and the
     * DISTINCT makes Postgres sort every inbox row by id before it can count.
     * EXISTS asks "has at least one Inbox label" once per thread, so a thread
     * carrying two labels of the role is still one row and a plain COUNT is
     * right.
     *
     * @param list<int>|null $accountIds one unified inbox's accounts, see findForUnifiedInbox()
     *
     * @return array{threads: array<string, int>, unread: array<string, int>}
     */
    public function countTabsForUnifiedInbox(UserInterface $user, ?array $accountIds = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->select(
                't.category AS category',
                'IDENTITY(t.userCategory) AS userCategory',
                'COUNT(t.id) AS threadCount',
                'SUM(CASE WHEN t.unreadCount > 0 THEN 1 ELSE 0 END) AS unreadCount',
            )
            ->join('t.account', 'a')
            ->where('a.usr = :user')
            ->andWhere('a.isActive = true')
            ->andWhere('EXISTS (SELECT il.id FROM ' . Label::class . ' il WHERE il.role = :inbox AND il MEMBER OF t.labels)')
            ->groupBy('t.category')
            ->addGroupBy('t.userCategory')
            ->setParameter('user', $user)
            ->setParameter('inbox', LabelRole::Inbox);

        MessageThreadListCriteria::excludeTrashed($qb);
        MessageThreadListCriteria::narrowToAccounts($qb, $accountIds);

        $rows     = $qb->getQuery()->getResult();
        $absorbed = MessageThreadListCriteria::switchedOffBy($user);

        return [
            'threads' => MessageThreadListCriteria::foldByTab($rows, 'threadCount', $absorbed),
            'unread'  => array_filter(MessageThreadListCriteria::foldByTab($rows, 'unreadCount', $absorbed), static fn (int $count): bool => $count > 0),
        ];
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
     * @param list<int>|null $accountIds one unified inbox's accounts, see findForUnifiedInbox()
     */
    public function countUnreadByCategoryForUnifiedInbox(UserInterface $user, ?array $accountIds = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->select('t.category AS category', 'IDENTITY(t.userCategory) AS userCategory', 'COUNT(DISTINCT t.id) AS threadCount')
            ->join('t.account', 'a')
            ->join('t.labels', 'l')
            ->where('a.usr = :user')
            ->andWhere('a.isActive = true')
            ->andWhere('l.role = :inbox')
            ->andWhere('t.unreadCount > 0')
            ->groupBy('t.category')
            ->addGroupBy('t.userCategory')
            ->setParameter('user', $user)
            ->setParameter('inbox', LabelRole::Inbox);

        MessageThreadListCriteria::excludeTrashed($qb);
        MessageThreadListCriteria::narrowToAccounts($qb, $accountIds);

        return MessageThreadListCriteria::foldByTab($qb->getQuery()->getResult(), 'threadCount', MessageThreadListCriteria::switchedOffBy($user));
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
            MessageThreadListCriteria::excludeTrashed($qb);
        }

        $this->onlyLiveDrafts($qb, $role);

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
            MessageThreadListCriteria::excludeTrashed($qb);
        }

        $this->onlyLiveDrafts($qb, $role);

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

        MessageThreadListCriteria::narrowToAccount($qb, $account);
        $this->excludeTrashedUnlessBin($qb, $label);
        $this->onlyLiveDrafts($qb, $label->role);
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

        MessageThreadListCriteria::narrowToAccount($qb, $account);
        $this->excludeTrashedUnlessBin($qb, $label);
        $this->onlyLiveDrafts($qb, $label->role);
        $this->narrowToUnread($qb, $unreadOnly);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Drafts lists a conversation for a draft that is still a draft, and not
     * for one in the bin.
     *
     * A thread's labels are its messages' together (ThreadLabelSynchronizer),
     * so a draft thrown away into Trash keeps Drafts on its conversation, and
     * the conversation is not wholly in the bin while the mail it answers is
     * not — excludeTrashed() lets it through. It was listed in Drafts, opened
     * on the live side (BinView), and the draft it was listed for was not in
     * it. A thrown-away draft is not one being worked on: it is in Trash, and
     * back here once it is restored.
     *
     * A correlated sub-query, which excludeTrashed() stopped being for speed
     * (#223). Only the Drafts lists pay it, over the few conversations that
     * carry Drafts at all.
     */
    private function onlyLiveDrafts(QueryBuilder $qb, ?LabelRole $role): void
    {
        if (LabelRole::Drafts !== $role) {
            return;
        }

        $qb->andWhere(sprintf(
            'EXISTS (SELECT liveDraft.id FROM %1$s liveDraft JOIN liveDraft.labels liveDraftLabel
                WHERE liveDraft.thread = t AND liveDraftLabel.role = :liveDraftRole
                AND NOT EXISTS (SELECT binnedDraft.id FROM %1$s binnedDraft JOIN binnedDraft.labels binnedDraftLabel
                    WHERE binnedDraft = liveDraft AND binnedDraftLabel.role = :binnedDraftRole))',
            Message::class,
        ))
            ->setParameter('liveDraftRole', LabelRole::Drafts)
            ->setParameter('binnedDraftRole', LabelRole::Trash);
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

        MessageThreadListCriteria::excludeTrashed($qb);
    }

    /**
     * One account's inbox — findForUnifiedInbox() narrowed to a single account.
     *
     * This is the account row in the sidebar, and it used to list EVERYTHING in
     * the account whatever it was labelled. That is not what clicking a mailbox
     * means to anybody: the list opened with your own sent mail interleaved
     * through it, plus drafts and spam, and the one thing a person clicking
     * their address is looking for — what has arrived — was buried in it. The
     * bin was already excluded, which was the first half of this same
     * realisation.
     *
     * Filtered by tab only when asked, and the default is not to. A tab is a
     * built-in category or one the reader made, and it narrows by the same
     * rule as the unified inbox's — narrowToCategory(), shared — so a thread
     * is in exactly one of them.
     *
     * This used to say the tabs belong to the unified inbox alone and that the
     * account's Inbox FOLDER is what is shown here. It does not hold any more:
     * the account page draws the same tab strip, because an installation whose
     * accounts are all Outlook is otherwise a client whose categories are
     * nowhere a person would look. A null category is still the whole folder,
     * which is what somebody who switched the tabs off gets, and it is still
     * what the account's own "Inbox" row in the expanded folder tree lists —
     * that row is a label view and does not have tabs.
     *
     * No `isActive` filter either, again unlike the unified list. A switched-off
     * account is still in the sidebar and still clickable; answering with an
     * empty list because it is asleep would read as lost mail.
     */
    public function findForAccountInbox(Account $account, int $page = 1, int $perPage = 50, ListSortOrder $sort = ListSortOrder::Newest, MessageCategory|InboxTab|null $category = null): array
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

        $this->narrowToCategory($qb, $category, MessageThreadListCriteria::switchedOffBy($account->usr));

        MessageThreadListCriteria::excludeTrashed($qb);

        $sort->applyTo($qb);

        return $qb->getQuery()->getResult();
    }

    /** Same two conditions as findForAccountInbox(), so the same reason to keep it. */
    public function countForAccountInbox(Account $account, MessageCategory|InboxTab|null $category = null): int
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

        $this->narrowToCategory($qb, $category, MessageThreadListCriteria::switchedOffBy($account->usr));

        MessageThreadListCriteria::excludeTrashed($qb);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * One account's All Mail: every conversation outside Spam and the bin.
     *
     * Archived, unlabelled, snoozed, sent and draft mail all included — Gmail's
     * All Mail holds drafts and sent mail too, and archived mail is what this
     * list exists to make browsable. See LabelRole::AllMail and AllMailView.
     *
     * @return list<MessageThread>
     */
    public function findForAccountAllMail(Account $account, int $page = 1, int $perPage = 50, ListSortOrder $sort = ListSortOrder::Newest, bool $unreadOnly = false): array
    {
        $qb = $this->allMailQuery($account, $unreadOnly)
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        $sort->applyTo($qb);

        return $qb->getQuery()->getResult();
    }

    /** Same query as findForAccountAllMail(), so the pager counts what it lists. */
    public function countForAccountAllMail(Account $account, bool $unreadOnly = false): int
    {
        return (int) $this->allMailQuery($account, $unreadOnly)
            ->select('COUNT(t.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * All Mail across the reader's accounts — the active ones, as the unified
     * Inbox lists: a switched-off account's mail is still reachable from its
     * own row in the sidebar.
     *
     * @return list<MessageThread>
     */
    public function findForUnifiedAllMail(User $user, int $page = 1, int $perPage = 50, ListSortOrder $sort = ListSortOrder::Newest, bool $unreadOnly = false): array
    {
        $qb = $this->allMailQuery($user, $unreadOnly)
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        $sort->applyTo($qb);

        return $qb->getQuery()->getResult();
    }

    /** Same query as findForUnifiedAllMail(). */
    public function countForUnifiedAllMail(User $user, bool $unreadOnly = false): int
    {
        return (int) $this->allMailQuery($user, $unreadOnly)
            ->select('COUNT(t.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * The one definition of All Mail, for one account or for a user's.
     *
     * Spam and the bin are left out by a subquery rather than by a join on
     * the labels. Labels are per thread and a thread can wear several, so a
     * join would list a conversation once per label — and would lose old mail
     * that wears no label at all, which is precisely the archived mail this
     * view is for. Any Spam label takes the whole conversation out; the bin
     * takes it out once all of it is there, the rule every other list follows
     * (excludeTrashed()).
     */
    private function allMailQuery(Account|User $owner, bool $unreadOnly): QueryBuilder
    {
        $qb = $this->createQueryBuilder('t')
            ->andWhere('t.id NOT IN (SELECT excluded.id FROM ' . MessageThread::class
                . ' excluded JOIN excluded.labels excludedLabel WHERE excludedLabel.role = :spamRole)')
            ->setParameter('spamRole', LabelRole::Spam);

        MessageThreadListCriteria::excludeTrashed($qb);

        if ($owner instanceof Account) {
            $qb->andWhere('t.account = :account')
                ->setParameter('account', $owner);
        } else {
            $qb->join('t.account', 'allAccount')
                ->andWhere('allAccount.usr = :user')
                ->andWhere('allAccount.isActive = true')
                ->setParameter('user', $owner);
        }

        $this->narrowToUnread($qb, $unreadOnly);

        return $qb;
    }

    /**
     * Threads per category in one account's inbox, regardless of read state.
     *
     * What decides which tabs the account page shows — the same question
     * countByCategoryForUnifiedInbox() answers for every account at once, with
     * the same two conditions findForAccountInbox() lists by, so a tab offered
     * here cannot open on an empty list. A tab with nothing in it has no key
     * rather than a zero.
     *
     * Keyed by tab, in InboxTab's keys: a thread filed in one of the reader's
     * own categories counts for that tab ONLY, and for the built-in one it was
     * assigned never — the rule narrowToCategory() lists by, and the reason the
     * counts add up to the inbox. Grouped by the reader's column as well as the
     * built-in one, because a count by `category` alone cannot say that a thread
     * has left its built-in tab, and a tab of the reader's own would then be
     * missing from the strip and its threads from every tab.
     *
     * A built-in the reader switched off is counted under Primary, as the list
     * puts it there (narrowToCategory()), so the account's tabs still add up to
     * its inbox.
     *
     * @return array<string, int>
     */
    public function countByCategoryForAccountInbox(Account $account): array
    {
        $qb = $this->createQueryBuilder('t')
            ->select('t.category AS category', 'IDENTITY(t.userCategory) AS userCategory', 'COUNT(DISTINCT t.id) AS threadCount')
            ->join('t.labels', 'l')
            ->where('t.account = :account')
            ->andWhere('l.role = :inbox')
            ->groupBy('t.category')
            ->addGroupBy('t.userCategory')
            ->setParameter('account', $account)
            ->setParameter('inbox', LabelRole::Inbox);

        MessageThreadListCriteria::excludeTrashed($qb);

        return MessageThreadListCriteria::foldByTab($qb->getQuery()->getResult(), 'threadCount', MessageThreadListCriteria::switchedOffBy($account->usr));
    }

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

        MessageThreadListCriteria::excludeTrashed($qb);
        $this->narrowToUnread($qb, $unreadOnly);

        $sort->applyTo($qb);

        return $qb->getQuery()->getResult();
    }

    /**
     * Every unread conversation, whatever it is filed under — the Unread view.
     *
     * Across folders on purpose, archived and labelled mail included: that is
     * what the view is for, the Inbox's own unread filter already answering
     * the narrower question. Only mail that has been put out of the way is left
     * out — the bin and spam, as every other cross-folder list here leaves them
     * out, and snoozed mail, whose snooze is a request not to be shown it yet.
     *
     * QueryBuilder for the account join and the role subquery; neither is a
     * field of the thread.
     *
     * @return list<MessageThread>
     */
    public function findForUnread(UserInterface $user, int $page = 1, int $perPage = 50, ListSortOrder $sort = ListSortOrder::Newest): array
    {
        $qb = $this->unreadQuery($user)
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        $sort->applyTo($qb);

        return $qb->getQuery()->getResult();
    }

    /** Same query as findForUnread() — the view's pager and its sidebar badge. */
    public function countForUnread(UserInterface $user): int
    {
        return (int) $this->unreadQuery($user)
            ->select('COUNT(t.id)')
            ->getQuery()
            ->getSingleScalarResult();
    }

    private function unreadQuery(UserInterface $user): QueryBuilder
    {
        $qb = $this->createQueryBuilder('t')
            ->join('t.account', 'a')
            ->where('a.usr = :user')
            ->andWhere('a.isActive = true')
            ->andWhere('t.unreadCount > 0')
            ->setParameter('user', $user);

        $qb->andWhere(
            $qb->expr()->notIn(
                't.id',
                'SELECT setAside.id FROM ' . MessageThread::class . ' setAside'
                    . ' JOIN setAside.labels setAsideLabel'
                    . ' WHERE setAsideLabel.role IN (:setAsideRoles)',
            ),
        )->setParameter('setAsideRoles', [LabelRole::Spam, LabelRole::Snoozed]);

        // The bin by the rule every list uses: all of the conversation, not
        // one message of it, or a reply that arrived in a half-deleted thread
        // is unread mail this view cannot show (#268).
        MessageThreadListCriteria::excludeTrashed($qb);

        return $qb;
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

        MessageThreadListCriteria::excludeTrashed($qb);
        $this->narrowToUnread($qb, $unreadOnly);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
    // ── the new-mail marker ───────────────────────────────────────────────
    //
    // "New" is listed_at IS NULL: the thread has never had its row put in front
    // of the user. It is not "unread", and the two are asked separately
    // everywhere below — a thread scrolled past but never opened answers no to
    // the first and yes to the second.

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
     * The two entity preloads a page of thread rows needs.
     *
     * The messages are not among them any more: a row reads a few fields of
     * each, never the entity, and App\Service\Mail\ThreadRows fetches exactly
     * those. That service is the list views' one entry point and calls this.
     *
     * @param MessageThread[] $threads
     */
    public function preloadForRows(array $threads): void
    {
        $this->preloadAccounts($threads);
        $this->preloadLabels($threads);
    }

    /**
     * The threshold is NOT HERE ANY MORE, and its absence is the fix.
     *
     * This was `SEMANTIC_MAX_DISTANCE = 0.45` — one cosine distance for every
     * installation, whatever model was configured. Cosine similarity is not
     * comparable between models, and not even between the instructed and
     * uninstructed forms of one model: measured, qwen3-embedding:0.6b wants a
     * minimum similarity of 0.42 with its instruction and 0.20 without, while
     * all-minilm wants 0.20 and nomic-embed-text 0.45. The shipped constant
     * worked out to 0.55, which is above all four.
     *
     * So it travels on {@see SemanticSearch}, next to the model and the width
     * it has to agree with, and it is a setting rather than a release. See
     * {@see \App\Domain\Ai\EmbeddingPreset} for the measurements.
     *
     * It is still a threshold. It is no longer the ONLY bound — see
     * {@see SEMANTIC_HITS}, which is the cap this docblock used to argue
     * against and which turns out to be safe in the place it is now written.
     */

    /**
     * Which of these threads are in the bin or in Spam.
     *
     * A second query over a short list of candidates rather than a NOT EXISTS
     * inside a search, where the label lookup would run once per matching
     * message.
     *
     * @param non-empty-list<int> $threadIds
     *
     * @return list<int>
     */
    public function findSetAsideAmong(array $threadIds): array
    {
        /** @var list<int|string> $ids */
        $ids = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            <<<SQL
                SELECT DISTINCT tl.message_thread_id
                FROM thread_label tl
                JOIN label l ON l.id = tl.label_id
                WHERE tl.message_thread_id IN (:threads) AND l.role IN (:roles)
            SQL,
            ['threads' => $threadIds, 'roles' => [LabelRole::Trash->value, LabelRole::Spam->value]],
            ['threads' => ArrayParameterType::INTEGER, 'roles' => ArrayParameterType::STRING],
        );

        return array_map(intval(...), $ids);
    }
}
