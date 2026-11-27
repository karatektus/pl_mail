<?php

declare(strict_types=1);

namespace App\Repository\Mail\Message;

use App\Domain\Enum\Mail\LabelRole;
use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use App\Repository\Mail\MessageThread\MessageThreadListCriteria;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The sidebar's unread badges counted in emails (#615), for somebody who has
 * conversation view off.
 *
 * The badge's unit is the list's: a conversation list counts conversations
 * holding unread mail (MessageThreadBadgeRepository), and an email list counts
 * unread emails, so the number beside "Inbox" is how many bold rows the Inbox
 * opens on. Same method names and the same rules as the conversation
 * repository — the bin row counts its own, a binned email stops counting
 * everywhere else, mail held for the assistant is in no inbox yet — so
 * App\Service\Mail\SidebarCounts asks whichever one the person's view needs.
 *
 * Not final, because tests double it.
 *
 * @extends ServiceEntityRepository<Message>
 */
class MessageListBadgeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Message::class);
    }

    /**
     * Unread emails in each account's Inbox, keyed by account id — what one
     * unified inbox's badge adds up.
     *
     * @return array<int, int>
     */
    public function countUnreadInboxPerAccount(UserInterface $user): array
    {
        $qb = $this->unread($user)
            ->select('a.id AS accountId', 'COUNT(m.id) AS unreadCount')
            ->andWhere('EXISTS (SELECT inboxLabel.id FROM ' . Label::class . ' inboxLabel WHERE inboxLabel MEMBER OF m.labels AND inboxLabel.role = :inbox)')
            // Held for the assistant: in no tab and in no inbox yet.
            ->andWhere('t.category IS NOT NULL')
            ->setParameter('inbox', LabelRole::Inbox)
            ->groupBy('a.id');

        $this->notBinned($qb);

        $counts = [];

        foreach ($qb->getQuery()->getArrayResult() as $row) {
            $counts[(int) $row['accountId']] = (int) $row['unreadCount'];
        }

        return $counts;
    }

    /**
     * Unread emails per system role, keyed by the role's value. The bin row
     * counts what is in the bin; every other row leaves it out.
     *
     * @return array<string, int>
     */
    public function countUnreadPerRole(UserInterface $user, ?array $accountIds = null): array
    {
        $qb = $this->unread($user, $accountIds)
            ->select('l.role AS role', 'COUNT(DISTINCT m.id) AS unreadCount')
            ->join('m.labels', 'l')
            ->andWhere('l.role IS NOT NULL')
            ->groupBy('l.role');

        $qb->andWhere($qb->expr()->orX(
            'l.role = :trashRole',
            'NOT EXISTS (SELECT binLabel.id FROM ' . Label::class . ' binLabel WHERE binLabel MEMBER OF m.labels AND binLabel.role = :trashRole)',
        ))->setParameter('trashRole', LabelRole::Trash);

        // Held for the assistant: not in the Inbox count yet.
        $qb->andWhere($qb->expr()->orX('l.role <> :heldRole', 't.category IS NOT NULL'))
            ->setParameter('heldRole', LabelRole::Inbox);

        $counts = [];

        foreach ($qb->getQuery()->getArrayResult() as $row) {
            $role = $row['role'];

            $counts[$role instanceof LabelRole ? $role->value : (string) $role] = (int) $row['unreadCount'];
        }

        return $counts;
    }

    /**
     * Unread emails per label of the person's own, optionally under one
     * account, keyed by label id.
     *
     * @return array<int, int>
     *
     * @param list<int>|null $accountIds a workspace's accounts (#783); null is all
     */
    public function countUnreadPerUserLabel(UserInterface $user, ?Account $account = null, ?array $accountIds = null): array
    {
        $qb = $this->unread($user, null === $account ? $accountIds : null)
            ->select('l.id AS labelId', 'COUNT(DISTINCT m.id) AS unreadCount')
            ->join('m.labels', 'l')
            ->andWhere('l.role IS NULL')
            ->groupBy('l.id');

        if (null !== $account) {
            $qb->andWhere('m.account = :account')
                ->setParameter('account', $account);
        }

        $this->notBinned($qb);

        $counts = [];

        foreach ($qb->getQuery()->getArrayResult() as $row) {
            $counts[(int) $row['labelId']] = (int) $row['unreadCount'];
        }

        return $counts;
    }

    /**
     * Unread emails wearing any label of the person's own: the LABELS heading.
     *
     * @param list<int>|null $accountIds a workspace's accounts (#783); null is all
     */
    public function countUnreadInUserLabels(UserInterface $user, ?array $accountIds = null): int
    {
        $qb = $this->unread($user, $accountIds)
            ->select('COUNT(m.id)')
            ->andWhere('EXISTS (SELECT userLabel.id FROM ' . Label::class . ' userLabel WHERE userLabel MEMBER OF m.labels AND userLabel.role IS NULL)');

        $this->notBinned($qb);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Unread emails wearing a label or any label nested under it: a collapsed
     * parent label (#807). Each email once, however many of those labels it
     * wears — see the thread repository's countUnreadInLabels().
     *
     * @param non-empty-list<int> $labelIds the label and its descendants
     * @param list<int>|null      $accountIds a workspace's accounts (#783); null is all
     */
    public function countUnreadInLabels(UserInterface $user, array $labelIds, ?array $accountIds = null): int
    {
        $qb = $this->unread($user, $accountIds)
            ->select('COUNT(m.id)')
            ->andWhere('EXISTS (SELECT treeLabel.id FROM ' . Label::class . ' treeLabel WHERE treeLabel MEMBER OF m.labels AND treeLabel.id IN (:labelIds))')
            ->setParameter('labelIds', $labelIds);

        $this->notBinned($qb);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /** Unread starred emails. */
    public function countUnreadForStarred(UserInterface $user, ?array $accountIds = null): int
    {
        return (int) $this->unread($user, $accountIds)
            ->select('COUNT(m.id)')
            ->andWhere('m.starredAt IS NOT NULL')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * The unread emails of the person's accounts with mail on, narrowed to a
     * workspace's accounts when it names some (#783).
     *
     * @param list<int>|null $accountIds
     */
    private function unread(UserInterface $user, ?array $accountIds = null): QueryBuilder
    {
        $qb = $this->createQueryBuilder('m')
            ->join('m.account', 'a')
            ->leftJoin('m.thread', 't')
            ->where('a.usr = :user')
            ->andWhere('a.mailEnabled = true')
            ->andWhere('m.seenAt IS NULL')
            ->setParameter('user', $user);

        MessageThreadListCriteria::narrowToAccounts($qb, $accountIds);

        return $qb;
    }

    /** Not in the bin — the email's own Trash label. */
    private function notBinned(QueryBuilder $qb): void
    {
        $qb->andWhere('NOT EXISTS (SELECT binnedLabel.id FROM ' . Label::class . ' binnedLabel WHERE binnedLabel MEMBER OF m.labels AND binnedLabel.role = :binnedRole)')
            ->setParameter('binnedRole', LabelRole::Trash);
    }
}
