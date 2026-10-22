<?php

declare(strict_types=1);

namespace App\Command\Backfill;

use App\Repository\Mail\MessageRepository;
use App\Service\Mail\MailBodySanitizer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Repairs Message::bodyHtmlSafe for mail whose cards were lost or hidden.
 *
 * Two faults, one picture: a review request that showed no product at all
 * (#271). The sanitizer dropped a `<form>` with everything inside it, and the
 * inliner let an inline `display:none` beat the stylesheet's
 * `display:block !important` that was meant to switch the card on. The form is
 * unwrapped now and the inliner follows the cascade; this is for the mail
 * that arrived before.
 *
 * One task for both, unlike the other safe-html repairs, because neither fix
 * brings this mail back without the other — the unwrapped card is still the
 * one the stylesheet hides — and a task per fault would leave the reader with
 * half a repair after running one of them.
 *
 * Repairable locally, as the others are: bodyHtml is the sender's own copy and
 * was never touched. Keyset pagination by id with an unchanging cursor, since
 * a repaired row still matches the predicate.
 */
final readonly class SafeHtmlHiddenCardBackfillTask implements BackfillTaskInterface
{
    private const int BATCH_SIZE = 100;

    public function __construct(
        private MessageRepository      $messageRepository,
        private MailBodySanitizer      $bodySanitizer,
        private EntityManagerInterface $em,
    ) {}

    public function getName(): string
    {
        return 'safe-html-hidden-cards';
    }

    public function getDescription(): string
    {
        return 'Re-sanitize Message.bodyHtmlSafe for HTML bodies with a form or a stylesheet !important, restoring content that was dropped or hidden.';
    }

    public function run(SymfonyStyle $io): int
    {
        $total = $this->messageRepository->countWithFormOrImportantRule();

        if (0 === $total) {
            $io->success('Nothing to repair — no stored HTML body has a form or a stylesheet !important.');

            return Command::SUCCESS;
        }

        $io->progressStart($total);

        $lastId    = 0;
        $processed = 0;
        $repaired  = 0;

        while (true) {
            $messages = $this->messageRepository->findWithFormOrImportantRule($lastId, self::BATCH_SIZE);

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
