<?php

declare(strict_types=1);

namespace App\Command\Backfill;

use App\Repository\Mail\Message\MessageBackfillRepository;
use App\Service\Mail\MailBodySanitizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Repairs Message::bodyHtmlSafe for mail that kept `position: absolute`.
 *
 * The reading pane used to keep absolute positioning, so a mail laid out like
 * a print page — a Scriptura notice, whose header banner sits in an absolutely
 * positioned box — showed its text running underneath the banner. The
 * sanitizer drops the declaration now (#626); this is for the mail that
 * arrived before.
 *
 * Repairable locally, as the other safe-html damage was: bodyHtml is the
 * sender's own copy and was never touched, and bodyHtmlSafe is derived from
 * it. The safe-html task cannot do it, since it only fills copies that are
 * missing, and these exist.
 *
 * A task of its own, as safe-html-table-links is, because each walks a set
 * chosen by its own cause and reports what it repaired. Keyset pagination by
 * id with an unchanging cursor: a repaired row can still match the predicate
 * (the word is in its text), and would otherwise be walked forever.
 */
final readonly class SafeHtmlAbsolutePositionBackfillTask implements BackfillTaskInterface
{
    private const int BATCH_SIZE = 100;

    public function __construct(
        private MessageBackfillRepository $messageBackfillRepository,
        private MailBodySanitizer      $bodySanitizer,
        private EntityManagerInterface $em
    ) {}

    public function getName(): string
    {
        return 'safe-html-absolute-position';
    }

    public function getDescription(): string
    {
        return 'Re-sanitize Message.bodyHtmlSafe for HTML bodies that kept position: absolute, so a banner no longer covers the text below it.';
    }

    public function run(SymfonyStyle $io): int
    {
        $total = $this->messageBackfillRepository->countWithAbsolutePosition();

        if (0 === $total) {
            $io->success('Nothing to repair — no stored HTML body is positioned absolutely.');

            return Command::SUCCESS;
        }

        $io->progressStart($total);

        $lastId    = 0;
        $processed = 0;
        $repaired  = 0;

        while (true) {
            $messages = $this->messageBackfillRepository->findWithAbsolutePosition($lastId, self::BATCH_SIZE);

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
