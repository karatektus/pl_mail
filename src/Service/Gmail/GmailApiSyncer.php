<?php

declare(strict_types=1);

namespace App\Service\Gmail;

use App\Domain\Exception\GmailApiException;
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
            $account->gmailHistoryId = $currentHistoryId;
            $this->em->flush();
        }

        $this->backfill($account);
    }

    /**
     * Walk the whole mailbox until every message has been fetched.
     *
     * Still runs on every sync rather than only the first, because an initial
     * sync cut short by a restart, a rate limit or a deploy has to be able to
     * resume. A settled account — one whose target is 0 — pays one cheap check
     * rather than a listing, so this is only expensive while there is genuinely
     * something left to fetch.
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
        $ranAt = $account->backfillRanAt;

        if (null !== $ranAt && $ranAt > new \DateTimeImmutable(sprintf('-%d seconds', self::BACKFILL_COOLDOWN))) {
            return;
        }

        $this->logger->info('GmailApiSyncer: planning backfill', [
            'accountId' => $account->id,
            'account'   => $account->email,
            'completed' => $account->backfillTarget ?? 'never',
        ]);

        $account->backfillRanAt = new \DateTimeImmutable();
        $this->em->flush();

        // No labelIds filter — fetch all mail (inbox, sent, spam, trash, …).
        $messageRefs = $this->apiClient->listMessages($account, [
            'maxResults' => self::PAGE_SIZE,
        ]);

        $pending = $this->newGmailIds($account, $messageRefs);

        $this->dispatchBatches($account, $pending);

        $this->settleBackfill($account, count($pending));
    }

    /**
     * Decide whether the backfill has reached the end of the mailbox, and
     * record it if so.
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
    private function settleBackfill(Account $account, int $pending): void
    {
        $attempts = $account->backfillAttempts + 1;

        if ($pending > 0 && $attempts < self::BACKFILL_MAX_ATTEMPTS) {
            $account->backfillAttempts = $attempts;
            $this->em->flush();

            return;
        }

        if ($pending > 0) {
            $this->logger->warning('GmailApiSyncer: backfill giving up on outstanding messages', [
                'accountId'   => $account->id,
                'outstanding' => $pending,
                'attempts'    => $attempts,
            ]);
        }

        // 0 is "the whole mailbox", which is the only stopping point there is
        // now. Recording it is what lets every later run skip the listing.
        $account->backfillTarget = 0;
        $account->backfillAttempts = 0;
        $this->em->flush();

        $this->logger->info('GmailApiSyncer: backfill complete', [
            'accountId' => $account->id,
        ]);
    }

    /**
     * Read history since the stored historyId, dispatch batches for newly-added
     * messages, and advance the stored historyId.
     */
    public function syncIncremental(Account $account): void
    {
        $startHistoryId = $account->gmailHistoryId;

        if (null === $startHistoryId) {
            $this->logger->warning('GmailApiSyncer: no historyId stored, running initial sync', [
                'accountId' => $account->id,
            ]);
            $this->initialSync($account);

            return;
        }

        try {
            // No labelId filter — track additions across all labels.
            $result = $this->apiClient->listHistory($account, $startHistoryId, [
                'historyTypes' => 'messageAdded',
            ]);
        } catch (GmailApiException $e) {
            // Matched on the status, not the message text. It used to be
            // str_contains($e->getMessage(), '404'), which fires on any failure
            // whose wording happens to contain those digits — a quota message
            // quoting a limit, a proxy error page, a label id — and answers a
            // transient rejection by re-listing the entire mailbox.
            //
            // 404 is what Gmail documents for a startHistoryId older than the
            // ~30 days of history it keeps. 410 is not documented for
            // history.list, but it is the status Google's other cursor-based
            // endpoints use for an expired token and it means the same thing
            // here; the response to either is the only one available anyway.
            if (true === in_array($e->getStatus(), [404, 410], true)) {
                $this->logger->warning('GmailApiSyncer: historyId expired, re-running initial sync', [
                    'accountId' => $account->id,
                ]);
                $account->gmailHistoryId = null;
                $this->em->flush();
                $this->initialSync($account);

                return;
            }

            throw $e;
        }
        // No catch for anything wider on purpose. listHistory() can still fail
        // outside this hierarchy — a refresh token that no longer grants, a
        // connection reset before the status is known — but neither of those is
        // an expired cursor, and the only way to guess at one from a failure
        // that carries no status is by reading its text, which is the bug this
        // replaced. They propagate and the transport retries them.

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

        $account->gmailHistoryId = (string) $result['historyId'];
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
            $this->messageRepository->findSyncedGmailIdsForUser($account->usr)
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
                (int) $account->id,
                $batch,
            ));
        }

        $this->logger->info('GmailApiSyncer: batches dispatched', [
            'accountId' => $account->id,
            'messages'  => count($gmailIds),
            'batches'   => count($batches),
        ]);
    }
}
