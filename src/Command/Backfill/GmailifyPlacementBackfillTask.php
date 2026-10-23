<?php

declare(strict_types=1);

namespace App\Command\Backfill;

use App\Repository\Mail\MessageRepository;
use App\Service\Gmail\GmailifyLinks;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Puts a Gmailify sibling's IMAP mail back where its folder says it is (#203).
 *
 * Until #203 the Gmail import merged its copy's placement onto the sibling's
 * own IMAP copy. Mail Gmail had trashed showed in plMail's Trash and out of
 * the Inbox while the IMAP server still had it in its Inbox; mail Gmail had
 * archived went to Archive. The merge stopped doing that, and nothing it
 * merges later changes placement, so the rows it already did it to stay that
 * way until this runs. GmailifyLinks::placeByFolder() decides what the
 * placement is.
 *
 * ── Which rows ───────────────────────────────────────────────────────────────
 * Only the IMAP copies a Gmail mailbox carries, which have a folder to read
 * the answer from. A row Gmail built for a sibling has no folder; its
 * placement is Gmail's and was right all along.
 *
 * Mail already erased because Gmail emptied its copy out of the Trash is not
 * brought back: the IMAP sync asks only for UIDs above the highest it has.
 *
 * ── A folder move that has not reached the server yet ────────────────────────
 * A move made in plMail changes the labels first and the folder when the IMAP
 * server confirms it. A row caught in between is put back in its old folder's
 * place, and the confirmed move puts it right again a moment later; run this
 * while the workers are quiet to avoid the flicker.
 *
 * Idempotent: a row whose labels agree with its folder is left alone.
 */
final readonly class GmailifyPlacementBackfillTask implements BackfillTaskInterface
{
    private const int BATCH_SIZE = 200;

    public function __construct(
        private MessageRepository      $messages,
        private GmailifyLinks          $links,
        private EntityManagerInterface $em,
    ) {}

    public function getName(): string
    {
        return 'gmailify-placement';
    }

    public function getDescription(): string
    {
        return 'Put IMAP mail a Gmail account also collects back in the Inbox, Trash or folder its IMAP folder says, where Gmail\'s copy had moved it.';
    }

    public function run(SymfonyStyle $io): int
    {
        $total = $this->messages->countImapCopiesCarried(null);

        if (0 === $total) {
            $io->success('Nothing to repair — no Gmail account collects mail another account syncs over IMAP.');

            return Command::SUCCESS;
        }

        $io->progressStart($total);

        $lastId   = 0;
        $repaired = 0;

        while (true) {
            $rows = $this->messages->findImapCopiesCarried(null, $lastId, self::BATCH_SIZE);

            if (0 === count($rows)) {
                break;
            }

            foreach ($rows as $row) {
                $lastId = (int) $row->id;

                if (true === $this->links->placeByFolder($row)) {
                    ++$repaired;
                }

                $io->progressAdvance();
            }

            $this->em->flush();
            $this->em->clear();
        }

        $io->progressFinish();
        $io->success(sprintf('%d message(s) put back, %d read.', $repaired, $total));

        return Command::SUCCESS;
    }
}
