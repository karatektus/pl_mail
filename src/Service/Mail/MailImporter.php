<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Enum\Mail\SyncTrigger;
use App\Domain\Helper\ImapConnectionFactory;
use App\Entity\Mail\Account;
use App\Infrastructure\Messaging\Message\ImportMailMessage;
use App\Repository\Mail\AccountRepository;
use App\Repository\Mail\MailboxRepository;
use App\Service\Gmail\GmailApiSyncer;
use App\Service\Gmail\GmailBackfillStep;
use App\Service\Graph\GraphApiSyncer;
use App\Service\Graph\GraphImportStep;
use App\Service\Imap\ImapImportOutcome;
use App\Service\Imap\ImapMailboxImporter;
use App\Service\Job\JobNotifier;
use App\Service\Monitoring\ProcessHeartbeatService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;

/**
 * An account's history, brought in a page at a time without ever being in
 * front of the mail that is arriving now.
 *
 * A first sync used to BE the import: one job per account that read every
 * folder to its end, oldest mail first, on the queue new mail arrives on. An
 * account with twenty thousand messages held that queue for as long as it
 * took, showed an empty Inbox for most of it, and made its worker look dead
 * while it worked (#42). The sync now only arranges for new mail to be seen
 * and hands the history to this.
 *
 * ## The shape
 *
 * One ImportMailMessage is one page: fifty messages of an IMAP or Microsoft
 * folder, or one page of a Gmail listing. Its handler ends by dispatching the next. Nothing
 * here is a loop, and that is the design:
 *
 *   - **Accounts take turns.** Each importing account has one message on the
 *     queue at a time, so three accounts' pages interleave in arrival order
 *     and each has a usable Inbox before any has its archive.
 *   - **Within an account the Inbox leads.** MailboxRepository::findImporting()
 *     orders the folders; the first is read until it is done. Its first four
 *     pages are the newest two hundred messages.
 *   - **Nothing holds the worker.** A page is seconds, so the heartbeat that
 *     beats between jobs is enough, and a restart loses one page.
 *
 * It all runs on `ingest_backlog`, with a worker of its own — see
 * messenger.yaml for why a second queue on the same worker would not do.
 *
 * ## The safety net
 *
 * A chain of messages is only as good as its weakest link: a page that fails
 * through every retry takes the chain with it. So every ordinary sync of an
 * account ends by calling ensureRunning(), which starts a chain if the account
 * still has history to fetch and nothing has touched its import for
 * QUIET_SECONDS. "Has anything touched it" is Account::$importBeatAt, which
 * each page stamps — the same fact the progress line reads to say an import
 * has stalled.
 *
 * Two chains for one account can therefore exist, briefly, when a page is slow
 * to arrive. That is left alone: the worker takes one message at a time, a
 * page asks the server what is missing before it fetches, and the worst of it
 * is an account importing a little faster than its neighbours.
 *
 * ## Three providers, one chain
 *
 * What a page IS differs — fifty messages of an IMAP folder, a page of a
 * Gmail listing, a page of a Microsoft folder's enumeration — and each
 * provider's own class knows how to take one. What is the same is here: whose
 * turn it is, when to ask again, and who is told.
 */
