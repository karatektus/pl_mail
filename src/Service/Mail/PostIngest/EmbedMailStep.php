<?php

declare(strict_types=1);

namespace App\Service\Mail\PostIngest;

use App\Domain\DTO\Mail\PostIngestResult;
use App\Domain\Interface\PostIngestStepInterface;
use App\Entity\Ai\AiFeature;
use App\Infrastructure\Messaging\Message\EmbedMessagesMessage;
use App\Service\Ai\AiAssistant;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Queues newly arrived mail for embedding, when semantic search is on.
 *
 * The guard is here as well as in the handler for the reason ClassifyMailStep
 * gives: almost every installation has this off, and without it every one of
 * them would enqueue a job per arriving batch for a handler whose whole body is
 * `return`.
 */
final readonly class EmbedMailStep implements PostIngestStepInterface
{
    public function __construct(
        private MessageBusInterface $bus,
        private AiAssistant         $ai,
    ) {
    }

    public function afterCommit(PostIngestResult $result): void
    {
        if (false === $this->ai->isEnabledFor(AiFeature::Search)) {
            return;
        }

        $ids = [];

        foreach ($result->messages as $message) {
            $id = $message->id;

            if (null !== $id) {
                $ids[] = (int) $id;
            }
        }

        if ([] === $ids) {
            return;
        }

        $this->bus->dispatch(new EmbedMessagesMessage($ids));
    }
}
