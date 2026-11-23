<?php

declare(strict_types=1);

namespace App\Command\Backfill;

use App\Repository\Mail\Message\MessageBackfillRepository;
use App\Service\Mail\MailBodySanitizer;
use DateTimeImmutable;
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
 * fix. Which mail needs it SQL cannot say, since it does not know the tags the
 * allow-list names, so the walk reads every HTML body as a plain string and
 * asks MailBodySanitizer::containsElementOutside(). Only the mail it says yes
 * to is loaded and sanitised again: the check is a regular expression, the
 * sanitise a CSS inliner and two HTML parses, and on most mailboxes few bodies
 * hold an unknown element.
 */
final readonly class SafeHtmlUnknownElementBackfillTask implements CountableBackfillTaskInterface
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
        return 'Re-sanitize Message.bodyHtmlSafe for HTML bodies with an element the allow-list does not name, restoring the content that sat inside it.';
    }

    public function getAddedOn(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-23');
    }

    /**
     * Safe to repeat: the display copy is rebuilt from bodyHtml, the sender's
     * untouched original, so a second run arrives at the same copy.
     */
    public function isSafeToRunAgain(): bool
    {
        return true;
    }

    /** A table repair walks everything, so it has no window to count within. */
    public function countCandidates(?DateTimeImmutable $since): int
    {
        return $this->messageBackfillRepository->countWithHtmlBody();
    }

    public function run(SymfonyStyle $io): int
    {
        $total = $this->messageBackfillRepository->countWithHtmlBody();

        if (0 === $total) {
            $io->success('Nothing to repair — no message has an HTML body.');

            return Command::SUCCESS;
        }

        $known = $this->bodySanitizer->knownElements();

        $io->progressStart($total);

        $lastId   = 0;
        $checked  = 0;
        $affected = 0;
        $repaired = 0;

        while (true) {
            $bodies = $this->messageBackfillRepository->findHtmlBodiesAfter($lastId, self::BATCH_SIZE);

            if ([] === $bodies) {
                break;
            }

            $lastId   = (int) array_key_last($bodies);
            $checked += count($bodies);
            $io->progressAdvance(count($bodies));
            $ids      = [];

            foreach ($bodies as $id => $html) {
                if (MailBodySanitizer::containsElementOutside($html, $known)) {
                    $ids[] = $id;
                }
            }

            unset($bodies);

            foreach ($this->messageBackfillRepository->findByIds($ids) as $message) {
                $before = $message->bodyHtmlSafe;

                $this->bodySanitizer->sanitize($message);

                if ($before !== $message->bodyHtmlSafe) {
                    ++$repaired;
                }
            }

            $affected += count($ids);

            $this->em->flush();
            $this->em->clear();
        }

        $io->progressFinish();
        $io->success(sprintf(
            'Checked %d message(s): %d had an element the allow-list does not name, %d of them changed.',
            $checked,
            $affected,
            $repaired,
        ));

        return Command::SUCCESS;
    }
}
