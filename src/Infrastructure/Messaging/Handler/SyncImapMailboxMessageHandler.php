<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Domain\Helper\ImapConnectionFactory;
use App\Entity\Mail\Mailbox;
use App\Infrastructure\Messaging\Message\SyncImapMailboxMessage;
use App\Repository\Mail\MailboxRepository;
use App\Service\Imap\MessageSyncer;
use App\Service\Mail\SyncNotifier;
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
 * ImapIdleCommand now debounces at the dispatching end, which is where the fix
 * belongs. This is the second line, and it is here because the first one is a
 * property of ONE caller: a future dispatcher — a retry, a command, a
 * webhook — would reintroduce the pile-up with nothing to stop it, and the
 * symptom is bandwidth rather than an error, so nobody would find out from a
 * log. Refusing at the handler makes the cost of a duplicate a lookup rather
 * than a folder listing, whoever queued it.
 */
#[AsMessageHandler]
final readonly class SyncImapMailboxMessageHandler
{
    /**
     * How recently a sync must have finished for the next one to be redundant.
     *
     * Deliberately far below any cadence that dispatches on purpose — the
     * scheduler's is minutes — so this can only ever collapse a burst. It is
     * also below SYNC_MIN_GAP in ImapIdleCommand, so a debounced dispatch from
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
        private ImapConnectionFactory $imapConnectionFactory,
        private SyncNotifier          $syncNotifier,
        private LoggerInterface       $logger,
    ) {}

    public function __invoke(SyncImapMailboxMessage $message): void
    {
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

        $client = $this->imapConnectionFactory->connect($mailbox->account);

        try {
            $this->messageSyncer->syncMailbox($mailbox, $client);
        } finally {
            $client->disconnect();
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
