<?php

declare(strict_types=1);

namespace App\Command\Backfill;

use App\Domain\Helper\AttachmentStorageHelper;
use App\Domain\Helper\MessageParser;
use App\Domain\Helper\RawMessageStorage;
use App\Domain\Interface\UpgradeTaskInterface;
use App\Entity\Mail\Message;
use App\Repository\Mail\Message\MessageBackfillRepository;
use App\Service\Mail\MisfiledBodyDetector;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Gives back their real names to attachments stored under webklex's checksum
 * (#718).
 *
 * A part that named itself only in `Content-Type: name=`, with a bare
 * `Content-Disposition: attachment`, was stored by webklex as e.g. "57d5c7cb":
 * no extension to open it by, and no PDF preview for one sent as
 * application/octet-stream. MessageParser reads that name, so no new ones
 * arrive; this is for the ones already here.
 *
 * ── Matched by the bytes, not by the checksum ────────────────────────────────
 * The real name is in the message's own stored original, so the repair reads
 * that. Each stored part is matched to the original's attachment whose content
 * is byte for byte the file on disk. The checksum the part was named after was
 * webklex's own and went with it; the bytes are what both parsers agree on,
 * since undoing a transfer encoding has one answer. A part whose bytes match
 * no named attachment, or attachments under more than one name, is left alone:
 * guessing would put the wrong name on a file, which is worse than a checksum.
 *
 * A part that never had a name keeps its checksum, which is what
 * MisfiledBodyDetector recognises a misfiled body by. The file on disk is
 * found by storagePath, not by name, so only the row changes.
 *
 * Idempotent: a renamed part no longer looks like a checksum, and a part that
 * still does has nothing better to be called. That is what lets it be an
 * upgrade task as well as a backfill.
 *
 * Proposed in #721 by James Morrison, who found the bug and wrote the first
 * version against webklex's checksum.
 */
final readonly class AttachmentNameBackfillTask implements BackfillTaskInterface, UpgradeTaskInterface
{
    private const int BATCH_SIZE = 100;

    public function __construct(
        private MessageBackfillRepository $messageBackfillRepository,
        private RawMessageStorage         $rawStorage,
        private AttachmentStorageHelper   $attachmentStorage,
        private MisfiledBodyDetector      $detector,
        private EntityManagerInterface    $em,
        private LoggerInterface           $logger,
    ) {}

    public function getName(): string
    {
        return 'attachment-content-type-names';
    }

    public function getDescription(): string
    {
        return 'Restore the names of attachments that gave their name only in Content-Type and were stored under a checksum.';
    }

    public function run(SymfonyStyle $io): int
    {
        $total = $this->messageBackfillRepository->countWithChecksumNamedPart();

        if (0 === $total) {
            $io->success('Nothing to repair - no attachment is stored under a checksum name.');

            return Command::SUCCESS;
        }

        $io->progressStart($total);

        $lastId  = 0;
        $renamed = 0;

        while (true) {
            $messages = $this->messageBackfillRepository->findWithChecksumNamedPart($lastId, self::BATCH_SIZE);

            if ([] === $messages) {
                break;
            }

            foreach ($messages as $message) {
                $lastId   = (int) $message->id;
                $renamed += $this->repair($message);

                $io->progressAdvance();
            }

            $this->em->flush();
            $this->em->clear();
        }

        $io->progressFinish();
        $io->success(sprintf('%d attachment(s) renamed in %d message(s) read.', $renamed, $total));

        return Command::SUCCESS;
    }

    /**
     * @return int how many parts were renamed
     */
    private function repair(Message $message): int
    {
        $parts = [];

        foreach ($message->messageParts as $part) {
            if (false === $part->sealed && true === $this->detector->looksLikeStoredHash($part->filename)) {
                $parts[] = $part;
            }
        }

        if ([] === $parts) {
            return 0;
        }

        try {
            $path = (string) $message->rawPath;

            if (false === $this->rawStorage->exists($path)) {
                return 0;
            }

            $attachments = MessageParser::parse((string) file_get_contents($this->rawStorage->getAbsolutePath($path)))->attachments;
        } catch (Throwable $e) {
            // One unreadable original is one message left as it was; stopping
            // here would leave every later one unrepaired too.
            $this->logger->warning('Could not read a stored message for the attachment-name repair', [
                'messageId' => $message->id,
                'error'     => $e->getMessage(),
            ]);

            return 0;
        }

        $renamed = 0;

        foreach ($parts as $part) {
            $file = $this->attachmentStorage->getAbsolutePath((string) $part->storagePath);

            if (false === is_file($file)) {
                continue;
            }

            $bytes = (string) file_get_contents($file);
            $names = [];

            foreach ($attachments as $attachment) {
                if (null !== $attachment->filename && $attachment->content === $bytes) {
                    $names[$attachment->filename] = true;
                }
            }

            if (1 !== count($names)) {
                continue;
            }

            $part->filename = (string) array_key_first($names);
            ++$renamed;
        }

        return $renamed;
    }
}
