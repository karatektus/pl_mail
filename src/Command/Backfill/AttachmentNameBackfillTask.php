<?php

declare(strict_types=1);

namespace App\Command\Backfill;

use App\Domain\Helper\AttachmentName;
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
use Webklex\PHPIMAP\Message as ImapMessage;

/**
 * Gives back their real names to attachments stored under webklex's hash.
 *
 * Before AttachmentName, a part that named itself only in `Content-Type:
 * name=` was stored as e.g. "57d5c7cb": no extension to open it by, and no
 * PDF preview for one sent as application/octet-stream. See AttachmentName.
 *
 * The real name is in the message's own stored original, so the repair reads
 * that. Each stored part is matched to its parsed attachment by the hash it
 * was named after — webklex computes it from the part's raw header and
 * content, so the same original gives the same hash — and only a part whose
 * attachment now has a real name changes. A part that never had a name keeps
 * its hash (MisfiledBodyDetector relies on it). The file on disk is found by
 * storagePath, not by name, so only the row changes.
 *
 * Idempotent: a renamed part no longer looks like a hash, and a part that
 * still does has nothing better to be called. That is what lets it be an
 * upgrade task as well as a backfill.
 */
final readonly class AttachmentNameBackfillTask implements BackfillTaskInterface, UpgradeTaskInterface
{
    private const int BATCH_SIZE = 100;

    public function __construct(
        private MessageBackfillRepository $messageBackfillRepository,
        private RawMessageStorage $rawStorage,
        private MisfiledBodyDetector $detector,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {}

    public function getName(): string
    {
        return 'attachment-content-type-names';
    }

    public function getDescription(): string
    {
        return 'Restore the names of attachments that gave their name only in Content-Type and were stored under a hash.';
    }

    public function run(SymfonyStyle $io): int
    {
        $total = $this->messageBackfillRepository->countWithHashNamedPart();

        if (0 === $total) {
            $io->success('Nothing to repair - no attachment is stored under a hash name.');

            return Command::SUCCESS;
        }

        $io->progressStart($total);

        $lastId  = 0;
        $renamed = 0;

        while (true) {
            $messages = $this->messageBackfillRepository->findWithHashNamedPart($lastId, self::BATCH_SIZE);

            if (count($messages) === 0) {
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
        $byHash = [];

        foreach ($message->messageParts as $part) {
            if (false === $part->sealed && true === $this->detector->looksLikeStoredHash($part->filename)) {
                $byHash[(string) $part->filename][] = $part;
            }
        }

        if ([] === $byHash) {
            return 0;
        }

        try {
            $path = (string) $message->rawPath;

            if (false === $this->rawStorage->exists($path)) {
                return 0;
            }

            $raw    = (string) file_get_contents($this->rawStorage->getAbsolutePath($path));
            $parsed = ImapMessage::fromString($raw, MessageParser::config());

            $names = [];

            foreach ($parsed->getAttachments() as $attachment) {
                $hash = (string) ($attachment->getHash() ?? '');
                $name = AttachmentName::of($attachment);

                if ('' !== $hash && null !== $name && $name !== $hash) {
                    $names[$hash] = $name;
                }
            }
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

        foreach ($byHash as $hash => $parts) {
            if (false === isset($names[$hash])) {
                continue;
            }

            foreach ($parts as $part) {
                $part->filename = $names[$hash];
                ++$renamed;
            }
        }

        return $renamed;
    }
}
