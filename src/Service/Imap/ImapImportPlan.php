<?php

declare(strict_types=1);

namespace App\Service\Imap;

use App\Entity\Mail\Mailbox;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;
use Webklex\PHPIMAP\Client;

/**
 * The moment a folder is first met: where new mail begins, and where its
 * history is to be read from.
 *
 * A folder used to have one marker, lastSeenUid, and a first sync was that
 * marker walking up from nothing — the whole folder, oldest first, in one job.
 * Here the two jobs that was doing are pulled apart (see
 * Mailbox::$importFloorUid):
 *
 *   - lastSeenUid goes straight to the TOP of the folder. From this instant a
 *     poll or an IDLE notice brings in new mail and only new mail, whatever
 *     state the history is in.
 *   - importFloorUid starts one above it, so the history below is everything
 *     the folder holds, and ImapMailboxImporter brings it in from the top
 *     down, a page at a time, on its own queue.
 *
 * Deliberately a class of its own and not a method of the importer: it is
 * called from MessageSyncer, which the importer in turn depends on, and a
 * constructor cannot be owed to itself.
 *
 * "The top" is UIDNEXT minus one, read off an EXAMINE. A server that does not
 * say what its next UID will be has not given enough to plan with, and the
 * folder is left unplanned: the sync then reads it the old way, bottom up,
 * which is slow and correct.
 */
final readonly class ImapImportPlan
{
    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface        $logger,
    ) {}

    /** @return bool whether the folder is planned now */
    public function begin(Mailbox $mailbox, Client $client): bool
    {
        $top = $this->highestUid($mailbox, $client);

        if (null === $top) {
            return false;
        }

        // Never down. A folder half-read by the old oldest-first pass has a
        // mark in its middle; everything between there and the top is simply
        // history now, and the importer skips what is already stored.
        $mailbox->lastSeenUid = max($mailbox->lastSeenUid ?? 0, $top);

        // An empty folder has no history, and saying so here is what keeps it
        // off the importer's list.
        $mailbox->importFloorUid     = 0 < $top ? $top + 1 : 0;
        $mailbox->importTotal        = null;
        $mailbox->importRemaining    = null;
        $mailbox->importPageAttempts = 0;

        $this->em->flush();

        $this->logger->info('Planned a folder\'s import: new mail from the top, history below it', [
            'mailboxId' => $mailbox->id,
            'mailbox'   => $mailbox->fullPath,
            'top'       => $top,
        ]);

        return true;
    }

    private function highestUid(Mailbox $mailbox, Client $client): ?int
    {
        $path = (string) ($mailbox->fullPath ?? $mailbox->name);

        try {
            $examined = $client->getConnection()->examineFolder($path)->validatedData();
        } catch (Throwable $e) {
            $this->logger->info('Import not planned: the folder could not be examined', [
                'mailbox' => $path,
                'error'   => $e->getMessage(),
            ]);

            return null;
        }

        if (false === is_array($examined) || false === isset($examined['uidnext'])) {
            $this->logger->info('Import not planned: no UIDNEXT in the EXAMINE response', [
                'mailbox' => $path,
            ]);

            return null;
        }

        return max(0, (int) $examined['uidnext'] - 1);
    }
}
