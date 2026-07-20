<?php

namespace App\Repository;

use App\Domain\Enum\LabelRole;
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

    /**
     * Joined via thread since Gmail-API messages carry no mailbox.
     */
    public function findOneByMessageIdsForAccount(array $messageIds, Account $account): ?Message
    {
        if (count($messageIds) === 0) {
            return null;
        }

        return $this->createQueryBuilder('message')
            ->innerJoin('message.thread', 'thread')
            ->where('thread.account = :account')
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

    /**
     * Label-based: covers Gmail drafts too (no mailbox to join through).
     */
    public function findDrafts(): array
    {
        return $this->createQueryBuilder('m')
            ->innerJoin('m.labels', 'l')
            ->where('l.role = :drafts')
            ->setParameter('drafts', LabelRole::Drafts)
            ->orderBy('m.updatedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Stream every message belonging to an account — via its mailbox (IMAP)
     * or its thread (Gmail-API messages carry no mailbox row).
     *
     * @return iterable<Message>
     */
    public function iterateForAccount(Account $account): iterable
    {
        return $this->createQueryBuilder('message')
            ->leftJoin('message.mailbox', 'mailbox')
            ->leftJoin('message.thread', 'thread')
            ->where('mailbox.account = :account OR thread.account = :account')
            ->setParameter('account', $account)
            ->getQuery()
            ->toIterable();
    }

    /**
     * Does the account already hold a message with this RFC Message-ID
     * (via mailbox or thread ownership)? Used by the Gmailify importer to
     * yield to the sibling's own IMAP copy.
     */
    public function existsForAccountByMessageId(Account $account, string $messageId): bool
    {
        $count = (int) $this->createQueryBuilder('message')
            ->select('COUNT(message.id)')
            ->leftJoin('message.mailbox', 'mailbox')
            ->leftJoin('message.thread', 'thread')
            ->where('message.messageId = :messageId')
            ->andWhere('mailbox.account = :account OR thread.account = :account')
            ->setParameter('messageId', $messageId)
            ->setParameter('account', $account)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    /**
     * A Gmail-imported message on this account with the given RFC Message-ID
     * that has no IMAP location yet — claimable by the IMAP syncer when the
     * server-side copy shows up.
     */
    public function findGmailOnlyByMessageId(Account $account, string $messageId): ?Message
    {
        return $this->createQueryBuilder('message')
            ->innerJoin('message.thread', 'thread')
            ->where('message.messageId = :messageId')
            ->andWhere('message.gmailId IS NOT NULL')
            ->andWhere('message.imapUid IS NULL')
            ->andWhere('thread.account = :account')
            ->setParameter('messageId', $messageId)
            ->setParameter('account', $account)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
