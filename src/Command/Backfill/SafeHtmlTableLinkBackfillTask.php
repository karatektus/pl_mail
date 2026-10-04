<?php

declare(strict_types=1);

namespace App\Command\Backfill;

use App\Repository\Mail\MessageRepository;
use App\Service\Mail\MailBodySanitizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Repairs Message::bodyHtmlSafe for mail whose links stopped being links
 * because they were wrapped around a table.
 *
 * The inliner's HTML 4 parser ended an `<a>` where a table began inside it, so
 * the card a newsletter or a job alert meant to be clickable was stored beside
 * an empty link instead of inside it. Html5CssInliner is what stops that
 * happening now; this is for the mail that arrived before it.
 *
 * Repairable locally for the same reason the charset damage was: bodyHtml is
 * the sender's own copy and was never touched, and bodyHtmlSafe is derived from
 * it. Sanitising again is the whole fix, and no mail server is asked for
 * anything.
 *
 * A task of its own rather than a wider safe-html-charset, because each walks a
 * set chosen by its own cause and reports what it repaired; one task named for
 * two unrelated faults would say neither. Keyset pagination by id with an
 * unchanging cursor, as there: a repaired row still matches the predicate.
 */
final readonly class SafeHtmlTableLinkBackfillTask implements BackfillTaskInterface
{
    private const int BATCH_SIZE = 100;

    public function __construct(
        private MessageRepository      $messageRepository,
        private MailBodySanitizer      $bodySanitizer,
        private EntityManagerInterface $em,
    ) {}

    public function getName(): string
    {
        return 'safe-html-table-links';
    }

    public function getDescription(): string
    {
        return 'Re-sanitize Message.bodyHtmlSafe for HTML bodies with a table inside a link, restoring links that came out empty.';
    }

    public function run(SymfonyStyle $io): int
    {
        $total = $this->messageRepository->countWithTableAfterLink();

        if (0 === $total) {
            $io->success('Nothing to repair — no stored HTML body has a table after a link.');

            return Command::SUCCESS;
        }

        $io->progressStart($total);

        $lastId    = 0;
        $processed = 0;
        $repaired  = 0;

        while (true) {
            $messages = $this->messageRepository->findWithTableAfterLink($lastId, self::BATCH_SIZE);

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
