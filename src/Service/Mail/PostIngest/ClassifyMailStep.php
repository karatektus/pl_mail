<?php

declare(strict_types=1);

namespace App\Service\Mail\PostIngest;

use App\Domain\DTO\Mail\PostIngestResult;
use App\Domain\Interface\PostIngestStepInterface;
use App\Entity\Ai\AiFeature;
use App\Infrastructure\Messaging\Message\ClassifyMailMessage;
use App\Service\Ai\AiAssistant;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Queues a second opinion for a freshly ingested batch — when anybody asked
 * for one.
 *
 * Dispatches and returns, which is the whole contract: this runs on a worker
 * holding an IMAP connection or a Graph rate-limit budget, and a language model
 * on another machine is the last thing that belongs inside a sync.
 *
 * THE GUARD IS HERE AND NOT ONLY IN THE HANDLER
 * ─────────────────────────────────────────────
 * Checking twice looks redundant and is not. Almost every installation has this
 * switched off, and without this check every one of them would enqueue a job
 * per arriving batch for a handler whose entire body is `return`. That is a
 * queue that fills, a transport that has to drain it, and a failure surface
 * that exists only for people who declined the feature.
 *
 * The handler checks again because settings can change between dispatch and
 * delivery, and because a job already on the queue when somebody switches the
 * feature off must not still run.
 */
final readonly class ClassifyMailStep implements PostIngestStepInterface
{
    public function __construct(
        private MessageBusInterface $bus,
        private AiAssistant         $ai,
    ) {
    }

    public function afterCommit(PostIngestResult $result): void
    {
        if (false === $this->ai->isEnabledFor(AiFeature::Categorise)) {
            return;
        }

        $ids = [];

        foreach ($result->messages as $message) {
            $id = $message->id;

            if (null !== $id) {
                $ids[] = (int) $id;
            }
        }

        // WHICH of these are worth asking about is the handler's decision, not
        // this one. The test is "did the deterministic cascade fall through to
        // its default", and answering it properly needs the correspondent set —
        // which is a query, and a query does not belong in a step that runs
        // inside a sync. Dispatching the batch and narrowing on the other side
        // costs one job for a batch that turns out to need nothing.

        if ([] === $ids) {
            return;
        }

        $this->bus->dispatch(new ClassifyMailMessage($ids));
    }
}
