<?php

declare(strict_types=1);

namespace App\Service\Imap;

use App\Entity\Mail\Mailbox;
use App\Domain\Helper\ImapFolderLocator;
use App\Repository\Mail\MailboxRepository;
use App\Repository\Mail\MessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Webklex\PHPIMAP\Client;

/**
 * One page of a folder's history: the newest messages it holds that are not
 * here yet.
 *
 * A first import used to be the sync itself, reading a folder from UID 1 to
 * its end in one job — oldest mail first, today's last, and every other folder
 * and account waiting behind it (#42). This is what replaced that. The sync
 * puts its mark at the top of the folder and stops (ImapImportPlan); what is
 * below is brought in here, from the top down, PAGE_SIZE messages at a call,
 * and MailImporter decides whose page is next.
 *
 * ## What a page is
 *
 * Not a range. UIDs have holes, and a range of fifty UIDs is anything from
 * fifty messages to none. The server is asked which UIDs exist below the
 * floor — one SEARCH, a few bytes a message — the ones already stored are
 * taken out, and the highest PAGE_SIZE of the rest are fetched by name.
 * "Already stored" matters more than it looks: Gmail over IMAP shows every
 * message in All Mail as well as its folder, a folder half-read by the old
 * oldest-first pass is planned again from the top, and in both cases the
 * messages that are here are never downloaded a second time to find that out.
 *
 * ## When a message will not store
 *
 * The floor does not move past a page that held something back, so the next
 * page is the same messages again — minus the ones that did store, which are
 * no longer missing. After MAX_PAGE_ATTEMPTS of that the floor moves anyway
 * and the page's leftovers are logged and given up: the alternative is a
 * folder whose history stops for good at one message the parser dislikes.
 *
 * Counted by the page and not by the message — see
 * Mailbox::$importPageAttempts for why the ledger new mail uses cannot be
 * shared.
 */
