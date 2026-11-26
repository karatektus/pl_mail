<?php

declare(strict_types=1);

namespace App\Command\Backfill;

use App\Domain\Interface\UpgradeTaskInterface;
use App\Repository\Mail\Message\MessageBackfillRepository;
use App\Repository\Mail\MessageThread\MessageThreadRebuildRepository;
use App\Service\Mail\InlineAttachmentReclassifier;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Shows the attachments stored as inline parts that nothing displays (#753).
 *
 * Apple Mail sends every file it attaches as "Content-Disposition: inline"
 * with no Content-ID, and InlineAttachmentDetector used to trust that
 * disposition when the body did not reference the part. Such a part was stored
 * as inline, and an inline part the body does not embed is shown nowhere: no
 * chip under the message, no paperclip in the list, while Gmail listed it. The
 * detector now calls a part inline only when the HTML references its cid, so
 * no new ones arrive; this is for the ones already here.
 *
 * An upgrade task as well as a backfill, because a hidden attachment is
 * damage, not a choice: nobody should have to read a changelog to get their
 * files back. The bytes were stored all along, so only the rows change.
 *
 * Idempotent: a repaired part is no longer inline and leaves the walk's set,
 * and a part that is still inline after a run is one the body references.
 */
final readonly class InlineAttachmentBackfillTask implements CountableBackfillTaskInterface, UpgradeTaskInterface
{
    private const int BATCH_SIZE = 200;

    public function __construct(
        private MessageBackfillRepository      $messageBackfillRepository,
        private MessageThreadRebuildRepository $messageThreadRebuildRepository,
        private InlineAttachmentReclassifier   $reclassifier,
        private EntityManagerInterface         $em,
    ) {}

    public function getName(): string
    {
        return 'attachment-unreferenced-inline';
    }

    public function getDescription(): string
    {
        return 'Show attachments stored as inline parts that the message body never displays, such as files attached in Apple Mail.';
    }

    public function getAddedOn(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-25');
    }

    public function isSafeToRunAgain(): bool
    {
        return true;
    }

    /** A table repair walks everything, so it has no window to count within. */
    public function countCandidates(?DateTimeImmutable $since): int
    {
        return $this->messageBackfillRepository->countWithInlinePart();
    }

    public function run(SymfonyStyle $io): int
    {
        $total = $this->messageBackfillRepository->countWithInlinePart();

        if (0 === $total) {
            $io->success('Nothing to repair - no message has an inline part.');

            return Command::SUCCESS;
        }

        $io->progressStart($total);

        $lastId   = 0;
        $repaired = 0;

        while (true) {
            $messages = $this->messageBackfillRepository->findWithInlinePart($lastId, self::BATCH_SIZE);

            if ([] === $messages) {
                break;
            }

            foreach ($messages as $message) {
                $lastId    = (int) $message->id;
                $repaired += $this->reclassifier->reclassify($message);

                $io->progressAdvance();
            }

            $this->em->flush();
            $this->em->clear();
        }

        $io->progressFinish();

        // Thread counters are derived from has_attachments, so they are
        // rebuilt from the repaired messages rather than patched by deltas.
        if (0 < $repaired) {
            $this->messageThreadRebuildRepository->recomputeAttachmentCounts();
        }

        $io->success(sprintf('%d part(s) shown as attachments in %d message(s) read.', $repaired, $total));

        return Command::SUCCESS;
    }
}
