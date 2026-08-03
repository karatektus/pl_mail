<?php

declare(strict_types=1);

namespace App\Command\Backfill;

use App\Domain\Helper\MessageIdHelper;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Repository\Mail\AccountRepository;
use App\Repository\Mail\MessageRepository;
use App\Service\Imap\MessageThreader;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rebuilds every thread from stored message data — no mail server contact.
 *
 * Needed once because threading was previously wrong in two ways that cannot be
 * repaired in place: Message-IDs were written with angle brackets on some
 * backends and without on others (so References threading never matched), and
 * the subject fallback merged any same-subject mail regardless of whether it was
 * a reply (so recurring notifications accreted into one endless thread).
 * Correcting either one changes which thread a message belongs to, so the
 * threads are discarded and recomputed rather than patched.
 *
 * Threads are rebuilt through the same MessageThreader the sync paths use, so
 * there is exactly one threading implementation to reason about.
 *
 * Note: provider thread keys (Gmail threadId, Graph conversationId) cannot be
 * recovered from stored data — they are written by the next sync. Until then,
 * already-synced API messages rethread by References and subject.
 */
final readonly class RethreadBackfillTask implements BackfillTaskInterface
{
    private const int BATCH_SIZE = 500;

    public function __construct(
        private AccountRepository      $accountRepository,
        private MessageRepository      $messageRepository,
        private MessageThreader        $threader,
        private EntityManagerInterface $em,
    ) {}

    public function getName(): string
    {
        return 'rethread';
    }

    public function getDescription(): string
    {
        return 'Normalise stored Message-IDs and rebuild all threads from scratch.';
    }

    public function run(SymfonyStyle $io): int
    {
        $accounts = $this->accountRepository->findBy(['isActive' => true]);

        if (count($accounts) === 0) {
            $io->warning('No active accounts.');

            return Command::SUCCESS;
        }

        $io->note('Threads are discarded and rebuilt. Per-thread starring, snoozing, category and labels are carried over; nothing else on the thread row survives.');

        if (false === $io->confirm('Rebuild all threads now?', false)) {
            return Command::SUCCESS;
        }

        foreach ($accounts as $account) {
            $this->runForAccount($io, $account);
        }

        return Command::SUCCESS;
    }

    private function runForAccount(SymfonyStyle $io, Account $account): void
    {
        $accountId = (int) $account->getId();

        $io->section(sprintf('Account #%d (%s)', $accountId, $account->getEmail()));

        $normalised = $this->normaliseStoredIds($io, $accountId);
        $io->text(sprintf('Normalised message-ids on %d message(s).', $normalised));

        $snapshots = $this->snapshotThreadState($accountId);
        $io->text(sprintf('Captured state for %d existing thread(s).', count($snapshots)));

        $this->discardThreads($accountId);

        $rebuilt = $this->rebuildThreads($io, $accountId);

        $restored = $this->restoreThreadState($accountId, $snapshots);

        $io->success(sprintf(
            'Rethreaded %d message(s); restored state on %d thread(s).',
            $rebuilt,
            $restored,
        ));
    }

    /**
     * Strips angle brackets from message_id and from every entry in the
     * in_reply_to / thread_references arrays, so old rows compare equal to
     * newly-synced ones.
     */
    private function normaliseStoredIds(SymfonyStyle $io, int $accountId): int
    {
        $touched = 0;

        foreach ($this->idChunks($accountId) as $ids) {
            foreach ($this->messagesByIds($ids) as $message) {
                $messageId  = $message->messageId;
                $inReplyTo  = MessageIdHelper::normaliseList($message->inReplyTo);
                $references = MessageIdHelper::normaliseList($message->references);

                $normalisedId = null === $messageId ? null : MessageIdHelper::normalise($messageId);

                if (
                    $normalisedId === $messageId
                    && $inReplyTo === $message->inReplyTo
                    && $references === $message->references
                ) {
                    continue;
                }

                $message->messageId = $normalisedId;
                $message->inReplyTo = $inReplyTo;
                $message->references = $references;

                ++$touched;
            }

            $this->em->flush();
            $this->em->clear();
        }

        return $touched;
    }

    /**
     * Per-thread state worth keeping, anchored to the thread's earliest message
     * so it can be found again once threads have been rebuilt.
     *
     * @return list<array{anchor: int, starredAt: ?string, snoozedUntil: ?string, category: ?string, labels: list<int>}>
     */
    private function snapshotThreadState(int $accountId): array
    {
        $connection = $this->em->getConnection();

        $rows = $connection->fetchAllAssociative(
            'SELECT t.id, t.starred_at, t.snoozed_until, t.category, MIN(m.id) AS anchor
             FROM message_thread t
             INNER JOIN message m ON m.thread_id = t.id
             WHERE t.account_id = :accountId
             GROUP BY t.id',
            ['accountId' => $accountId],
        );

        if (count($rows) === 0) {
            return [];
        }

        $labelsByThread = [];

        foreach ($connection->fetchAllAssociative(
            'SELECT message_thread_id, label_id FROM thread_label WHERE message_thread_id IN (:threadIds)',
            ['threadIds' => array_map(static fn(array $row): int => (int) $row['id'], $rows)],
            ['threadIds' => ArrayParameterType::INTEGER],
        ) as $row) {
            $labelsByThread[(int) $row['message_thread_id']][] = (int) $row['label_id'];
        }

        $snapshots = [];

        foreach ($rows as $row) {
            $threadId = (int) $row['id'];

            $snapshots[] = [
                'anchor'       => (int) $row['anchor'],
                'starredAt'    => $row['starred_at'],
                'snoozedUntil' => $row['snoozed_until'],
                'category'     => $row['category'],
                'labels'       => $labelsByThread[$threadId] ?? [],
            ];
        }

        return $snapshots;
    }

    /**
     * Detaches messages first, then drops the thread rows.
     *
     * Deliberately DBAL, not the ORM: MessageThread cascades remove onto its
     * messages, so removing threads through the EntityManager would delete the
     * mail along with them.
     */
    private function discardThreads(int $accountId): void
    {
        $connection = $this->em->getConnection();

        $connection->executeStatement(
            'UPDATE message SET thread_id = NULL WHERE account_id = :accountId',
            ['accountId' => $accountId],
        );

        $connection->executeStatement(
            'DELETE FROM message_thread WHERE account_id = :accountId',
            ['accountId' => $accountId],
        );

        $this->em->clear();
    }

    /**
     * Replays every message through the threader in arrival order, so a parent
     * always exists by the time its replies are threaded.
     *
     * Flushes per message rather than per batch: two messages in one batch can
     * belong to the same new thread, and the threader can only find that thread
     * once it is in the database.
     */
    private function rebuildThreads(SymfonyStyle $io, int $accountId): int
    {
        $total = (int) $this->messageRepository->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.account = :accountId')
            ->setParameter('accountId', $accountId)
            ->getQuery()
            ->getSingleScalarResult();

        if (0 === $total) {
            return 0;
        }

        $io->progressStart($total);

        $processed = 0;

        foreach ($this->idChunks($accountId, orderByArrival: true) as $ids) {
            $account = $this->em->getReference(Account::class, $accountId);

            foreach ($this->messagesByIds($ids, orderByArrival: true) as $message) {
                $this->threader->assignThread($message, $account);

                $this->em->flush();

                ++$processed;
                $io->progressAdvance();
            }

            $this->em->clear();
        }

        $io->progressFinish();

        return $processed;
    }

    /**
     * @param list<array{anchor: int, starredAt: ?string, snoozedUntil: ?string, category: ?string, labels: list<int>}> $snapshots
     */
    private function restoreThreadState(int $accountId, array $snapshots): int
    {
        if (count($snapshots) === 0) {
            return 0;
        }

        $connection = $this->em->getConnection();
        $restored   = 0;

        foreach ($snapshots as $snapshot) {
            $threadId = $connection->fetchOne(
                'SELECT thread_id FROM message WHERE id = :anchor',
                ['anchor' => $snapshot['anchor']],
            );

            if (false === $threadId || null === $threadId) {
                continue;
            }

            // COALESCE, not assignment: several old threads can collapse into one
            // rebuilt thread, and a value already restored by an earlier snapshot
            // must not be blanked by a later one that happened to be empty.
            $connection->executeStatement(
                'UPDATE message_thread
                 SET starred_at    = COALESCE(starred_at, :starredAt),
                     snoozed_until = COALESCE(snoozed_until, :snoozedUntil),
                     category      = COALESCE(category, :category)
                 WHERE id = :threadId',
                [
                    'starredAt'    => $snapshot['starredAt'],
                    'snoozedUntil' => $snapshot['snoozedUntil'],
                    'category'     => $snapshot['category'],
                    'threadId'     => $threadId,
                ],
            );

            foreach ($snapshot['labels'] as $labelId) {
                $connection->executeStatement(
                    'INSERT INTO thread_label (message_thread_id, label_id)
                     VALUES (:threadId, :labelId)
                     ON CONFLICT DO NOTHING',
                    ['threadId' => $threadId, 'labelId' => $labelId],
                );
            }

            ++$restored;
        }

        return $restored;
    }

    /**
     * Ids up front, entities in chunks: the rebuild clears the EntityManager on
     * every batch, which would invalidate a cursor held across it.
     *
     * @return iterable<list<int>>
     */
    private function idChunks(int $accountId, bool $orderByArrival = false): iterable
    {
        $qb = $this->messageRepository->createQueryBuilder('m')
            ->select('m.id')
            ->where('m.account = :accountId')
            ->setParameter('accountId', $accountId);

        if (true === $orderByArrival) {
            $qb->orderBy('m.receivedAt', 'ASC');
        }

        $qb->addOrderBy('m.id', 'ASC');

        $ids = array_map(
            static fn(array $row): int => (int) $row['id'],
            $qb->getQuery()->getArrayResult(),
        );

        foreach (array_chunk($ids, self::BATCH_SIZE) as $chunk) {
            yield $chunk;
        }
    }

    /**
     * @param list<int> $ids
     *
     * @return list<Message>
     */
    private function messagesByIds(array $ids, bool $orderByArrival = false): array
    {
        $qb = $this->messageRepository->createQueryBuilder('m')
            ->where('m.id IN (:ids)')
            ->setParameter('ids', $ids);

        if (true === $orderByArrival) {
            $qb->orderBy('m.receivedAt', 'ASC');
        }

        $qb->addOrderBy('m.id', 'ASC');

        return $qb->getQuery()->getResult();
    }
}
