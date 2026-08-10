<?php

declare(strict_types=1);

namespace App\Command\Backfill;

use App\Repository\Mail\MessageRepository;
use App\Service\Mail\MailBodySanitizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Repairs Message::bodyHtmlSafe for mail rendered as mojibake because the
 * sender's HTML declared a charset of its own.
 *
 * The damage was done by the HTML parser, not by the sync: an ingested body is
 * UTF-8, but a `<meta charset=iso-8859-1>` inside it survived that conversion,
 * and the parser behind the sanitizer obeys the tag over any default — so
 * "über" was written to bodyHtmlSafe as "Ã¼ber". See
 * CharsetHelper::retagHtmlAsUtf8(), which is what stops it happening now.
 *
 * That makes this repairable without touching a mail server, which the
 * mislabelled-part bug before it was not. Nothing on the wire was ever wrong
 * and nothing stored was lost: bodyHtml holds the correct text still, so
 * sanitising it again is the whole fix. Re-syncing would not have helped —
 * IMAP skips UIDs it already has, and a message re-fetched anyway would have
 * arrived at the same sanitizer.
 *
 * Separate from the safe-html task rather than folded into it because the two
 * ask different questions. That one fills a copy that is missing, and can
 * afford to trust whatever is already there; this one distrusts copies that
 * exist. Keyset pagination by id, as there — with an unchanging cursor here,
 * since a repaired row still matches the predicate that selected it.
 */
final readonly class SafeHtmlCharsetBackfillTask implements BackfillTaskInterface
{
    private const int BATCH_SIZE = 100;

    public function __construct(
        private MessageRepository      $messageRepository,
        private MailBodySanitizer      $bodySanitizer,
        private EntityManagerInterface $em,
    ) {}

    public function getName(): string
    {
        return 'safe-html-charset';
    }

    public function getDescription(): string
    {
        return 'Re-sanitize Message.bodyHtmlSafe for HTML bodies that declare their own charset, repairing mojibake.';
    }

    public function run(SymfonyStyle $io): int
    {
        $total = $this->messageRepository->countWithHtmlCharsetDeclaration();

        if (0 === $total) {
            $io->success('Nothing to repair — no stored HTML body declares a charset.');

            return Command::SUCCESS;
        }

        $io->progressStart($total);

        $lastId    = 0;
        $processed = 0;
        $repaired  = 0;

        while (true) {
            $messages = $this->messageRepository->findWithHtmlCharsetDeclaration($lastId, self::BATCH_SIZE);

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
