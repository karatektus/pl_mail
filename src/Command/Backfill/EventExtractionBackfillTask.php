<?php

declare(strict_types=1);

namespace App\Command\Backfill;

use App\Entity\Mail\Message;
use App\Repository\Mail\MessageRepository;
use App\Service\Calendar\EventReconciler;
use App\Service\Calendar\Extraction\EventExtractionRunner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Re-runs extraction over mail that is already stored.
 *
 * This is the payoff of the whole design, and the reason every extractor reads
 * persisted data only: improving a mapper, adding a sender parser, or fixing a
 * timezone bug means running this, not resyncing anyone's mailbox. The one
 * exception is a Gmail invite that arrived as a lazy stub, which costs a
 * single fetch the first time its bytes are needed and is then on disk.
 *
 * Idempotent. Extraction produces the same claims from the same rows, and
 * EventReconciler is what makes replaying them safe: an unchanged claim
 * matches an existing event by uid, a suppressed one is skipped, and a
 * user-edited event is left alone.
 *
 * Only messages that could plausibly carry an event are walked — a mailbox is
 * mostly newsletters, and parsing all of it to find the few per cent that are
 * bookings is work nobody gets back.
 */
final readonly class EventExtractionBackfillTask implements BackfillTaskInterface
{
    private const int BATCH_SIZE = 200;

    public function __construct(
        private MessageRepository      $messages,
        private EventExtractionRunner  $runner,
        private EventReconciler        $reconciler,
        private EntityManagerInterface $em,
    ) {
    }

    public function getName(): string
    {
        return 'events';
    }

    public function getDescription(): string
    {
        return 'Re-run calendar event extraction over stored mail.';
    }

    public function run(SymfonyStyle $io): int
    {
        $total = $this->messages->countExtractionCandidates();

        if (0 === $total) {
            $io->success('No messages carry anything to extract.');

            return Command::SUCCESS;
        }

        $io->progressStart($total);

        // Keyset by id so each batch can flush and clear without holding a
        // server-side cursor, matching CategoryBackfillTask.
        $lastId    = 0;
        $processed = 0;
        $events    = 0;

        while (true) {
            /** @var list<Message> $batch */
            $batch = $this->messages->extractionCandidates($lastId, self::BATCH_SIZE);

            if (0 === count($batch)) {
                break;
            }

            foreach ($batch as $message) {
                $lastId = (int) $message->getId();
                $processed++;

                try {
                    $extracted = $this->runner->run($message);

                    if ([] !== $extracted) {
                        $events += count($this->reconciler->reconcile($message, $extracted));
                    }
                } catch (\Throwable $e) {
                    $io->warning(sprintf('Message %d: %s', $lastId, $e->getMessage()));
                }

                $io->progressAdvance();
            }

            $this->em->flush();
            $this->em->clear();
        }

        $io->progressFinish();
        $io->success(sprintf('Walked %d message(s); %d event(s) created or updated.', $processed, $events));

        return Command::SUCCESS;
    }
}
