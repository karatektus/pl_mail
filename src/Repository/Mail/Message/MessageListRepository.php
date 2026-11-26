<?php

declare(strict_types=1);

namespace App\Repository\Mail\Message;

use App\Domain\DTO\Mail\InboxTab;
use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\ListLevel;
use App\Domain\Enum\Mail\ListSortOrder;
use App\Domain\Enum\Mail\MessageCategory;
use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Repository\Mail\MessageThread\MailListQuery;
use App\Repository\Mail\MessageThread\MessageThreadPinRepository;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The mail lists one email per row (#615) — what a person with conversation
 * view switched off is shown.
 *
 * The twin of MessageThreadRepository's lists, and not a copy of them: both
 * build MailListQuery, which defines each list once, this one at the Email
 * level. Each method here is the same list and the same arguments as the
 * conversation method of the same name, so App\Service\Mail\MailLists can
 * pick one or the other by the person's setting and nothing else.
 *
 * The page methods also ask which emails the reader's pinned conversations
 * put first (MessageThreadPinRepository::anchorIdsForUser()), because a
 * conversation pinned in the other view shows here as its newest received
 * email at the top.
 *
 * Not final, because tests double it.
 *
 * @extends ServiceEntityRepository<Message>
 */
class MessageListRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly MessageThreadPinRepository $pins,
    ) {
        parent::__construct($registry, Message::class);
    }

    /**
     * @param list<int>|null $accountIds
     *
     * @return list<Message>
     */
    public function findForUnifiedInbox(UserInterface $user, MessageCategory|InboxTab|null $category, int $page = 1, int $perPage = 50, ListSortOrder $sort = ListSortOrder::Newest, bool $unreadOnly = false, ?array $accountIds = null): array
    {
        $lists = $this->lists();

        return $this->page($lists->inbox(ListLevel::Email, $user, $category, $unreadOnly, $accountIds), $sort, $page, $perPage, null, $user);
    }

    /** @param list<int>|null $accountIds */
    public function countForUnifiedInbox(UserInterface $user, MessageCategory|InboxTab|null $category, bool $unreadOnly = false, ?array $accountIds = null): int
    {
        $lists = $this->lists();

        return $lists->count($lists->inbox(ListLevel::Email, $user, $category, $unreadOnly, $accountIds), ListLevel::Email);
    }

    /**
     * Every tab's email count and unread count, in one read of the inbox —
     * countTabsForUnifiedInbox() at the Email level.
     *
     * @param list<int>|null $accountIds
     *
     * @return array{threads: array<string, int>, unread: array<string, int>}
     */
    public function countTabsForUnifiedInbox(UserInterface $user, ?array $accountIds = null): array
    {
        $lists = $this->lists();

        return $lists->countEmailTabs($lists->inbox(ListLevel::Email, $user, null, false, $accountIds), $user);
    }

    /**
     * Unread emails per tab, for the tab strip's numbers on a sync —
     * countUnreadByCategoryForUnifiedInbox() at the Email level.
     *
     * @param list<int>|null $accountIds
     *
     * @return array<string, int>
     */
    public function countUnreadByCategoryForUnifiedInbox(UserInterface $user, ?array $accountIds = null): array
    {
        return $this->countTabsForUnifiedInbox($user, $accountIds)['unread'];
    }

    /** @return list<Message> */
    public function findForRole(UserInterface $user, LabelRole $role, int $page = 1, int $perPage = 50, ListSortOrder $sort = ListSortOrder::Newest, bool $unreadOnly = false): array
    {
        return $this->page($this->lists()->role(ListLevel::Email, $user, $role, $unreadOnly), $sort, $page, $perPage, $role, $user);
    }

    public function countForRole(UserInterface $user, LabelRole $role, bool $unreadOnly = false): int
    {
        $lists = $this->lists();

        return $lists->count($lists->role(ListLevel::Email, $user, $role, $unreadOnly), ListLevel::Email);
    }

    /** @return list<Message> */
    public function findForLabel(Label $label, ?Account $account = null, int $page = 1, int $perPage = 50, ListSortOrder $sort = ListSortOrder::Newest, bool $unreadOnly = false): array
    {
        return $this->page($this->lists()->label(ListLevel::Email, $label, $account, $unreadOnly), $sort, $page, $perPage, $label->role, $label->usr);
    }

    public function countForLabel(Label $label, ?Account $account = null, bool $unreadOnly = false): int
    {
        $lists = $this->lists();

        return $lists->count($lists->label(ListLevel::Email, $label, $account, $unreadOnly), ListLevel::Email);
    }

    /** @return list<Message> */
    public function findForAccountInbox(Account $account, int $page = 1, int $perPage = 50, ListSortOrder $sort = ListSortOrder::Newest, MessageCategory|InboxTab|null $category = null, bool $unreadOnly = false): array
    {
        return $this->page($this->lists()->accountInbox(ListLevel::Email, $account, $category, $unreadOnly), $sort, $page, $perPage, null, $account->usr);
    }

    public function countForAccountInbox(Account $account, MessageCategory|InboxTab|null $category = null, bool $unreadOnly = false): int
    {
        $lists = $this->lists();

        return $lists->count($lists->accountInbox(ListLevel::Email, $account, $category, $unreadOnly), ListLevel::Email);
    }

    /**
     * Emails per tab in one account's inbox — what decides which tabs its
     * page shows, as countByCategoryForAccountInbox() does for conversations.
     *
     * @return array<string, int>
     */
    public function countByCategoryForAccountInbox(Account $account): array
    {
        return $this->countTabsForAccountInbox($account)['threads'];
    }

    /**
     * Every tab's email count and unread count in one account's inbox —
     * countTabsForAccountInbox() at the Email level.
     *
     * @return array{threads: array<string, int>, unread: array<string, int>}
     */
    public function countTabsForAccountInbox(Account $account): array
    {
        $lists = $this->lists();
        $user  = $account->usr ?? throw new \LogicException('An account has an owner.');

        return $lists->countEmailTabs($lists->accountInbox(ListLevel::Email, $account), $user);
    }

    /** @return list<Message> */
    public function findForAccountAllMail(Account $account, int $page = 1, int $perPage = 50, ListSortOrder $sort = ListSortOrder::Newest, bool $unreadOnly = false): array
    {
        return $this->page($this->lists()->allMail(ListLevel::Email, $account, $unreadOnly), $sort, $page, $perPage, null, $account->usr);
    }

    public function countForAccountAllMail(Account $account, bool $unreadOnly = false): int
    {
        $lists = $this->lists();

        return $lists->count($lists->allMail(ListLevel::Email, $account, $unreadOnly), ListLevel::Email);
    }

    /** @return list<Message> */
    public function findForUnifiedAllMail(User $user, int $page = 1, int $perPage = 50, ListSortOrder $sort = ListSortOrder::Newest, bool $unreadOnly = false): array
    {
        return $this->page($this->lists()->allMail(ListLevel::Email, $user, $unreadOnly), $sort, $page, $perPage, null, $user);
    }

    public function countForUnifiedAllMail(User $user, bool $unreadOnly = false): int
    {
        $lists = $this->lists();

        return $lists->count($lists->allMail(ListLevel::Email, $user, $unreadOnly), ListLevel::Email);
    }

    /** @return list<Message> */
    public function findForStarred(UserInterface $user, int $page = 1, int $perPage = 50, ListSortOrder $sort = ListSortOrder::Newest, bool $unreadOnly = false): array
    {
        return $this->page($this->lists()->starred(ListLevel::Email, $user, $unreadOnly), $sort, $page, $perPage, null, $user);
    }

    public function countForStarred(UserInterface $user, bool $unreadOnly = false): int
    {
        $lists = $this->lists();

        return $lists->count($lists->starred(ListLevel::Email, $user, $unreadOnly), ListLevel::Email);
    }

    /** @return list<Message> */
    public function findForUnread(UserInterface $user, int $page = 1, int $perPage = 50, ListSortOrder $sort = ListSortOrder::Newest): array
    {
        return $this->page($this->lists()->unread(ListLevel::Email, $user), $sort, $page, $perPage, null, $user);
    }

    public function countForUnread(UserInterface $user): int
    {
        $lists = $this->lists();

        return $lists->count($lists->unread(ListLevel::Email, $user), ListLevel::Email);
    }

    /**
     * What a page of email rows reads besides the email: its labels (the
     * chips and data-label-ids), its account (the corner wedge, "me") and its
     * conversation (pins, snoozes and the tab). Fetch-joined onto the entities
     * already in memory, so the result is discarded — the effect is on them,
     * as MessageThreadRepository::preloadLabels() does for conversations.
     *
     * @param list<Message> $messages
     */
    public function preloadForRows(array $messages): void
    {
        if ([] === $messages) {
            return;
        }

        $this->createQueryBuilder('m')
            ->addSelect('label', 'account', 'thread')
            ->leftJoin('m.labels', 'label')
            ->join('m.account', 'account')
            ->leftJoin('m.thread', 'thread')
            ->where('m IN (:messages)')
            ->setParameter('messages', $messages)
            ->getQuery()
            ->getResult();
    }

    private function lists(): MailListQuery
    {
        return new MailListQuery($this->getEntityManager());
    }

    /**
     * A page in the email order, with the reader's pinned conversations'
     * newest received emails among the pins.
     *
     * @return list<Message>
     */
    private function page(QueryBuilder $qb, ListSortOrder $sort, int $page, int $perPage, ?LabelRole $role, ?UserInterface $owner): array
    {
        /** @var list<Message> */
        return $this->lists()->page(
            $qb,
            ListLevel::Email,
            $sort,
            $page,
            $perPage,
            $role,
            null === $owner ? [] : $this->pins->anchorIdsForUser($owner),
        );
    }
}
