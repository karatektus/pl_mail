<?php

declare(strict_types=1);

namespace App\Service\Gmail;

use App\Entity\Mail\Account;
use App\Infrastructure\Messaging\Message\SyncGmailMessageBatchMessage;
use App\Repository\Mail\MessageRepository;
use App\Service\Mail\GmailApiClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Plans Gmail sync work and fans it out to SyncGmailMessageBatchMessage jobs.
 *
 * Label-based architecture: the planner operates on the Account directly —
 * Gmail accounts have no Mailbox rows anymore. Each batch handler resolves
 * a message's labelIds to Label entities via GmailLabelResolver and filters
 * out messages not addressed to the owning account via GmailAddressFilter.
 */
final class GmailApiSyncer
{
    /** Message-list page size (API max is 500). */
    private const int PAGE_SIZE = 500;

    /** Gmail message IDs per fan-out batch. */
    private const int BATCH_SIZE = 100;

    /** Seconds before an unfinished backfill lists again. */
    private const int BACKFILL_COOLDOWN = 3600;

    /** Hourly listings before an unfinished backfill stops retrying. */
    private const int BACKFILL_MAX_ATTEMPTS = 24;

    public function __construct(
        private readonly GmailApiClient         $apiClient,
        private readonly MessageRepository      $messageRepository,
        private readonly EntityManagerInterface $em,
        private readonly MessageBusInterface    $bus,
        private readonly LoggerInterface        $logger,
    ) {}

    /**
     * Snapshot the historyId up front, then walk the backlog.
     *
     * The historyId is stored before any message is fetched, on purpose: it
     * marks where incremental sync should pick up, and anything Gmail adds
     * from here on must be seen by syncIncremental() rather than depend on
     * this run finishing. What it deliberately does NOT mean is "the backlog
     * is in" — that is backfill()'s own state, so an initial sync cut short
     * by a worker restart, a rate limit or a deploy resumes on the next run
     * instead of being written off as complete.
     */
    public function initialSync(Account $account): void
    {
        $profile          = $this->apiClient->getProfile($account);
        $currentHistoryId = (string) ($profile['historyId'] ?? '');

        if ('' !== $currentHistoryId) {
            $account->setGmailHistoryId($currentHistoryId);
            $this->em->flush();
        }

        $this->backfill($account);
    }

    /**
     * Walk backwards through the mailbox until the sync cap is satisfied.
     *
     * Runs on every sync, not just the first, because the cap is a setting the
     * user can raise later — and raising it has to mean something. Returns
     * immediately once a backfill has covered the current cap, so a settled
     * account pays one cheap check rather than a listing.
     *
     * Gmail lists newest-first, so a capped account never pages through the
     * whole backlog; an uncapped one walks the lot exactly once.
     */
    public function backfill(Account $account): void
    {
        if (false === $account->needsBackfill()) {
            return;
        }

        // Batches are dispatched asynchronously, so a listing run started
        // minutes ago is probably still draining. Re-listing now would find
        // the same ids missing and dispatch them a second time; an hour is
        // long enough for the queue to have made visible progress.
        $ranAt = $account->getBackfillRanAt();

        if (null !== $ranAt && $ranAt > new \DateTimeImmutable(sprintf('-%d seconds', self::BACKFILL_COOLDOWN))) {
            return;
        }

        $limit = $account->getSyncLimit();

        $this->logger->info('GmailApiSyncer: planning backfill', [
            'accountId' => $account->getId(),
            'account'   => $account->getEmail(),
            'limit'     => 0 === $limit ? 'none' : $limit,
            'completed' => $account->getBackfillTarget() ?? 'never',
        ]);

        $account->setBackfillRanAt(new \DateTimeImmutable());
        $this->em->flush();

        // No labelIds filter — fetch all mail (inbox, sent, spam, trash, …).
        $messageRefs = $this->apiClient->listMessages($account, [
            'maxResults' => self::PAGE_SIZE,
        ], $limit);

        $pending = $this->newGmailIds($account, $messageRefs);

        $this->dispatchBatches($account, $pending);

        $this->settleBackfill($account, $limit, count($pending));
    }

