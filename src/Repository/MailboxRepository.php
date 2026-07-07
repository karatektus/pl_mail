<?php

namespace App\Repository;

use App\Domain\Enum\MailboxSpecialUse;
use App\Domain\Enum\MessageFlag;
use App\Entity\Account;
use App\Entity\Mailbox;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * @extends ServiceEntityRepository<Mailbox>
 */
class MailboxRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Mailbox::class);
    }

    public function findIndexedByFullPath(Account $account): array
    {
        $mailboxes = $this->findBy(['account' => $account]);
        $indexed = [];

        foreach ($mailboxes as $mailbox) {
            $indexed[$mailbox->getFullPath()] = $mailbox;
        }

        return $indexed;
    }

    public function findSentMailboxForAccount(Account $account): ?Mailbox
    {
        return $this->findOneBy(['account' => $account, 'specialUse' => MailboxSpecialUse::SENT]);
    }
    public function findDraftMailboxForAccount(Account $account): ?Mailbox
    {
        return $this->findOneBy(['account' => $account, 'specialUse' => MailboxSpecialUse::DRAFTS]);
    }

    public function findIdleEnabledAndSyncEnabled(): array
    {
        $queryBuilder = $this->createQueryBuilder('mailbox');

        $queryBuilder
            ->innerJoin('mailbox.account', 'account')
            ->addSelect('account')
            ->andWhere('mailbox.isIdleEnabled = :isIdleEnabled')
            ->andWhere('mailbox.isSyncEnabled = :isSyncEnabled')
            ->andWhere('account.isActive = :isActive')
            ->setParameter('isIdleEnabled', true)
            ->setParameter('isSyncEnabled', true)
            ->setParameter('isActive', true);

        return $queryBuilder->getQuery()->getResult();
    }

    public function getIdsOfActiveInboxMailboxesForUser(UserInterface $user): array
    {
        return $this->createQueryBuilder('mailbox')
            ->select('mailbox.id')
            ->leftJoin('mailbox.account', 'account')
            ->where('account.isActive = :isActive')
            ->andWhere('account.usr = :usr')
            ->andWhere('mailbox.specialUse = :inbox')
            ->setParameter('isActive', true)
            ->setParameter('usr', $user)
            ->setParameter('inbox', MailboxSpecialUse::INBOX)
            ->getQuery()
            ->getSingleColumnResult();
    }

    public function getActiveSentMailboxesForUser(UserInterface $user): QueryBuilder
    {
        return $this->createQueryBuilder('mailbox')
            ->leftJoin('mailbox.account', 'account')
            ->where('account.isActive = :isActive')
            ->andWhere('account.usr = :usr')
            ->andWhere('mailbox.specialUse = :sent')
            ->setParameter('isActive', true)
            ->setParameter('usr', $user)
            ->setParameter('sent', MailboxSpecialUse::DRAFTS)
            ->orderBy('account.isPrimary', 'DESC');
    }

    public function findPrimaryDraftMailboxForUser(UserInterface $user){
        return $this->createQueryBuilder('mailbox')
            ->leftJoin('mailbox.account', 'account')
            ->where('account.isActive = :isActive')
            ->andWhere('account.usr = :usr')
            ->andWhere('mailbox.specialUse = :drafts')
            ->andWhere('account.isPrimary = :isPrimary')
            ->setParameter('isActive', true)
            ->setParameter('usr', $user)
            ->setParameter('drafts', MailboxSpecialUse::DRAFTS)
            ->setParameter('isPrimary', true)
            ->getQuery()
            ->getSingleResult();
    }
}