final readonly class ImapMailboxImporter
{
    /** Messages to a page. MessageSyncer writes a page in one transaction. */
    public const int PAGE_SIZE = 50;

    /** Tries at a page that keeps holding something back before it is passed. */
    public const int MAX_PAGE_ATTEMPTS = 5;

    public function __construct(
        private MessageSyncer          $messageSyncer,
        private ImapImportPlan         $plan,
        private ImapPagedFetch         $pagedFetch,
        private MailboxRepository      $mailboxes,
        private MessageRepository      $messages,
        private EntityManagerInterface $em,
        private LoggerInterface        $logger,
    ) {}

    /**
     * Bring in the next page of this folder.
     *
     * @return ImapImportOutcome what became of it; the caller schedules what
     *                           follows
     */
    public function importPage(int $mailboxId, Client $client): ImapImportOutcome
    {
        $mailbox = $this->mailboxes->find($mailboxId);

        if (null === $mailbox || false === $mailbox->isImporting()) {
            return ImapImportOutcome::Finished;
        }

        // Met here before any sync got to it — a folder created a moment ago.
        if (null === $mailbox->importFloorUid && false === $this->plan->begin($mailbox, $client)) {
            return ImapImportOutcome::Waiting;
        }

        $floor = (int) $mailbox->importFloorUid;

        if (0 === $floor) {
            return ImapImportOutcome::Finished;
        }

        $folder = ImapFolderLocator::of($client, $mailbox);

        if (null === $folder) {
            $this->logger->warning('Import: the folder is not on the server; nothing imported', [
                'mailboxId' => $mailboxId,
                'mailbox'   => $mailbox->fullPath,
            ]);

            return ImapImportOutcome::Waiting;
        }

        $missing = $this->missingBelow($mailbox, $folder, $floor);

        // Written at once: storing a page clears the entity manager, and a
        // total set on this object and flushed "later" would be set on nothing.
        if (null === $mailbox->importTotal) {
            $mailbox->importTotal     = count($missing);
            $mailbox->importRemaining = count($missing);

            $this->em->flush();
        }

        if ([] === $missing) {
            $this->finish($mailbox);

            return ImapImportOutcome::Finished;
        }

        $page = array_slice($missing, 0, self::PAGE_SIZE);
        $path = (string) $mailbox->fullPath;

        $query      = $folder->messages()->where(MessageSyncer::uidSetCriteria($page));
        $lowestHeld = null;

        foreach ($this->pagedFetch->pages($query, self::PAGE_SIZE) as [$batch, $unreadable]) {
            // Found again for each: storing a page clears the entity manager.
            $current = $this->mailboxes->find($mailboxId);

            if (null === $current) {
                return ImapImportOutcome::Finished;
            }

            $held = $this->messageSyncer->storeImportPage($current, $batch, $unreadable);

            if (null !== $held) {
                $lowestHeld = null === $lowestHeld ? $held : min($lowestHeld, $held);
            }
        }

        $mailbox = $this->mailboxes->find($mailboxId);

        if (null === $mailbox) {
            return ImapImportOutcome::Finished;
        }

        $outcome = $this->settle($mailbox, $page, count($missing), $lowestHeld, $path);

        $mailbox->unreadMessages = $this->messages->countUnseenForMailbox($mailbox);
        $mailbox->totalMessages  = $this->messages->countTotalForMailbox($mailbox);

        $this->em->flush();

        return $outcome;
    }

    /**
     * The UIDs the server holds below the floor that are not stored here,
     * highest first.
     *
     * @return list<int>
     */
    private function missingBelow(Mailbox $mailbox, \Webklex\PHPIMAP\Folder $folder, int $floor): array
    {
        $onServer = $folder->messages()
            ->where(MessageSyncer::uidRangeCriteria(sprintf('1:%d', $floor - 1)))
            ->search()
            ->all();

        // `1:n` on a folder whose highest UID is below n answers with that
        // highest one, the way `*` does. Anything at or above the floor has
        // been dealt with and is not history.
        $onServer = array_filter(array_map('intval', $onServer), static fn (int $uid): bool => $uid < $floor);

        $stored  = array_flip(array_map('intval', $this->messages->findSyncedUids($mailbox, 0)));
        $missing = array_values(array_filter($onServer, static fn (int $uid): bool => false === isset($stored[$uid])));

        rsort($missing);

        return $missing;
    }

    /**
     * Move the floor, or count another try at a page that held something back.
     *
     * @param list<int> $page
     */
    private function settle(Mailbox $mailbox, array $page, int $missing, ?int $lowestHeld, string $path): ImapImportOutcome
    {
        if (null !== $lowestHeld && ++$mailbox->importPageAttempts < self::MAX_PAGE_ATTEMPTS) {
            // The floor stays. What did store is no longer missing, so the
            // next page is the leftovers and whatever is next below them.
            return ImapImportOutcome::Retry;
        }

        if (null !== $lowestHeld) {
            $this->logger->error('Import: messages that would not store are being passed over for good', [
                'mailboxId' => $mailbox->id,
                'mailbox'   => $path,
                'from'      => min($page),
                'to'        => max($page),
                'attempts'  => $mailbox->importPageAttempts,
            ]);
        }

        $mailbox->importPageAttempts = 0;
        $mailbox->importFloorUid     = min($page);
        $mailbox->importRemaining    = max(0, $missing - count($page));

        if (0 === $mailbox->importRemaining) {
            $this->finish($mailbox);

            return ImapImportOutcome::Finished;
        }

        return ImapImportOutcome::More;
    }

    private function finish(Mailbox $mailbox): void
    {
        $mailbox->importFloorUid     = 0;
        $mailbox->importRemaining    = 0;
        $mailbox->importPageAttempts = 0;

        $this->em->flush();

        $this->logger->info('Import: folder finished', [
            'mailboxId' => $mailbox->id,
            'mailbox'   => $mailbox->fullPath,
            'messages'  => $mailbox->importTotal,
        ]);
    }
}
