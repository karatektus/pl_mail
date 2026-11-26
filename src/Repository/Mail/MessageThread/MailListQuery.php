<?php

declare(strict_types=1);

namespace App\Repository\Mail\MessageThread;

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
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Every mail list, defined once, built as conversations or as single emails
 * (#615).
 *
 * The lists — an inbox and its tabs, a system folder, a label, All Mail,
 * Starred, Unread — used to be written for conversations alone, in
 * MessageThreadRepository. Turning conversation view off needs the same lists
 * one email per row, and two copies of each definition would drift: a badge
 * counting one rule beside a list drawing another is how a pager comes to
 * offer a page that is not there. So each list is ONE method here, made of
 * conditions that each know their two forms, and the level is an argument.
 *
 * The conversation form keeps the columns kept on the conversation for speed
 * — its labels (thread_label), $binned, $unreadCount, $starredAt — rather
 * than asking the emails. Those exist because the email-level sub-queries
 * cost a list on a big mailbox some 170 ms (#223), and they agree with the
 * emails by construction (ThreadLabelSynchronizer, the binned trigger).
 *
 * The aliases are fixed and shared by both forms: `t` is the conversation,
 * `m` the email (Email level only), `a` the account. The category tabs are
 * the conversation's at both levels — a tab moved by hand is recorded on the
 * conversation — so narrowToCategory() reads `t` and is the same code either
 * way.
 *
 * Conditions the conversation and the email lists share, so one class in this
 * folder (CODESTYLE 5.3). Not a service: MessageThreadRepository and
 * MessageListRepository build one over their entity manager, which keeps the
 * thread repository constructible the way its tests double it.
 */
final readonly class MailListQuery
{
    /**
     * @param list<int>|null $scope the workspace's accounts (#783): every list
     *                              that spans the reader's accounts is narrowed
     *                              to them. Null is every account, which is what
     *                              anything that is not drawing a workspace
     *                              (JMAP, the workers) means.
     */
    public function __construct(
        private EntityManagerInterface $em,
        private ?array                 $scope = null,
    )
    {
    }

    // ── the lists ────────────────────────────────────────────────────────

    /**
     * The inbox across the reader's active accounts, one tab of it or all of
     * it for a null category, optionally narrowed to one of their unified
     * inboxes (#265). See MessageThreadRepository::findForUnifiedInbox().
     *
     * @param list<int>|null $accountIds
     */
    public function inbox(ListLevel $level, UserInterface $user, MessageCategory|InboxTab|null $category, bool $unreadOnly = false, ?array $accountIds = null): QueryBuilder
    {
        $qb = $this->base($level);

        $this->ownedBy($qb, $level, $user);
        $this->withRole($qb, $level, LabelRole::Inbox);
        self::narrowToCategory($qb, $category, MessageThreadListCriteria::switchedOffBy($user));
        MessageThreadListCriteria::narrowToAccounts($qb, $accountIds);
        $this->notBinned($qb, $level);
        $this->unreadOnly($qb, $level, $unreadOnly);

        return $qb;
    }

    /**
     * One account's inbox, for its row in the sidebar. No mail-enabled check:
     * a switched-off account is still clickable, and answering it with
     * nothing would read as lost mail. See findForAccountInbox().
     *
     * `$unreadOnly` is what a tab's unread count opens (#670), as on the
     * unified inbox: the same list, narrowed the same way.
     */
    public function accountInbox(ListLevel $level, Account $account, MessageCategory|InboxTab|null $category = null, bool $unreadOnly = false): QueryBuilder
    {
        $qb = $this->base($level);

        $this->inAccount($qb, $level, $account);
        $this->withRole($qb, $level, LabelRole::Inbox);
        self::narrowToCategory($qb, $category, MessageThreadListCriteria::switchedOffBy($account->usr));
        $this->notBinned($qb, $level);
        $this->unreadOnly($qb, $level, $unreadOnly);

        return $qb;
    }

    /**
     * One system folder across the reader's active accounts. Everything but
     * the bin itself leaves out what is in the bin; Drafts lists only drafts
     * that are still drafts. See findForRole().
     */
    public function role(ListLevel $level, UserInterface $user, LabelRole $role, bool $unreadOnly = false): QueryBuilder
    {
        $qb = $this->base($level);

        $this->ownedBy($qb, $level, $user);
        $this->withRole($qb, $level, $role);

        if (LabelRole::Trash !== $role) {
            $this->notBinned($qb, $level);
        }

        $this->onlyLiveDrafts($qb, $level, $role);
        $this->unreadOnly($qb, $level, $unreadOnly);

        return $qb;
    }

    /**
     * Mail carrying a label, across the accounts it is bound to or under one
     * of them. The bin's own label row is the one list that keeps what is in
     * the bin. See findForLabel().
     */
    public function label(ListLevel $level, Label $label, ?Account $account = null, bool $unreadOnly = false): QueryBuilder
    {
        $qb = $this->base($level);

        if (ListLevel::Conversation === $level) {
            $qb->join('t.labels', 'l')
                ->andWhere('l = :label');
        } else {
            $qb->andWhere(':label MEMBER OF m.labels');
        }

        $qb->setParameter('label', $label);

        if (null !== $account) {
            $this->inAccount($qb, $level, $account);
        } elseif (null !== $this->scope) {
            // A label spans every account it is bound to; in a workspace, only
            // the workspace's. One account asked for by name is shown as asked.
            $qb->join(self::root($level) . '.account', 'sa');
            $this->inScope($qb, 'sa');
        }

        if (LabelRole::Trash !== $label->role) {
            $this->notBinned($qb, $level);
        }

        $this->onlyLiveDrafts($qb, $level, $label->role);
        $this->unreadOnly($qb, $level, $unreadOnly);

        return $qb;
    }

    /**
     * All Mail: everything outside Spam and the bin, for one account or
     * across the reader's active ones. Spam is left out by a sub-query rather
     * than a join, so mail with no label at all — the archived mail this view
     * exists for — is kept. See allMailQuery().
     */
    public function allMail(ListLevel $level, Account|User $owner, bool $unreadOnly = false): QueryBuilder
    {
        $qb = $this->base($level);

        $this->notIn($qb, $level, [LabelRole::Spam], 'allMailSpam');
        $this->notBinned($qb, $level);

        if ($owner instanceof Account) {
            $this->inAccount($qb, $level, $owner);
        } else {
            $this->ownedBy($qb, $level, $owner);
        }

        $this->unreadOnly($qb, $level, $unreadOnly);

        return $qb;
    }

    /** Starred mail across the reader's active accounts, outside the bin. */
    public function starred(ListLevel $level, UserInterface $user, bool $unreadOnly = false): QueryBuilder
    {
        $qb = $this->base($level);

        $this->ownedBy($qb, $level, $user);
        $qb->andWhere(self::root($level) . '.starredAt IS NOT NULL');
        $this->notBinned($qb, $level);
        $this->unreadOnly($qb, $level, $unreadOnly);

        return $qb;
    }

    /**
     * Every unread mail, whatever it is filed under, except what has been put
     * out of the way: Spam, Snoozed and the bin. See findForUnread().
     */
    public function unread(ListLevel $level, UserInterface $user): QueryBuilder
    {
        $qb = $this->base($level);

        $this->ownedBy($qb, $level, $user);
        $this->unreadOnly($qb, $level, true);
        $this->notIn($qb, $level, [LabelRole::Spam, LabelRole::Snoozed], 'setAside');
        $this->notBinned($qb, $level);

        return $qb;
    }

    // ── paging, counting ─────────────────────────────────────────────────

    /**
     * One page of a list, in its order: pins first, then by date.
     *
     * The order is the level's: a conversation by its last message (in
     * Drafts, by its draft), an email by its own date. $pinAnchors are the
     * emails the reader's pinned conversations put first at the Email level
     * (MessageThreadPinRepository::anchorIdsForUser()); the Conversation
     * level does not use them.
     *
     * @param list<int> $pinAnchors
     *
     * @return list<MessageThread>|list<Message>
     */
    public function page(QueryBuilder $qb, ListLevel $level, ListSortOrder $sort, int $page, int $perPage, ?LabelRole $role = null, array $pinAnchors = []): array
    {
        $qb->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage);

        if (ListLevel::Conversation === $level) {
            $this->sortConversations($qb, $sort, $role);
        } else {
            $sort->applyToEmails($qb, $pinAnchors, LabelRole::Drafts === $role);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * How many rows the list has, for the pager. COUNT(DISTINCT) for
     * conversations, whose label join is to-many and would count a
     * conversation once per label of the role; an email list has no to-many
     * join, so a plain count is right.
     */
    public function count(QueryBuilder $qb, ListLevel $level): int
    {
        return (int) $qb
            ->select(ListLevel::Conversation === $level ? 'COUNT(DISTINCT t.id)' : 'COUNT(m.id)')
            ->distinct(false)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Emails per tab of an inbox list, and how many of them are unread — the
     * tab strip's numbers in the email view. The tab is the conversation's
     * (narrowToCategory()), so the emails are grouped by its columns and
     * folded the way the conversation counts are
     * (MessageThreadListCriteria::foldByTab()).
     *
     * Email level only. The conversation strip has its own grouped read
     * (MessageThreadRepository::countTabsForUnifiedInbox()), which tests the
     * Inbox label with EXISTS so a plain SUM is right there; over this
     * builder's label join, a conversation wearing two Inbox labels would be
     * counted twice in the unread sum.
     *
     * @return array{threads: array<string, int>, unread: array<string, int>}
     */
    public function countEmailTabs(QueryBuilder $qb, UserInterface $user): array
    {
        $rows = $qb->select(
            't.category AS category',
            'IDENTITY(t.userCategory) AS userCategory',
            'COUNT(m.id) AS threadCount',
            'SUM(CASE WHEN m.seenAt IS NULL THEN 1 ELSE 0 END) AS unreadCount',
        )
            ->groupBy('t.category')
            ->addGroupBy('t.userCategory')
            ->getQuery()
            ->getResult();

        $absorbed = MessageThreadListCriteria::switchedOffBy($user);

        return [
            'threads' => MessageThreadListCriteria::foldByTab($rows, 'threadCount', $absorbed),
            'unread'  => array_filter(MessageThreadListCriteria::foldByTab($rows, 'unreadCount', $absorbed), static fn (int $count): bool => $count > 0),
        ];
    }

    // ── the conditions, each in its two forms ────────────────────────────

    /**
     * The list's root: conversations, or emails joined to their conversation
     * for the tab columns, which are the conversation's at both levels.
     */
    private function base(ListLevel $level): QueryBuilder
    {
        $qb = $this->em->createQueryBuilder();

        return ListLevel::Conversation === $level
            ? $qb->select('t')->from(MessageThread::class, 't')
            : $qb->select('m')->from(Message::class, 'm')->leftJoin('m.thread', 't');
    }

    private static function root(ListLevel $level): string
    {
        return ListLevel::Conversation === $level ? 't' : 'm';
    }

    /** The reader's accounts that have mail switched on — every unified list. */
    private function ownedBy(QueryBuilder $qb, ListLevel $level, UserInterface $user): void
    {
        $qb->join(self::root($level) . '.account', 'a')
            ->andWhere('a.usr = :user')
            ->andWhere('a.mailEnabled = true')
            ->setParameter('user', $user);

        $this->inScope($qb, 'a');
    }

    /**
     * Narrowed to the workspace's accounts (#783), on the account joined as
     * $alias. Its own parameter name, because a unified inbox narrows the same
     * join by its accounts as well and both conditions have to hold.
     */
    private function inScope(QueryBuilder $qb, string $alias): void
    {
        if (null === $this->scope) {
            return;
        }

        if ([] === $this->scope) {
            $qb->andWhere('1 = 0');

            return;
        }

        $qb->andWhere($alias . '.id IN (:workspaceAccounts)')
            ->setParameter('workspaceAccounts', $this->scope, ArrayParameterType::INTEGER);
    }

    private function inAccount(QueryBuilder $qb, ListLevel $level, Account $account): void
    {
        $qb->andWhere(self::root($level) . '.account = :account')
            ->setParameter('account', $account);
    }

    /**
     * Filed under a system role. A conversation by its labels, which are its
     * emails' together — joined, as every conversation list always has, which
     * is what makes those lists DISTINCT. An email by its own labels, asked
     * with EXISTS: one row per email, and nothing to de-duplicate.
     */
    private function withRole(QueryBuilder $qb, ListLevel $level, LabelRole $role): void
    {
        if (ListLevel::Conversation === $level) {
            $qb->join('t.labels', 'l')
                ->andWhere('l.role = :role')
                ->setParameter('role', $role)
                ->distinct();

            return;
        }

        $qb->andWhere('EXISTS (SELECT roleLabel.id FROM ' . Label::class . ' roleLabel WHERE roleLabel MEMBER OF m.labels AND roleLabel.role = :role)')
            ->setParameter('role', $role);
    }

    /**
     * "…and it is not in the bin." A conversation once all of it is
     * (MessageThread::$binned, see MessageThreadListCriteria::excludeTrashed());
     * an email once it is.
     */
    private function notBinned(QueryBuilder $qb, ListLevel $level): void
    {
        if (ListLevel::Conversation === $level) {
            MessageThreadListCriteria::excludeTrashed($qb);

            return;
        }

        $this->notIn($qb, $level, [LabelRole::Trash], 'binned');
    }

    /**
     * Not filed under any of these roles: the conversation by its labels,
     * the email by its own.
     *
     * @param non-empty-list<LabelRole> $roles
     */
    private function notIn(QueryBuilder $qb, ListLevel $level, array $roles, string $name): void
    {
        if (ListLevel::Conversation === $level) {
            $qb->andWhere(sprintf(
                't.id NOT IN (SELECT %1$sThread.id FROM %2$s %1$sThread JOIN %1$sThread.labels %1$sLabel WHERE %1$sLabel.role IN (:%1$sRoles))',
                $name,
                MessageThread::class,
            ));
        } else {
            $qb->andWhere(sprintf(
                'NOT EXISTS (SELECT %1$sLabel.id FROM %2$s %1$sLabel WHERE %1$sLabel MEMBER OF m.labels AND %1$sLabel.role IN (:%1$sRoles))',
                $name,
                Label::class,
            ));
        }

        $qb->setParameter($name . 'Roles', $roles);
    }

    private static function unreadCondition(ListLevel $level): string
    {
        return ListLevel::Conversation === $level ? 't.unreadCount > 0' : 'm.seenAt IS NULL';
    }

    /**
     * Unread: a conversation holding anything unread, which is what its
     * badges count; an email not yet read. A no-op when off, so every caller
     * passes the flag unconditionally.
     */
    private function unreadOnly(QueryBuilder $qb, ListLevel $level, bool $unreadOnly): void
    {
        if (false === $unreadOnly) {
            return;
        }

        $qb->andWhere(self::unreadCondition($level));
    }

    /**
     * Drafts lists a conversation for a draft that is still a draft, not one
     * thrown in the bin (see MessageThreadRepository's history of it). An
     * email in Drafts already is one, and notBinned() has left out the
     * thrown-away ones, so the Email level has nothing to add.
     */
    private function onlyLiveDrafts(QueryBuilder $qb, ListLevel $level, ?LabelRole $role): void
    {
        if (LabelRole::Drafts !== $role || ListLevel::Email === $level) {
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
     * One tab of the inbox, or all of it for null — the conversation's tab,
     * at both levels. Public and static because the conversation repository's
     * grouped tab counts narrow by it too.
     *
     * @param list<string> $absorbed built-in values switched off — their
     *                               conversations are listed under Primary
     */
    public static function narrowToCategory(QueryBuilder $qb, MessageCategory|InboxTab|null $category, array $absorbed = []): void
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
     * The conversation list's order — by its last message, except in Drafts,
     * which is ordered by when its drafts were last written.
     *
     * lastMessageAt is the wrong key there, and on purpose everywhere else: a
     * draft may not advance it (MessageThreader::recordActivity()), so an
     * autosave does not reorder the inbox. But Drafts lists a conversation
     * BECAUSE of its draft, and a reply written just now to a mail from last
     * week sat a week down the list, under drafts nobody had touched since.
     *
     * The draft's time is ThreadRowMessage::writtenAt(), spelled in DQL: the
     * provider's date for a draft synced from elsewhere, our last save for one
     * written here. Only live drafts count, the ones onlyLiveDrafts() lists the
     * conversation for. HIDDEN keeps the row an entity; under DISTINCT Postgres
     * wants an ORDER BY expression in the select list, which this is.
     */
    private function sortConversations(QueryBuilder $qb, ListSortOrder $sort, ?LabelRole $role): void
    {
        if (LabelRole::Drafts !== $role) {
            $sort->applyTo($qb);

            return;
        }

        $direction = ListSortOrder::Oldest === $sort ? 'ASC' : 'DESC';

        $qb->addSelect(sprintf(
            '(SELECT MAX(COALESCE(sortDraft.receivedAt, sortDraft.updatedAt)) FROM %1$s sortDraft JOIN sortDraft.labels sortDraftLabel
                WHERE sortDraft.thread = t AND sortDraftLabel.role = :sortDraftRole
                AND NOT EXISTS (SELECT sortBinned.id FROM %1$s sortBinned JOIN sortBinned.labels sortBinnedLabel
                    WHERE sortBinned = sortDraft AND sortBinnedLabel.role = :sortBinnedRole)) AS HIDDEN draftWrittenAt',
            Message::class,
        ))
            ->setParameter('sortDraftRole', LabelRole::Drafts)
            ->setParameter('sortBinnedRole', LabelRole::Trash);

        // Pins first here too: a pinned conversation is at the top of every
        // list it is in (#654), and Drafts is one.
        ListSortOrder::pinnedFirst($qb)
            ->addOrderBy('draftWrittenAt', $direction)
            ->addOrderBy('t.id', $direction);
    }
}
