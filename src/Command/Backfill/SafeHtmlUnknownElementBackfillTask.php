<?php

declare(strict_types=1);

namespace App\Command\Backfill;

use App\Repository\Mail\Message\MessageBackfillRepository;
use App\Service\Mail\MailBodySanitizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Repairs Message::bodyHtmlSafe for mail whose letter sat inside an element
 * the allow-list does not name.
 *
 * The sanitizer dropped such an element with everything in it, and Nayax wraps
 * every mail it sends in `<message>`: invitations, refund requests and refund
 * confirmations were stored with a blank body. MailBodySanitizer unwraps them
 * now; this is for the mail that arrived before it.
 *
 * Sanitising again from bodyHtml, the sender's untouched copy, is the whole
 * fix. The walk is every HTML body, because SQL cannot tell which mail held an
 * unknown element; it reports how many came out different.
 */
final readonly class SafeHtmlUnknownElementBackfillTask implements BackfillTaskInterface
{
    private const int BATCH_SIZE = 100;

    public function __construct(
        private MessageBackfillRepository $messageBackfillRepository,
        private MailBodySanitizer      $bodySanitizer,
        private EntityManagerInterface $em
    ) {}

    public function getName(): string
    {
        return 'safe-html-unknown-elements';
    }

    public function getDescription(): string
    {
        return 'Re-sanitize Message.bodyHtmlSafe for every HTML body, restoring content that sat inside an element the allow-list does not name.';
    }

    public function run(SymfonyStyle $io): int
    {
        $total = $this->messageBackfillRepository->countWithHtmlBody();

        if (0 === $total) {
            $io->success('Nothing to repair — no message has an HTML body.');

            return Command::SUCCESS;
        }

        $io->progressStart($total);

        $lastId    = 0;
        $processed = 0;
        $repaired  = 0;

        while (true) {
            $messages = $this->messageBackfillRepository->findWithHtmlBody($lastId, self::BATCH_SIZE);

            if (count($messages) === 0) {
                break;
            }

            foreach ($messages as $message) {
                $lastId = (int) $message->id;
                $before = $message->bodyHtmlSafe;

                $this->bodySanitizer->sanitize($message);

                if ($before !== $message->bodyHtmlSafe) {
                    ++$repaired;
                }

                ++$processed;
                $io->progressAdvance();
            }

            $this->em->flush();
            $this->em->clear();
        }

        $io->progressFinish();
        $io->success(sprintf('Re-sanitized %d message(s), %d of them changed.', $processed, $repaired));

        return Command::SUCCESS;
    }
}
