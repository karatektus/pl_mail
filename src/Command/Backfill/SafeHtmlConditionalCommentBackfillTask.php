<?php

declare(strict_types=1);

namespace App\Command\Backfill;

use App\Repository\Mail\MessageRepository;
use App\Service\Mail\MailBodySanitizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Repairs Message::bodyHtmlSafe for mail that shows `<![endif]-->` as text.
 *
 * A comment XML could not hold sent the whole body to the inliner's HTML 4
 * parser, which reads the closing marker of Outlook's downlevel-revealed
 * conditional comment as text; the sanitizer then kept it, escaped, where the
 * reader saw it. Html5CssInliner is what stops that happening now (#175); this
 * is for the mail that arrived before it.
 *
 * Repairable locally for the same reason the charset and table-link damage
 * were: bodyHtml is the sender's own copy and was never touched, and
 * bodyHtmlSafe is derived from it. The safe-html task cannot do it, since it
 * only fills copies that are missing, and these exist.
 *
 * A task of its own, as safe-html-table-links is, because each walks a set
 * chosen by its own cause and reports what it repaired. Keyset pagination by
 * id with an unchanging cursor: a row the sanitizer still cannot clean would
 * otherwise match again and be walked forever.
 */
final readonly class SafeHtmlConditionalCommentBackfillTask implements BackfillTaskInterface
{
    private const int BATCH_SIZE = 100;

    public function __construct(
        private MessageRepository      $messageRepository,
        private MailBodySanitizer      $bodySanitizer,
        private EntityManagerInterface $em,
    ) {}

    public function getName(): string
    {
        return 'safe-html-conditional-comments';
    }

    public function getDescription(): string
    {
        return 'Re-sanitize Message.bodyHtmlSafe for HTML bodies that show an Outlook conditional comment (<![endif]-->) as text.';
    }

    public function run(SymfonyStyle $io): int
    {
        $total = $this->messageRepository->countWithConditionalCommentText();

        if (0 === $total) {
            $io->success('Nothing to repair — no stored HTML body shows a conditional comment as text.');

            return Command::SUCCESS;
        }

        $io->progressStart($total);

        $lastId    = 0;
        $processed = 0;
        $repaired  = 0;

        while (true) {
            $messages = $this->messageRepository->findWithConditionalCommentText($lastId, self::BATCH_SIZE);

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
