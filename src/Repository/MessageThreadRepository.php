<?php

namespace App\Repository;

use App\Domain\Enum\MailboxSpecialUse;
use App\Domain\Enum\MessageTab;
use App\Entity\Account;
use App\Entity\MessageThread;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
            ->join('t.mailboxes', 'm')
            ->where('a.usr = :user')
            ->andWhere('a.isActive = true')
            ->andWhere('m.specialUse = :inbox')
            ->andWhere('t.tab = :tab')
            ->setParameter('user', $user)
            ->setParameter('inbox', '\\Inbox')
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
            ->join('t.mailboxes', 'm')
            ->where('a.usr = :user')
            ->andWhere('a.isActive = true')
            ->andWhere('m.specialUse = :inbox')
            ->andWhere('t.tab = :tab')
            ->setParameter('user', $user)
            ->setParameter('inbox', '\\Inbox')
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
            ->join('t.mailboxes', 'm')
            ->where('a.usr = :user')
            ->andWhere('a.isActive = true')
            ->andWhere('m.specialUse = :inbox')
            ->andWhere('t.unreadCount > 0')
            ->groupBy('t.tab')
            ->setParameter('user', $user)
            ->setParameter('inbox', '\\Inbox')
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

    public function findForSpecialUse(UserInterface $user, MailboxSpecialUse $specialUse, int $page = 1, int $perPage = 50): array
    {
        $offset = ($page - 1) * $perPage;

        return $this->createQueryBuilder('t')
            ->join('t.account', 'a')
            ->join('t.mailboxes', 'm')
            ->where('a.usr = :user')
            ->andWhere('a.isActive = true')
            ->andWhere('m.specialUse = :specialUse')
            ->setParameter('user', $user)
            ->setParameter('specialUse', $specialUse)
            ->orderBy('t.lastMessageAt', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($perPage)
            ->distinct()
            ->getQuery()
            ->getResult();
    }

    public function countForSpecialUse(UserInterface $user, MailboxSpecialUse $specialUse): int
    {
        return $this->createQueryBuilder('t')
            ->select('COUNT(DISTINCT t.id)')
            ->join('t.account', 'a')
            ->join('t.mailboxes', 'm')
            ->where('a.usr = :user')
            ->andWhere('a.isActive = true')
            ->andWhere('m.specialUse = :specialUse')
            ->setParameter('user', $user)
            ->setParameter('specialUse', $specialUse)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countUnreadPerSpecialUse(UserInterface $user): array
    {
        $rows = $this->createQueryBuilder('t')
            ->select('m.specialUse AS specialUse', 'COUNT(DISTINCT t.id) AS unreadCount')
            ->join('t.account', 'a')
            ->join('t.mailboxes', 'm')
            ->where('a.usr = :user')
            ->andWhere('a.isActive = true')
            ->andWhere('t.unreadCount > 0')
            ->andWhere('m.specialUse IS NOT NULL')
            ->groupBy('m.specialUse')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();

        $counts = [];
        
        foreach ($rows as $row) {
            $counts[$row['specialUse']->value] = $row['unreadCount'];
        }

        return $rows;
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
}