final readonly class MailImporter
{
    /** Seconds of silence after which an unfinished import is started again. */
    public const int QUIET_SECONDS = 300;

    /**
     * How long to leave a page that could not go through before asking again.
     * A retry is the same messages a second time, and a folder the server
     * will not open is not going to open in the next second.
     */
    private const int WAIT_MS = 60_000;

    public function __construct(
        private AccountRepository       $accounts,
        private MailboxRepository       $mailboxes,
        private ImapMailboxImporter     $imapImporter,
        private ImapConnectionFactory   $connections,
        private GmailApiSyncer          $gmail,
        private GraphApiSyncer          $graph,
        private SyncNotifier            $notifier,
        private JobNotifier             $jobs,
        private ProcessHeartbeatService $heartbeats,
        private SyncOrigin              $origin,
        private MessageBusInterface     $bus,
        private EntityManagerInterface  $em,
        private LoggerInterface         $logger,
    ) {}

    /** Whether this account still has history that this class would fetch. */
    public function hasWork(Account $account): bool
    {
        if (true !== $account->isActive) {
            return false;
        }

        if (true === $account->isMicrosoft()) {
            return $account->needsGraphImport();
        }

        if (true === $account->isGmail()) {
            return $account->needsBackfill();
        }

        return [] !== $this->mailboxes->findImporting($account);
    }

    /**
     * Start this account's import if it has one to do and nothing is doing it.
     *
     * Called at the end of every sync, which makes it both the thing that
     * starts an import — the first sync of a new account has just planned its
     * folders — and the thing that restarts one whose chain broke.
     */
    public function ensureRunning(Account $account): void
    {
        if (false === $this->hasWork($account)) {
            return;
        }

        $beatAt = $account->importBeatAt;

        if (null !== $beatAt && $beatAt > new DateTimeImmutable(sprintf('-%d seconds', self::QUIET_SECONDS))) {
            return;
        }

        $this->beat($account);

        $this->bus->dispatch(new ImportMailMessage((int) $account->id));

        $this->logger->info('Import: started', ['accountId' => $account->id]);
    }

    /**
     * One page of this account's history, and the request for the next.
     */
    public function runPage(int $accountId): void
    {
        $account = $this->accounts->find($accountId);

        if (null === $account || false === $this->hasWork($account)) {
            // The chain ends here: nothing left, or nobody to import for.
            return;
        }

        $this->beat($account);
        $this->heartbeats->beatWhileBusy();

        // Imported mail is marked as such, like mail a poll brought in: it is
        // not something a person was just sent.
        $wait = false;

        $this->origin->during(SyncTrigger::Poll, function () use ($account, &$wait): void {
            $wait = match (true) {
                $account->isGmail()     => $this->gmailPage($account),
                $account->isMicrosoft() => GraphImportStep::Waiting === $this->graph->importPage($account),
                default                 => $this->imapPage($account),
            };
        });

        // A page clears the entity manager; this is the account as it is now.
        $account = $this->accounts->find($accountId);

        if (null === $account) {
            return;
        }

        $this->beat($account);
        $this->jobs->nudge($account->usr);

        if (false === $this->hasWork($account)) {
            $this->logger->info('Import: finished', ['accountId' => $accountId]);

            return;
        }

        // Gmail between listings has nothing to ask for until the last one's
        // batches have drained; the chain stops and ensureRunning() picks it
        // up again from a later sync.
        if (true === $account->isGmail() && true === $wait) {
            return;
        }

        $this->bus->dispatch(
            new ImportMailMessage($accountId),
            true === $wait ? [new DelayStamp(self::WAIT_MS)] : [],
        );
    }

    /** @return bool whether asking again at once would only repeat this */
    private function gmailPage(Account $account): bool
    {
        // The very first page of the very first listing: the Inbox by name,
        // ahead of the mailbox at large.
        if (null === $account->backfillRanAt) {
            $this->gmail->importInboxFirst($account);
        }

        return GmailBackfillStep::More !== $this->gmail->backfillPage($account);
    }

    /** @return bool whether asking again at once would only repeat this */
    private function imapPage(Account $account): bool
    {
        $accountId  = (int) $account->id;
        $mailboxIds = array_map(
            static fn ($mailbox): int => (int) $mailbox->id,
            $this->mailboxes->findImporting($account),
        );

        $client  = $this->connections->connect($account);
        $outcome = ImapImportOutcome::Waiting;

        try {
            // The first folder that can be read. One the server will not open
            // just now is passed over rather than waited on: it is first in
            // the list by rank, and waiting on it would hold every folder
            // behind it for as long as it stays shut.
            foreach ($mailboxIds as $mailboxId) {
                $outcome = $this->imapImporter->importPage($mailboxId, $client);

                if (ImapImportOutcome::Waiting !== $outcome) {
                    $this->published($accountId, $mailboxId);

                    break;
                }
            }
        } finally {
            $client->disconnect();
        }

        return $outcome->shouldWait();
    }

    /** Tell the open pages of this account's owner that a folder has more in it. */
    private function published(int $accountId, int $mailboxId): void
    {
        $account = $this->accounts->find($accountId);
        $mailbox = $this->mailboxes->find($mailboxId);

        if (null !== $account && null !== $mailbox) {
            $this->notifier->publishMailboxSynced($account, $mailbox);
        }
    }

    private function beat(Account $account): void
    {
        $account->importBeatAt = new DateTimeImmutable();

        $this->em->flush();
    }
}
