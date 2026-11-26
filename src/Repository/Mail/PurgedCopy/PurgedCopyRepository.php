<?php

declare(strict_types=1);

namespace App\Repository\Mail\PurgedCopy;

use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\PurgedCopy;
use DateTimeImmutable;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Copies plMail deleted on an IMAP server and is waiting to see gone.
 *
 * @extends ServiceEntityRepository<PurgedCopy>
 */
class PurgedCopyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PurgedCopy::class);
    }

    /**
     * Purged email of this account that a new copy might be: matched by
     * Message-ID or by the server's own email id, whichever the copy has.
     *
     * @param list<string> $messageIds
     * @param list<string> $serverIds
     *
     * @return list<PurgedCopy>
     */
    public function findForAccountByIds(Account $account, array $messageIds, array $serverIds): array
    {
        if ([] === $messageIds && [] === $serverIds) {
            return [];
        }

        $qb = $this->createQueryBuilder('p')
            ->join('p.mailbox', 'mb')
            ->where('mb.account = :account')
            ->setParameter('account', $account);

        $either = $qb->expr()->orX();

        if ([] !== $messageIds) {
            $either->add('p.messageId IN (:messageIds)');
            $qb->setParameter('messageIds', $messageIds);
        }

        if ([] !== $serverIds) {
            $either->add('p.serverEmailId IN (:serverIds)');
            $qb->setParameter('serverIds', $serverIds);
        }

        /** @var list<PurgedCopy> */
        return $qb->andWhere($either)->getQuery()->getResult();
    }

    /**
     * Forget the copies of this folder the server has confirmed gone.
     *
     * @param list<int> $uids
     */
    public function deleteConfirmed(Account $account, string $path, array $uids): void
    {
        if ([] === $uids) {
            return;
        }

        $this->getEntityManager()->createQueryBuilder()
            ->delete(PurgedCopy::class, 'p')
            ->where('p.imapUid IN (:uids)')
            ->andWhere('p.mailbox IN (SELECT mb.id FROM ' . Mailbox::class . ' mb WHERE mb.account = :account AND mb.fullPath = :path)')
            ->setParameter('uids', $uids)
            ->setParameter('account', $account)
            ->setParameter('path', $path)
            ->getQuery()
            ->execute();
    }

    /**
     * The UIDs in this folder plMail has purged and not yet seen gone.
     *
     * @return list<int>
     */
    public function uidsIn(Mailbox $mailbox): array
    {
        return array_map(intval(...), $this->createQueryBuilder('p')
            ->select('p.imapUid')
            ->where('p.mailbox = :mailbox')
            ->setParameter('mailbox', $mailbox)
            ->getQuery()
            ->getSingleColumnResult());
    }

    /**
     * This folder's purges older than $before, which the server should long
     * since have carried out.
     *
     * @return list<PurgedCopy>
     */
    public function findPurgedBefore(Mailbox $mailbox, DateTimeImmutable $before): array
    {
        /** @var list<PurgedCopy> */
        return $this->createQueryBuilder('p')
            ->where('p.mailbox = :mailbox')
            ->andWhere('p.purgedAt < :before')
            ->setParameter('mailbox', $mailbox)
            ->setParameter('before', $before)
            ->getQuery()
            ->getResult();
    }
}
