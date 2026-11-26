<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\Mail\Account;
use App\Jmap\State\JmapObjectType;
use App\Jmap\State\StateManager;
use App\Repository\Mail\Account\AccountMailPurgeRepository;
use App\Repository\Mail\Account\AccountRepository;
use App\Repository\Mail\Mailbox\MailboxRepository;
use App\Repository\Mail\Pop3Uidl\Pop3UidlRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Removes the mail plMail has synced for one account — here, and never on the
 * server (#575).
 *
 * The companion to switching mail off. Off keeps what was fetched, readable,
 * for as long as the person wants it; this is how they say they do not. It is
 * offered in Settings only while mail is off, but that is a matter of what the
 * page shows, not of what this does: pressed with mail on — a second tab, a
 * stale page — it behaves exactly the same way, and still sends nothing
 * anywhere. Every statement it issues is a local one (see
 * AccountMailPurgeRepository for why it is not MessagePurger).
 *
 * The account's sync position is forgotten with the mail, the way app:reset
 * forgets it. A cursor left behind would describe messages that are no longer
 * here, and switching mail back on would then fetch only what arrived since —
 * a mailbox with a hole in it nobody could see. Forgotten, switching mail on
 * reads the mailbox again from the start, which is what an empty account
 * should do. Folders stay: they are the account's structure, labels are bound
 * to them, and they cost nothing.
 *
 * The apps are told: every removed Email and Thread is written to the JMAP
 * change log, batch by batch, as any other removal is.
 */
final readonly class LocalMailPurger
{
    /**
     * Threads per round. Each round is one transaction and one change-log
     * flush; a few hundred keeps both small without a round per thread.
     */
    private const int BATCH = 500;

    /**
     * Settings that describe how far the mailbox has been read, and that would
     * describe an empty account wrongly. Everything else in the bag — the POP3
     * delete mode, the JMAP session address, signatures — is configuration
     * and is kept.
     *
     * @var list<string>
     */
    private const array SYNC_POSITION_SETTINGS = [
        Account::SETTING_BACKFILL_TARGET,
        Account::SETTING_BACKFILL_RAN_AT,
        Account::SETTING_BACKFILL_PAGE_TOKEN,
        Account::SETTING_BACKFILL_PENDING,
        Account::SETTING_BACKFILL_ATTEMPTS,
        Account::SETTING_IMPORT_BEAT_AT,
        Account::SETTING_IMPORT_TOTAL,
        Account::SETTING_JMAP_STATES,
        Account::SETTING_JMAP_IMPORT,
        Account::SETTING_GRAPH_IMPORT,
        Account::SETTING_GRAPH_REMOVALS,
    ];

    public function __construct(
        private AccountMailPurgeRepository $purge,
        private AccountRepository          $accounts,
        private MailboxRepository          $mailboxes,
        private Pop3UidlRepository         $uidls,
        private StateManager               $state,
        private EntityManagerInterface     $em,
        private LoggerInterface            $logger,
    ) {
    }

    /**
     * @return int how many messages were removed
     */
    public function purge(Account $account): int
    {
        $accountId = (int) $account->id;
        $removed   = 0;

        while ([] !== ($threadIds = $this->purge->threadIdBatch($accountId, self::BATCH))) {
            $messageIds = $this->purge->messageIdsInThreads($threadIds);

            $this->recordDestroyed($accountId, JmapObjectType::Email, $messageIds);
            $this->recordDestroyed($accountId, JmapObjectType::Thread, $threadIds);

            $this->em->wrapInTransaction(function () use ($threadIds): void {
                $this->purge->deleteThreads($threadIds);
            });

            $removed += count($messageIds);

            // The change-log rows just flushed, and whatever was held of the
            // deleted rows, would otherwise pile up batch after batch.
            $this->em->clear();
        }

        while ([] !== ($messageIds = $this->purge->threadlessMessageIdBatch($accountId, self::BATCH))) {
            $this->recordDestroyed($accountId, JmapObjectType::Email, $messageIds);

            $this->em->wrapInTransaction(function () use ($messageIds): void {
                $this->purge->deleteMessages($messageIds);
            });

            $removed += count($messageIds);

            $this->em->clear();
        }

        // Read again: the clears above detached the one passed in.
        $account = $this->accounts->find($accountId);

        if (null === $account) {
            return $removed;
        }

        $this->forgetSyncPosition($account);
        $this->em->flush();

        $this->logger->info('LocalMailPurger: removed an account\'s synced mail from this database', [
            'accountId' => $accountId,
            'messages'  => $removed,
        ]);

        return $removed;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * @param list<int> $ids
     */
    private function recordDestroyed(int $accountId, JmapObjectType $type, array $ids): void
    {
        foreach ($ids as $id) {
            $this->state->recordDestroyed($accountId, $type, (string) $id);
        }

        // StateManager persists without flushing, by design; here there is no
        // surrounding unit of work to ride on, so each batch commits its own.
        $this->em->flush();
    }

    /**
     * Back to the position of an account nothing has been read from, as
     * IncomingProtocolSwitch leaves an IMAP folder it re-plans.
     */
    private function forgetSyncPosition(Account $account): void
    {
        $account->gmailHistoryId  = null;
        $account->graphDeltaLinks = [];
        $account->lastSyncedAt    = null;

        foreach (self::SYNC_POSITION_SETTINGS as $key) {
            $account->unsetSetting($key);
        }

        foreach ($this->mailboxes->findBy(['account' => $account]) as $mailbox) {
            $mailbox->uidValidity       = null;
            $mailbox->lastSeenUid       = 0;
            $mailbox->syncedAt          = null;
            $mailbox->sweptAt           = null;
            $mailbox->failedUid         = null;
            $mailbox->failedUidAttempts = 0;
            $mailbox->importFloorUid    = null;
            $mailbox->importTotal       = null;

            // The folder's message count changed under the apps, which
            // learn it from a Mailbox change.
            $this->state->recordUpdated((int) $account->id, JmapObjectType::Mailbox, (string) $mailbox->id);
        }

        // A POP3 server's UIDLs record which messages were stored here. They
        // are not here any more, so the next fetch has to take them again.
        $this->uidls->forget($account);
    }
}
