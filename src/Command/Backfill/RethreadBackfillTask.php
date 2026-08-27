<?php

declare(strict_types=1);

namespace App\Command\Backfill;

use App\Domain\Helper\MessageIdHelper;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Repository\Mail\AccountRepository;
use App\Repository\Mail\MessageRepository;
use App\Repository\Mail\MessageThreadRepository;
use App\Service\Imap\MessageThreader;
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
        private AccountRepository       $accountRepository,
        private MessageRepository       $messageRepository,
        private MessageThreadRepository $threadRepository,
        private MessageThreader         $threader,
        private EntityManagerInterface  $em,
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

        // Stored AI summaries are named explicitly because they are the one
        // loss here that cost somebody half a minute of waiting to produce.
        // They are NOT carried over, deliberately: the rebuild can change which
        // messages a thread holds, so the stored source hash would not match
        // the new transcript and the row would be invisible anyway — carrying
        // it would buy nothing and add a sixth argument to a carry-over
        // signature that is already five long.
        $io->note('Threads are discarded and rebuilt. Per-thread starring, snoozing, category and labels are carried over; nothing else on the thread row survives — including any stored AI summary, which has to be asked for again.');

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
        $accountId = (int) $account->id;

        $io->section(sprintf('Account #%d (%s)', $accountId, $account->email));

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
     * @return list<array{anchor: int, starredAt: ?string, snoozedUntil: ?string, category: ?string, listedAt: ?string, labels: list<int>}>
     */
    private function snapshotThreadState(int $accountId): array
    {
        $rows = $this->threadRepository->findCarriedOverStateForAccount($accountId);

        if (count($rows) === 0) {
            return [];
        }

        $labelsByThread = $this->threadRepository->findLabelIdsByThread(
            array_map(static fn(array $row): int => (int) $row['id'], $rows),
        );

        $snapshots = [];

        foreach ($rows as $row) {
            $threadId = (int) $row['id'];

            $snapshots[] = [
                'anchor'       => (int) $row['anchor'],
                'starredAt'    => $row['starred_at'],
                'snoozedUntil' => $row['snoozed_until'],
                'category'     => $row['category'],
                // Or the rebuild announces the account's whole history as new
                // mail — see findCarriedOverStateForAccount().
                'listedAt'     => $row['listed_at'],
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
        $this->messageRepository->detachAllFromThreadsForAccount($accountId);
        $this->threadRepository->deleteAllForAccount($accountId);

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
        $total = $this->messageRepository->count(['account' => $accountId]);

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
     * @param list<array{anchor: int, starredAt: ?string, snoozedUntil: ?string, category: ?string, listedAt: ?string, labels: list<int>}> $snapshots
     */
    private function restoreThreadState(int $accountId, array $snapshots): int
    {
        if (count($snapshots) === 0) {
            return 0;
        }

        $restored = 0;

        foreach ($snapshots as $snapshot) {
            $threadId = $this->messageRepository->findThreadIdFor($snapshot['anchor']);

            if (null === $threadId) {
                continue;
            }

            $this->threadRepository->restoreCarriedOverState(
                $threadId,
                $snapshot['starredAt'],
                $snapshot['snoozedUntil'],
                $snapshot['category'],
                $snapshot['listedAt'],
            );

            foreach ($snapshot['labels'] as $labelId) {
                $this->threadRepository->addLabelIfAbsent($threadId, $labelId);
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
        $ids = $this->messageRepository->findAllIdsForAccount($accountId, $orderByArrival);

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
        return true === $orderByArrival
            ? $this->messageRepository->findByIdsInArrivalOrder($ids)
            : $this->messageRepository->findByIds($ids);
    }
}
