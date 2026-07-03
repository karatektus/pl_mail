<?php

namespace App\Repository;

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
}