    /**
     * Decide whether the backfill has reached the cap, and record it if so.
     *
     * The obvious signal — a listing that turns up nothing unfetched — is the
     * common one, but it is not guaranteed to arrive. Messages the handler
     * declines to store (attributable to no known account: chats, drafts with
     * no recipient, mail bearing neither the account nor a sibling in its
     * headers) are listed forever and stored never, so waiting only for zero
     * would re-list the mailbox every hour for the lifetime of the account.
     *
     * Hence the attempt ceiling: a day of hourly runs is far longer than a
     * queue needs to drain, so anything still outstanding is not going to
     * arrive by listing again. It gets logged rather than retried silently.
     */
    private function settleBackfill(Account $account, int $limit, int $pending): void
    {
        $attempts = $account->getBackfillAttempts() + 1;

        if ($pending > 0 && $attempts < self::BACKFILL_MAX_ATTEMPTS) {
            $account->setBackfillAttempts($attempts);
            $this->em->flush();

            return;
        }

        if ($pending > 0) {
            $this->logger->warning('GmailApiSyncer: backfill giving up on outstanding messages', [
                'accountId'   => $account->getId(),
                'outstanding' => $pending,
                'attempts'    => $attempts,
            ]);
        }

        // Record how far this reached so later runs skip the listing entirely,
        // until the cap is raised past it.
        $account->setBackfillTarget($limit);
        $account->setBackfillAttempts(0);
        $this->em->flush();

        $this->logger->info('GmailApiSyncer: backfill complete', [
            'accountId' => $account->getId(),
            'target'    => 0 === $limit ? 'none' : $limit,
        ]);
    }

    /**
     * Read history since the stored historyId, dispatch batches for newly-added
     * messages, and advance the stored historyId.
     */
    public function syncIncremental(Account $account): void
    {
        $startHistoryId = $account->getGmailHistoryId();

        if (null === $startHistoryId) {
            $this->logger->warning('GmailApiSyncer: no historyId stored, running initial sync', [
                'accountId' => $account->getId(),
            ]);
            $this->initialSync($account);

            return;
        }

        try {
            // No labelId filter — track additions across all labels.
            $result = $this->apiClient->listHistory($account, $startHistoryId, [
                'historyTypes' => 'messageAdded',
            ]);
        } catch (\Throwable $e) {
            if (
                true === str_contains($e->getMessage(), '404')
                || true === str_contains($e->getMessage(), '410')
            ) {
                $this->logger->warning('GmailApiSyncer: historyId expired, re-running initial sync', [
                    'accountId' => $account->getId(),
                ]);
                $account->setGmailHistoryId(null);
                $this->em->flush();
                $this->initialSync($account);

                return;
            }

            throw $e;
        }

        $refs = [];

        foreach ($result['history'] as $record) {
            foreach ($record['messagesAdded'] ?? [] as $added) {
                $id = (string) ($added['message']['id'] ?? '');

                if ('' !== $id) {
                    $refs[] = ['id' => $id];
                }
            }
        }

        $this->dispatchBatches($account, $this->newGmailIds($account, $refs));

        $account->setGmailHistoryId((string) $result['historyId']);
        $this->em->flush();
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * @param list<array{id?: string}> $refs
     * @return list<string>
     */
    private function newGmailIds(Account $account, array $refs): array
    {
        $syncedGmailIds = array_flip(
            $this->messageRepository->findSyncedGmailIdsForUser($account->getUsr())
        );

        $pending = [];

        foreach ($refs as $ref) {
            $gmailId = (string) ($ref['id'] ?? '');

            if ('' === $gmailId) {
                continue;
            }

            if (true === isset($syncedGmailIds[$gmailId])) {
                continue;
            }

            $pending[] = $gmailId;
        }

        return $pending;
    }

    /**
     * @param list<string> $gmailIds
     */
    private function dispatchBatches(Account $account, array $gmailIds): void
    {
        if (count($gmailIds) === 0) {
            return;
        }

        $batches = array_chunk($gmailIds, self::BATCH_SIZE);

        foreach ($batches as $batch) {
            $this->bus->dispatch(new SyncGmailMessageBatchMessage(
                (int) $account->getId(),
                $batch,
            ));
        }

        $this->logger->info('GmailApiSyncer: batches dispatched', [
            'accountId' => $account->getId(),
            'messages'  => count($gmailIds),
            'batches'   => count($batches),
        ]);
    }
}
