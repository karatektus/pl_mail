<?php

declare(strict_types=1);

namespace App\Repository\Mail\Message;

use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageLocation;
use App\Entity\Mail\MessageThread;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\AbstractQuery;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Messages by Message-ID and the replies that name one, for threading.
 */
class MessageThreadingRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Message::class);
    }

    /**
     * Every Message-ID a row points at, In-Reply-To and References as one
     * jsonb array.
     *
     * Spelled once because an index is built on exactly this expression
     * (idx_message_referenced_ids, Version20261016130000) and the planner only
     * uses it for a query that states the same one. A change here without a
     * migration that rebuilds the index leaves the lookup correct and slow,
     * which on a large mailbox is the quadratic import the index exists to
     * prevent.
     */
    private const string REFERENCED_IDS = <<<'SQL'
        (COALESCE(in_reply_to::jsonb, '[]'::jsonb) || COALESCE(thread_references::jsonb, '[]'::jsonb))
        SQL;

    /**
     * Cut every message of an account loose from its thread.
     *
     * Deliberately a single UPDATE and deliberately not the ORM: MessageThread
     * cascades remove onto its messages, so detaching by walking the
     * association and then removing threads through the EntityManager would
     * delete the mail along with them.
     */
    public function detachAllFromThreadsForAccount(int $accountId): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE message SET thread_id = NULL WHERE account_id = :accountId',
            ['accountId' => $accountId],
        );
    }

    /**
     * Joined via thread since Gmail-API messages carry no mailbox — a join
     * across an association, which findOneBy() has no way to state.
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

    /**
     * The provider's thread key for mail answering these Message-IDs: the
     * Gmail threadId a reply has to be sent into (#676).
     *
     * The newest message named wins. After a conversation has been split —
     * which is what sending without a threadId did — its messages sit in
     * different Gmail threads, and the one the latest mail is in is where the
     * other side's next answer will go too.
     *
     * The message's own key first, then its thread's: a row plMail wrote when
     * it sent the mail has none of its own until Gmail's copy is synced onto
     * it, but it sits in the thread it was answering, and that thread has one.
     *
     * Scoped to the account sending, through the thread like
     * findOneByMessageIdsForAccount(): a key from another account's Gmail
     * would name a thread this one does not have, and Gmail refuses the send.
     *
     * @param list<string> $messageIds normalised, as MessageIdHelper writes them
     */
    public function findProviderThreadKeyForReply(array $messageIds, Account $account): ?string
    {
        if (count($messageIds) === 0) {
            return null;
        }

        $key = $this->createQueryBuilder('message')
            ->select('COALESCE(message.providerThreadKey, thread.providerThreadKey)')
            ->innerJoin('message.thread', 'thread')
            ->where('thread.account = :account')
            ->andWhere('message.messageId IN (:messageIds)')
            ->andWhere('message.providerThreadKey IS NOT NULL OR thread.providerThreadKey IS NOT NULL')
            ->setParameter('account', $account)
            ->setParameter('messageIds', $messageIds)
            ->orderBy('message.receivedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult(AbstractQuery::HYDRATE_SINGLE_SCALAR);

        return is_string($key) && '' !== $key ? $key : null;
    }

    /**
     * The row plMail wrote when it sent this mail from this account, while
     * Gmail's copy of it has not been synced onto it yet (#676).
     *
     * Sent by plMail (sentAt), and no Gmail id: once it has one, the sync finds
     * it by that id and never asks here, and a row that already carries a
     * different id is a different Gmail message which must not be given a
     * second one. Lowest id first, so a retried batch picks the same row.
     */
    public function findSentAwaitingGmailCopy(Account $account, string $messageId): ?Message
    {
        return $this->createQueryBuilder('message')
            ->where('message.account = :account')
            ->andWhere('message.messageId = :messageId')
            ->andWhere('message.sentAt IS NOT NULL')
            ->andWhere('message.gmailId IS NULL')
            ->setParameter('account', $account)
            ->setParameter('messageId', $messageId)
            ->orderBy('message.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * The messages of an account that name this Message-ID in In-Reply-To or
     * References — its replies, and their replies, wherever they were threaded.
     *
     * The other direction from findOneByMessageIdsForAccount(), for the message
     * that arrives after the mail answering it (#172). Raw SQL for the ids,
     * because containment in a json array has no DQL spelling; entities for
     * the result, through findBy(), so a row this unit of work already holds
     * comes back as the instance it holds. That matters to the caller: a reply
     * threaded earlier in the same batch has its thread only in memory until
     * the batch is flushed, and the database still says NULL.
     *
     * The threader also asks it about a Message-ID nobody has, to find the
     * other mail that hangs off the same missing conversation start (#377).
     *
     * Both spellings of the id are asked for. Rows synced before Message-IDs
     * were normalised still hold "<x@host>" in their arrays until the rethread
     * backfill has run over them, and `@>` compares exactly.
     *
     * @return list<Message>
     */
    public function findRepliesToForAccount(string $messageId, Account $account): array
    {
        $sql = sprintf(
            <<<'SQL'
                SELECT id
                  FROM message
                 WHERE account_id = :accountId
                   AND %s @> ANY (ARRAY[jsonb_build_array(CAST(:bare AS text)), jsonb_build_array(CAST(:bracketed AS text))])
                SQL,
            self::REFERENCED_IDS,
        );

        $ids = $this->getEntityManager()->getConnection()->fetchFirstColumn(
            $sql,
            [
                'accountId' => $account->id,
                'bare'      => $messageId,
                'bracketed' => sprintf('<%s>', $messageId),
            ],
            ['accountId' => ParameterType::INTEGER],
        );

        if ([] === $ids) {
            return [];
        }

        return array_values($this->findBy(['id' => array_map(intval(...), $ids)], ['id' => 'ASC']));
    }

    /**
     * The account's own copy of a message (via mailbox or thread ownership)
     * by canonical RFC Message-ID — the enrichment target for Gmailify dedup.
     *
     * The OR across two joins is what findOneBy() cannot say: ownership lives
     * on whichever of the two associations this message happens to have.
     */
    public function findOneForAccountByMessageId(Account $account, string $messageId): ?Message
    {
        return $this->createQueryBuilder('message')
            ->leftJoin('message.thread', 'thread')
            ->where('message.messageId = :messageId')
            ->andWhere('EXISTS (SELECT l.id FROM ' . MessageLocation::class . ' l JOIN l.mailbox mailbox WHERE l.message = message AND mailbox.account = :account) OR thread.account = :account')
            ->setParameter('messageId', $messageId)
            ->setParameter('account', $account)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
