<?php

namespace App\Repository;

use App\Entity\Account;
use App\Entity\Mailbox;
use App\Entity\Message;
use App\Entity\MessageThread;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;


class MessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Message::class);
    }

    public function findSyncedUids(Mailbox $mailbox): array
    {
        return $this->createQueryBuilder('m')
            ->select('m.imapUid')
            ->where('m.mailbox = :mailbox')
            ->andWhere('m.imapUid IS NOT NULL')
            ->setParameter('mailbox', $mailbox)
            ->getQuery()
            ->getSingleColumnResult();
    }

    public function findSyncedGmailIdsForUser(User $user): array
    {
        return $this->createQueryBuilder('m')
            ->select('m.gmailId')
            ->innerJoin('m.thread', 't')
            ->innerJoin('t.account', 'a')
            ->where('a.usr = :usr')
            ->andWhere('m.gmailId IS NOT NULL')
            ->setParameter('usr', $user)
            ->getQuery()
            ->getSingleColumnResult();
    }

    public function findByMailboxOrderedByDate(Mailbox $mailbox): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.mailbox = :mailbox')
            ->setParameter('mailbox', $mailbox)
            ->orderBy('m.receivedAt', 'DESC')
            ->orderBy('m.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByMessageIdsForAccount(array $messageIds, Account $account): ?Message
    {
        if (count($messageIds) === 0) {
            return null;
        }

        return $this->createQueryBuilder('message')
            ->innerJoin('message.mailbox', 'mailbox')
            ->where('mailbox.account = :account')
            ->andWhere('message.messageId IN (:messageIds)')
            ->setParameter('account', $account)
            ->setParameter('messageIds', $messageIds)
            ->orderBy('message.receivedAt', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function existsWithFromAddressInThread(string $fromAddress, MessageThread $thread): bool
    {
        $result = $this->createQueryBuilder('m')
            ->select('1')
            ->where('m.thread = :thread')
            ->andWhere('LOWER(m.fromAddress) = :fromAddress')
            ->setParameter('thread', $thread)
            ->setParameter('fromAddress', mb_strtolower($fromAddress))
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        return $result !== null;
    }

    public function countUnseenForMailbox(Mailbox $mailbox): int
    {
        return $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.mailbox = :mailbox')
            ->andWhere('m.seenAt IS NULL')
            ->setParameter('mailbox', $mailbox)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countTotalForMailbox(Mailbox $mailbox): int
    {
        return $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.mailbox = :mailbox')
            ->setParameter('mailbox', $mailbox)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function findDrafts(): array
    {
        return $this->createQueryBuilder('m')
            ->join('m.mailbox', 'mb')
            ->join('mb.account', 'a')
            ->where('mb.specialUse = :drafts')
            ->setParameter('drafts', '\\Drafts')
            ->orderBy('m.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
