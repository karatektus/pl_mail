<?php

declare(strict_types=1);

namespace App\Repository\Mail\MessageThread;

use App\Domain\Enum\Mail\ListSortOrder;
use App\Entity\Label\Label;
use App\Entity\Mail\Account;
use App\Entity\Mail\MessageThread;
use App\Repository\Mail\Message\MessageRepository;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Threads as threading builds and rebuilds them: finding the thread a message joins,
 * and the state carried across a rethread of an account.
 */
class MessageThreadRebuildRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MessageThread::class);
    }

    public function findOneByProviderThreadKeyForAccount(string $providerThreadKey, Account $account): ?MessageThread
    {
        return $this->findOneBy([
            'account'           => $account,
            'providerThreadKey' => $providerThreadKey,
        ]);
    }

    /**
     * Newest thread with this subject that is still recent enough to be a
     * plausible parent. The $since bound is what stops a recurring subject
     * ("Your order has shipped") from accreting into one endless thread.
     *
     * QueryBuilder because of that bound: findOneBy() compares fields to
     * values, and `lastMessageAt >= :since` is a comparison it cannot state.
     */
    public function findMatchingNormalizedSubjectThreadForAccount(
        string             $normalizedSubject,
        Account            $account,
        \DateTimeImmutable $since,
    ): ?MessageThread
    {
        return $this->createQueryBuilder('thread')
            ->where('thread.account = :account')
            ->andWhere('thread.normalizedSubject = :normalizedSubject')
            ->andWhere('thread.lastMessageAt >= :since')
            ->setParameter('account', $account)
            ->setParameter('normalizedSubject', $normalizedSubject)
            ->setParameter('since', $since)
            ->orderBy('thread.lastMessageAt', 'DESC')
            // The tiebreaker ListSortOrder explains for the folder lists, for
            // the same reason in a different shape: this takes ONE row, so two
            // candidate threads sharing a lastMessageAt made "which
            // conversation does this message join" a question the database
            // answered however it liked. Nothing is lost either way, but the
            // same mailbox could thread the same message differently on two
            // runs — and a batch landing with one timestamp is ordinary, not an
            // edge case.
            ->addOrderBy('thread.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Threads of one account with nothing left in them.
     *
     * `messages IS EMPTY` is a collection predicate; findBy() compares fields
     * to values and has no way to ask about the size of an association.
     *
     * @return list<MessageThread>
     */
    public function findEmptyForUser(UserInterface $user): array
    {
        return $this->createQueryBuilder('t')
            ->innerJoin('t.account', 'a')
            ->where('a.usr = :user')
            ->andWhere('t.messages IS EMPTY')
            ->setParameter('user', $user)
            ->getQuery()
            ->getResult();
    }

    /**
     * Per-thread state worth carrying across a rethread, anchored to each
     * thread's earliest message so it can be found again afterwards.
     *
     * Raw SQL, and scalars rather than entities, because the caller is about to
     * delete every one of these rows: hydrating them would leave a unit of work
     * full of entities whose tables are then truncated out from under it. The
     * MIN(id) anchor is an aggregate the ORM would have to load a whole thread
     * to compute.
     *
     * listed_at comes along for a reason worth stating: a rebuild drops every
     * thread row and makes new ones, and a new row is born null, which is to
     * say NEW. Without carrying it, running this maintenance command would
     * light up the account's entire history as unseen mail — the same failure
     * the migration's backfill exists to prevent, reachable from a console
     * command instead of a deploy.
     *
     * @return list<array{id: int, starred_at: ?string, snoozed_until: ?string, category: ?string, listed_at: ?string, anchor: int}>
     */
    public function findCarriedOverStateForAccount(int $accountId): array
    {
        /** @var list<array{id: int, starred_at: ?string, snoozed_until: ?string, category: ?string, listed_at: ?string, anchor: int}> */
        return $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT t.id, t.starred_at, t.snoozed_until, t.category, t.listed_at, MIN(m.id) AS anchor
             FROM message_thread t
             INNER JOIN message m ON m.thread_id = t.id
             WHERE t.account_id = :accountId
             GROUP BY t.id',
            ['accountId' => $accountId],
        );
    }

    /**
     * Which labels each of these threads carries.
     *
     * Straight off the join table: thread_label has no entity, and reading it
     * through the Label side would hydrate every label of every thread to
     * recover two integers per row.
     *
     * @param list<int> $threadIds
     *
     * @return array<int, list<int>> thread id => label ids
     */
    public function findLabelIdsByThread(array $threadIds): array
    {
        if (0 === count($threadIds)) {
            return [];
        }

        $labelsByThread = [];

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT message_thread_id, label_id FROM thread_label WHERE message_thread_id IN (:threadIds)',
            ['threadIds' => $threadIds],
            ['threadIds' => ArrayParameterType::INTEGER],
        );

        foreach ($rows as $row) {
            $labelsByThread[(int) $row['message_thread_id']][] = (int) $row['label_id'];
        }

        return $labelsByThread;
    }

    /**
     * Drop every thread row of an account without touching its mail.
     *
     * Deliberately not the ORM: MessageThread cascades remove onto its
     * messages, so removing threads through the EntityManager would delete the
     * mail along with them. Callers detach the messages first — see
     * MessageRepository::detachAllFromThreadsForAccount().
     */
    public function deleteAllForAccount(int $accountId): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'DELETE FROM message_thread WHERE account_id = :accountId',
            ['accountId' => $accountId],
        );
    }

    /**
     * Write a snapshot's starring, snoozing and category back onto a rebuilt
     * thread.
     *
     * COALESCE, not assignment, and that is why this cannot be a property
     * write: several old threads can collapse into one rebuilt thread, and a
     * value already restored by an earlier snapshot must not be blanked by a
     * later one that happened to be empty. The values arrive as the strings the
     * snapshot read, so they go back the way they came rather than through a
     * round trip that would have to guess their timezone.
     */
    public function restoreCarriedOverState(
        int     $threadId,
        ?string $starredAt,
        ?string $snoozedUntil,
        ?string $category,
        ?string $listedAt = null,
    ): void {
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE message_thread
             SET starred_at    = COALESCE(starred_at, :starredAt),
                 snoozed_until = COALESCE(snoozed_until, :snoozedUntil),
                 category      = COALESCE(category, :category),
                 listed_at     = COALESCE(listed_at, :listedAt)
             WHERE id = :threadId',
            [
                'starredAt'    => $starredAt,
                'snoozedUntil' => $snoozedUntil,
                'category'     => $category,
                // COALESCE cuts the right way here too: several old threads can
                // collapse into one rebuilt thread, and if ANY of them had been
                // shown then the conversation is not news to anybody. The first
                // non-null wins and later nulls cannot take it back.
                'listedAt'     => $listedAt,
                'threadId'     => $threadId,
            ],
        );
    }

    /**
     * Put a label back on a rebuilt thread, tolerating the one that is already
     * there.
     *
     * ON CONFLICT DO NOTHING because collapsed threads restore the same label
     * more than once, and the join table has no entity to check for first.
     */
    public function addLabelIfAbsent(int $threadId, int $labelId): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO thread_label (message_thread_id, label_id)
             VALUES (:threadId, :labelId)
             ON CONFLICT DO NOTHING',
            ['threadId' => $threadId, 'labelId' => $labelId],
        );
    }

    /**
     * Rebuild every thread's attachment counter from its messages.
     *
     * The counter is derived, so it is recomputed rather than patched by
     * deltas — a repair that has to be right whatever state it starts from.
     * One correlated UPDATE rather than loading every thread: nothing here
     * needs an entity, and the alternative is the whole mailbox in memory.
     */
    public function recomputeAttachmentCounts(): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(<<<'SQL'
            UPDATE message_thread t
            SET attachment_count = COALESCE((
                SELECT COUNT(*) FROM message m
                WHERE m.thread_id = t.id AND m.has_attachments = true
            ), 0)
        SQL);
    }
}
