<?php

declare(strict_types=1);

namespace App\Repository\Mail\Message;

use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageLocation;
use App\Entity\Mail\MessageThread;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ParameterType;
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
