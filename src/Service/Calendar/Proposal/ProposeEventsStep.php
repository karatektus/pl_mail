<?php

declare(strict_types=1);

namespace App\Service\Calendar\Proposal;

use App\Domain\DTO\Mail\PostIngestResult;
use App\Domain\Interface\PostIngestStepInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Looks for a date in prose in each freshly ingested message.
 *
 * A post-ingest step rather than a hook in the three sync paths, for the reason
 * the interface exists at all: a feature that reacts to new mail should be a
 * class, not three edits. And it belongs after ingest rather than inside it
 * because it depends on work the pipeline does in the same pass — the category
 * is assigned in that loop, and refusing bulk mail is this feature's first and
 * most important rule.
 *
 * It does its work inline instead of dispatching a job, which is the one place
 * this bends PostIngestStepInterface's stated contract, so here is the case for
 * it and the line it must not cross. That rule is about cost: a step runs on a
 * worker holding an IMAP connection or a Graph rate-limit budget, and a fetch,
 * an image decode or a raw-MIME parse there delays every message behind it.
 * This path performs no I/O at all. The body is already hydrated on the row,
 * the gate is a single regex over it, and the overwhelming majority of messages
 * are refused by the category check before even that — the ones that get as far
 * as being parsed are a few per cent, and the parse is regular expressions over
 * a few kilobytes. Queuing that would cost a Messenger round trip per batch to
 * move microseconds off the ingest worker.
 *
 * The line: a detector that calls anything — a model, a service, a network —
 * must NOT run here. When one is added, it goes behind a job and this step
 * dispatches for the messages the deterministic detector could not read.
 * ProposalDetectorInterface is where that boundary will be drawn.
 *
 * Flushes its own writes. The pipeline's flushes are done by the time steps
 * run, and a proposal that is only persisted is a proposal nobody ever sees.
 */
final readonly class ProposeEventsStep implements PostIngestStepInterface
{
    public function __construct(
        private EventProposer          $proposer,
        private EntityManagerInterface $em,
        private LoggerInterface        $logger,
    ) {
    }

    public function afterCommit(PostIngestResult $result): void
    {
        $proposed = 0;

        foreach ($result->messages as $message) {
            // Per message, because one message the parser chokes on must not
            // cost the batch its other proposals. The pipeline's own guard is
            // one level coarser than that: it catches for the whole step.
            try {
                if (null !== $this->proposer->propose($message)) {
                    $proposed++;
                }
            } catch (\Throwable $e) {
                $this->logger->error('EventProposal: proposing failed', [
                    'messageId' => $message->id,
                    'error'     => $e->getMessage(),
                    'exception' => $e,
                ]);
            }
        }

        if (0 === $proposed) {
            return;
        }

        $this->em->flush();
    }
}
