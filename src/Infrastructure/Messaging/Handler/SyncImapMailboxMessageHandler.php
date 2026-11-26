<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Entity\Mail\Mailbox;
use App\Infrastructure\Messaging\Message\SyncImapMailboxMessage;
use App\Repository\Mail\Mailbox\MailboxRepository;
use App\Service\Imap\ImapConnector;
use App\Service\Imap\ImapDeadline;
use App\Service\Imap\MailboxSyncQueue;
use App\Service\Imap\MessageSyncer;
use App\Service\Mail\SyncNotifier;
use App\Service\Mail\SyncOrigin;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * One mailbox, re-read from the server.
 *
 * THE GUARD BELOW IS NOT AN OPTIMISATION
 * ──────────────────────────────────────
 * Nothing in Messenger deduplicates, and nothing used to check whether a sync
 * for this mailbox was already queued — so a burst of IDLE notifications put a
 * job in the queue for each, and every one of them opened its own IMAP session
 * and re-listed the entire folder. Observed at nine identical jobs at a time
 * against a 1832-message inbox, feeding itself: the listing provoked the
 * notification that queued the next listing.
 *
 * ImapIdleListener now debounces at the dispatching end, which is where the fix
 * belongs. This is the second line, and it is here because the first one is a
 * property of ONE caller: a future dispatcher — a retry, a command, a
 * webhook — would reintroduce the pile-up with nothing to stop it, and the
 * symptom is bandwidth rather than an error, so nobody would find out from a
 * log. Refusing at the handler makes the cost of a duplicate a lookup rather
 * than a folder listing, whoever queued it.
 *
 * It only sees syncs that WORKED, though: syncedAt is written on success. A
 * folder whose syncs ran into the deadline never wrote it, so every queued
 * duplicate went through and spent its five minutes (#418). MailboxSyncQueue
 * is what stops those now, by not letting them be queued in the first place,
 * and a sync the deadline cut off is not retried — see handle().
 */
#[AsMessageHandler]
final readonly class SyncImapMailboxMessageHandler
{
    /**
     * How recently a sync must have finished for the next one to be redundant.
     *
     * Deliberately far below any cadence that dispatches on purpose — the
     * scheduler's is minutes — so this can only ever collapse a burst. It is
     * also below SYNC_MIN_GAP in ImapIdleListener, so a debounced dispatch from
     * there is never the thing this refuses; only genuine duplicates are.
     *
     * The risk it accepts, stated plainly: a real change landing in the same
     * three seconds as a sync is not read until the next one. Flags no longer
     * depend on this path at all — ApplyRemoteFlagsMessage carries those — and
     * new mail announces itself again on the next EXISTS, so the exposure is a
     * deletion waiting for the sweep cadence it was already on.
     */
    private const int MIN_RESYNC_GAP_SECONDS = 3;

    public function __construct(
        private MailboxRepository     $mailboxRepository,
        private MessageSyncer         $messageSyncer,
        private ImapConnector         $connector,
        private SyncNotifier          $syncNotifier,
        private LoggerInterface       $logger,
        private SyncOrigin            $origin,
        private MailboxSyncQueue      $syncQueue,
        private ImapDeadline          $deadline,
    ) {}

    public function __invoke(SyncImapMailboxMessage $message): void
    {
        // Everything stored from here on is marked with what started this
        // sync. See SyncOrigin, and SyncAccountMessage for the `??`.
        $this->origin->during($message->trigger ?? null, fn () => $this->handle($message));
    }

    private function handle(SyncImapMailboxMessage $message): void
    {
        // First, before anything can return or throw: from here on a change
        // in the folder is not covered by this sync, and must queue its own.
        $this->syncQueue->taken($message->mailboxId);

        $mailbox = $this->mailboxRepository->find($message->mailboxId);

        if (null === $mailbox) {
            $this->logger->info('Mailbox not found', ['mailboxId' => $message->mailboxId]);
            return;
        }

        if (false === $mailbox->isSyncEnabled) {
            $this->logger->info('Mailbox sync disabled', ['mailboxId' => $message->mailboxId]);
            return;
        }

        if (true === $this->syncedJustNow($mailbox)) {
            $this->logger->debug('Skipping a sync that another job has just performed', [
                'mailboxId' => $message->mailboxId,
            ]);

            return;
        }

        $session = $this->connector->session($mailbox->account);

        try {
            $this->messageSyncer->syncMailbox($mailbox, $session);
        } catch (\Throwable $failure) {
            // Cut off by the deadline: let it go rather than retry it. Each
            // retry would be another five minutes with every other account's
            // mail waiting behind it, and the folder's next IDLE notice or the
            // account's poll asks for this sync again anyway (#418).
            // ImapDeadline has already logged the cut as a warning.
            if (true === $this->deadline->hasExpired()) {
                $this->logger->info('Mailbox sync cut off by the IMAP deadline, not retried', [
                    'mailboxId' => $message->mailboxId,
                    'error'     => $failure->getMessage(),
                ]);

                return;
            }

            throw $failure;
        } finally {
            $session->logout();
        }

        // MessageSyncer clears the EntityManager mid-run, so reload before notifying.
        $mailbox = $this->mailboxRepository->find($message->mailboxId);

        if (null === $mailbox) {
            return;
        }

        $account = $mailbox->account;

        $this->syncNotifier->publishMailboxSynced($account, $mailbox);
    }

    /**
     * Whether somebody else already did this, moments ago.
     *
     * syncedAt is written by the syncer itself, so this reads what actually
     * happened rather than what was dispatched — which is the only version of
     * the question worth asking when the whole problem is duplicate dispatches.
     * A mailbox that has never synced has no timestamp and is never refused.
     */
    private function syncedJustNow(Mailbox $mailbox): bool
    {
        if (null === $mailbox->syncedAt) {
            return false;
        }

        return $mailbox->syncedAt->getTimestamp() > time() - self::MIN_RESYNC_GAP_SECONDS;
    }
}
