<?php

namespace App\Repository\Mail;

use App\Domain\Enum\Mail\MailboxSpecialUse;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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

    /** Doctrine's own findBy(), keyed in PHP — the index is not a query. */
    public function findIndexedByFullPath(Account $account): array
    {
        $mailboxes = $this->findBy(['account' => $account]);
        $indexed = [];

        foreach ($mailboxes as $mailbox) {
            $indexed[$mailbox->fullPath] = $mailbox;
        }

        return $indexed;
    }

    public function findTrashMailboxForAccount(Account $account): ?Mailbox
    {
        return $this->findOneBy(['account' => $account, 'specialUse' => MailboxSpecialUse::TRASH]);
    }

    public function findArchiveMailboxForAccount(Account $account): ?Mailbox
    {
        return $this->findOneBy(['account' => $account, 'specialUse' => MailboxSpecialUse::ARCHIVE]);
    }

    public function findSentMailboxForAccount(Account $account): ?Mailbox
    {
        return $this->findOneBy(['account' => $account, 'specialUse' => MailboxSpecialUse::SENT]);
    }

    public function findDraftMailboxForAccount(Account $account): ?Mailbox
    {
        return $this->findOneBy(['account' => $account, 'specialUse' => MailboxSpecialUse::DRAFTS]);
    }

    /**
     * Mailboxes an IDLE supervisor should hold a connection for.
     *
     * QueryBuilder on two counts: whether the owning account is still active is
     * a field of Account, which findBy() cannot reach, and the account is
     * fetch-joined because the supervisor opens a connection with its
     * credentials for every row — leaving it lazy is an N+1 across the whole
     * install at boot.
     */
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

    /**
     * QueryBuilder for the join to Account and for the id-only projection —
     * the caller compares sets of ids and has no use for a Mailbox.
     */
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
}
