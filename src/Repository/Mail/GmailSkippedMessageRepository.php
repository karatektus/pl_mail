<?php

declare(strict_types=1);

namespace App\Repository\Mail;

use App\Entity\Mail\Account;
use App\Entity\Mail\GmailSkippedMessage;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GmailSkippedMessage>
 */
class GmailSkippedMessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GmailSkippedMessage::class);
    }

    /**
     * Every Gmail id this account has an answer for that is not "stored".
     *
     * One column, for the listing: it holds this beside the stored ids while
     * it walks the mailbox, and an entity per skipped message would be two
     * thousand objects to read one string off each.
     *
     * @return list<string>
     */
    public function findGmailIdsForAccount(Account $account): array
    {
        return array_map(strval(...), $this->createQueryBuilder('s')
            ->select('s.gmailId')
            ->where('s.account = :account')
            ->setParameter('account', $account)
            ->getQuery()
            ->getSingleColumnResult());
    }

    /**
     * Which of these ids the account has skipped.
     *
     * @param list<string> $gmailIds
     *
     * @return list<string>
     */
    public function findGmailIdsAmong(Account $account, array $gmailIds): array
    {
        if ([] === $gmailIds) {
            return [];
        }

        return array_map(strval(...), $this->createQueryBuilder('s')
            ->select('s.gmailId')
            ->where('s.account = :account')
            ->andWhere('s.gmailId IN (:gmailIds)')
            ->setParameter('account', $account)
            ->setParameter('gmailIds', $gmailIds)
            ->getQuery()
            ->getSingleColumnResult());
    }

    public function countForAccount(Account $account): int
    {
        return (int) $this->createQueryBuilder('s')
            ->select('COUNT(s.id)')
            ->where('s.account = :account')
            ->setParameter('account', $account)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Forget these: they were stored after all, or somebody wants them asked
     * for again. Null forgets everything the account has skipped.
     *
     * @param list<string>|null $gmailIds
     *
     * @return int how many rows went
     */
    public function forget(Account $account, ?array $gmailIds = null): int
    {
        if ([] === $gmailIds) {
            return 0;
        }

        $delete = $this->createQueryBuilder('s')
            ->delete()
            ->where('s.account = :account')
            ->setParameter('account', $account);

        if (null !== $gmailIds) {
            $delete->andWhere('s.gmailId IN (:gmailIds)')->setParameter('gmailIds', $gmailIds);
        }

        return (int) $delete->getQuery()->execute();
    }

    /**
     * @return list<GmailSkippedMessage> oldest first
     */
    public function findForAccount(Account $account, ?int $limit = null): array
    {
        /** @var list<GmailSkippedMessage> */
        return $this->createQueryBuilder('s')
            ->where('s.account = :account')
            ->setParameter('account', $account)
            ->orderBy('s.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
