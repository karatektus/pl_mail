<?php

declare(strict_types=1);

namespace App\Repository\Mail\MessageThread;


use App\Domain\DTO\Ai\SemanticSearch;
use App\Domain\DTO\Mail\InboxTab;
use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\ListLevel;
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
     * The list definitions both views share (#615); this repository builds
     * them at the Conversation level, MessageListRepository at the Email one.
     */
    private function lists(): MailListQuery
    {
        return new MailListQuery($this->getEntityManager());
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
        $lists = $this->lists();

        return $lists->page($lists->inbox(ListLevel::Conversation, $user, $category, $unreadOnly, $accountIds), ListLevel::Conversation, $sort, $page, $perPage);
    }

    /**
     * Same two joins as findForUnifiedInbox(), so the same reason to keep it.
     *
     * @param list<int>|null $accountIds see findForUnifiedInbox()
     */
    public function countForUnifiedInbox(UserInterface $user, MessageCategory|InboxTab|null $category, bool $unreadOnly = false, ?array $accountIds = null): int
    {
        $lists = $this->lists();

        return $lists->count($lists->inbox(ListLevel::Conversation, $user, $category, $unreadOnly, $accountIds), ListLevel::Conversation);
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
            ->andWhere('a.mailEnabled = true')
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
            ->andWhere('a.mailEnabled = true')
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
        $lists = $this->lists();

        return $lists->page($lists->role(ListLevel::Conversation, $user, $role, $unreadOnly), ListLevel::Conversation, $sort, $page, $perPage, $role);
    }
    /** Same joins as findForRole(). */
    public function countForRole(UserInterface $user, LabelRole $role, bool $unreadOnly = false): int
    {
        $lists = $this->lists();

        return $lists->count($lists->role(ListLevel::Conversation, $user, $role, $unreadOnly), ListLevel::Conversation);
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
        $lists = $this->lists();

        return $lists->page($lists->label(ListLevel::Conversation, $label, $account, $unreadOnly), ListLevel::Conversation, $sort, $page, $perPage, $label->role);
    }

    /** Same to-many filter as findForLabel(). */
    public function countForLabel(Label $label, ?Account $account = null, bool $unreadOnly = false): int
    {
        $lists = $this->lists();

        return $lists->count($lists->label(ListLevel::Conversation, $label, $account, $unreadOnly), ListLevel::Conversation);
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
    public function findForAccountInbox(Account $account, int $page = 1, int $perPage = 50, ListSortOrder $sort = ListSortOrder::Newest, MessageCategory|InboxTab|null $category = null, bool $unreadOnly = false): array
    {
        $lists = $this->lists();

        return $lists->page($lists->accountInbox(ListLevel::Conversation, $account, $category, $unreadOnly), ListLevel::Conversation, $sort, $page, $perPage);
    }

    /** Same two conditions as findForAccountInbox(), so the same reason to keep it. */
    public function countForAccountInbox(Account $account, MessageCategory|InboxTab|null $category = null, bool $unreadOnly = false): int
    {
        $lists = $this->lists();

        return $lists->count($lists->accountInbox(ListLevel::Conversation, $account, $category, $unreadOnly), ListLevel::Conversation);
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
        $lists = $this->lists();

        return $lists->page($lists->allMail(ListLevel::Conversation, $account, $unreadOnly), ListLevel::Conversation, $sort, $page, $perPage);
    }

    /** Same query as findForAccountAllMail(), so the pager counts what it lists. */
    public function countForAccountAllMail(Account $account, bool $unreadOnly = false): int
    {
        $lists = $this->lists();

        return $lists->count($lists->allMail(ListLevel::Conversation, $account, $unreadOnly), ListLevel::Conversation);
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
        $lists = $this->lists();

        return $lists->page($lists->allMail(ListLevel::Conversation, $user, $unreadOnly), ListLevel::Conversation, $sort, $page, $perPage);
    }

    /** Same query as findForUnifiedAllMail(). */
    public function countForUnifiedAllMail(User $user, bool $unreadOnly = false): int
    {
        $lists = $this->lists();

        return $lists->count($lists->allMail(ListLevel::Conversation, $user, $unreadOnly), ListLevel::Conversation);
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
        return $this->countTabsForAccountInbox($account)['threads'];
    }

    /**
     * Every tab's thread count and unread count in one account's inbox, in one
     * read — countTabsForUnifiedInbox() for a single account.
     *
     * The totals decide which tabs the account page shows (see
     * countByCategoryForAccountInbox() above); the unread counts are the
     * numbers on its tabs, each a link to that tab's unread view (#670). Same
     * conditions as findForAccountInbox() with the unread filter on, so a tab
     * saying 3 opens on 3. The Inbox label is tested with EXISTS for the
     * reason countTabsForUnifiedInbox() gives: a join would need
     * COUNT(DISTINCT), and the SUM beside it cannot be made distinct.
     *
     * @return array{threads: array<string, int>, unread: array<string, int>}
     */
    public function countTabsForAccountInbox(Account $account): array
    {
        $qb = $this->createQueryBuilder('t')
            ->select(
                't.category AS category',
                'IDENTITY(t.userCategory) AS userCategory',
                'COUNT(t.id) AS threadCount',
                'SUM(CASE WHEN t.unreadCount > 0 THEN 1 ELSE 0 END) AS unreadCount',
            )
            ->where('t.account = :account')
            ->andWhere('EXISTS (SELECT il.id FROM ' . Label::class . ' il WHERE il.role = :inbox AND il MEMBER OF t.labels)')
            ->groupBy('t.category')
            ->addGroupBy('t.userCategory')
            ->setParameter('account', $account)
            ->setParameter('inbox', LabelRole::Inbox);

        MessageThreadListCriteria::excludeTrashed($qb);

        $rows     = $qb->getQuery()->getResult();
        $absorbed = MessageThreadListCriteria::switchedOffBy($account->usr);

        return [
            'threads' => MessageThreadListCriteria::foldByTab($rows, 'threadCount', $absorbed),
            'unread'  => array_filter(MessageThreadListCriteria::foldByTab($rows, 'unreadCount', $absorbed), static fn (int $count): bool => $count > 0),
        ];
    }

    /**
     * QueryBuilder for the join to Account: both the owner and whether the
     * account is still active live there, and neither is a field of the thread.
     */
    public function findForStarred(UserInterface $user, int $page = 1, int $perPage = 50, ListSortOrder $sort = ListSortOrder::Newest, bool $unreadOnly = false): array
    {
        $lists = $this->lists();

        return $lists->page($lists->starred(ListLevel::Conversation, $user, $unreadOnly), ListLevel::Conversation, $sort, $page, $perPage);
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
        $lists = $this->lists();

        return $lists->page($lists->unread(ListLevel::Conversation, $user), ListLevel::Conversation, $sort, $page, $perPage);
    }

    /** Same query as findForUnread() — the view's pager and its sidebar badge. */
    public function countForUnread(UserInterface $user): int
    {
        $lists = $this->lists();

        return $lists->count($lists->unread(ListLevel::Conversation, $user), ListLevel::Conversation);
    }

    /** Same join as findForStarred(). */
    public function countForStarred(UserInterface $user, bool $unreadOnly = false): int
    {
        $lists = $this->lists();

        return $lists->count($lists->starred(ListLevel::Conversation, $user, $unreadOnly), ListLevel::Conversation);
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
        $qb = $this->createQueryBuilder('thread')
            ->innerJoin('thread.labels', 'label')
            ->where('label IN (:labels)')
            ->setParameter('labels', $labels)
            ->groupBy('thread.id');

        // Pins first, as in every folder list (#654) — see
        // ListSortOrder::pinnedFirst().
        return ListSortOrder::pinnedFirst($qb, 'thread')
            ->addOrderBy('thread.lastMessageAt', 'DESC')
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
