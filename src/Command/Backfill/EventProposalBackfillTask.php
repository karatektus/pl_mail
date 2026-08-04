<?php

declare(strict_types=1);

namespace App\Command\Backfill;

use App\Domain\Enum\Mail\MessageCategory;
use App\Entity\Mail\Message;
use App\Repository\Mail\MessageRepository;
use App\Service\Calendar\Proposal\EventProposer;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Re-reads stored mail for dates written in prose.
 *
 * The counterpart of EventExtractionBackfillTask, and it exists for the same
 * reason that one does: the parser will improve, and improving it must mean
 * running this rather than resyncing anybody's mailbox. Every detector reads
 * persisted data only — the body, the headers, the message's own date — which
 * is what makes that possible.
 *
 * Idempotent, and not by luck. EventProposer refuses a message that already
 * carries a proposal, so a second run over judged mail changes nothing; and it
 * asks EventSuppression before it writes, so a proposal the user threw away
 * stays thrown away. Those two rules are the whole of it, and they live in the
 * proposer rather than here, so a run from the console and a message arriving
 * are decided identically.
 *
 * Walks Primary mail only. That is not an optimisation with a cost: refusing
 * everything else is the proposer's first rule, so nothing is skipped that a
 * full walk would have accepted — and a mailbox is mostly newsletters, which is
 * precisely the set this leaves out.
 *
 * Offset paging rather than the keyset walk EventExtractionBackfillTask uses,
 * and it is safe here for a reason worth stating: this task writes into
 * event_proposal and never touches `message`, so the ordered set it is paging
 * over cannot shift under it. A task that rewrites the column it pages by —
 * CategoryBackfillTask — has no such licence.
 */
final readonly class EventProposalBackfillTask implements BackfillTaskInterface
{
    private const int BATCH_SIZE = 200;

    public function __construct(
        private MessageRepository      $messages,
        private EventProposer          $proposer,
        private EntityManagerInterface $em,
    ) {
    }

    public function getName(): string
    {
        return 'proposals';
    }

    public function getDescription(): string
    {
        return 'Re-read stored mail for dates written in prose and offer them as calendar proposals.';
    }

    public function run(SymfonyStyle $io): int
    {
        $total = $this->messages->count(['category' => MessageCategory::Primary]);

        if (0 === $total) {
            $io->success('No personal mail to read.');

            return Command::SUCCESS;
        }

        $io->progressStart($total);

        $offset    = 0;
        $processed = 0;
        $proposed  = 0;

        while (true) {
            /** @var list<Message> $batch */
            $batch = $this->messages->findBy(
                ['category' => MessageCategory::Primary],
                ['id' => 'ASC'],
                self::BATCH_SIZE,
                $offset,
            );

            if (0 === count($batch)) {
                break;
            }

            foreach ($batch as $message) {
                $processed++;
                $offset++;

                try {
                    if (null !== $this->proposer->propose($message)) {
                        $proposed++;

                        // Per message, not per batch: a rejected flush closes
                        // the manager and takes everything queued behind it,
                        // and one unreadable body must not cost the rest their
                        // proposals.
                        $this->em->flush();
                    }
                } catch (\Throwable $e) {
                    $io->warning(sprintf('Message %d: %s', (int) $message->id, $e->getMessage()));
                }

                // Doctrine closes the manager on a failed flush and every
                // operation after that throws, so continuing is not
                // resilience — it is several hundred more messages read for
                // nothing. Stop while the failure still has a cause attached.
                if (false === $this->em->isOpen()) {
                    $io->progressFinish();
                    $io->error(sprintf(
                        'Stopped at message %d: the entity manager closed, so nothing further could be saved.',
                        (int) $message->id,
                    ));

                    return Command::FAILURE;
                }

                $io->progressAdvance();
            }

            $this->em->clear();
        }

        $io->progressFinish();
        $io->success(sprintf('Read %d message(s); %d proposal(s) offered.', $processed, $proposed));

        return Command::SUCCESS;
    }
}
