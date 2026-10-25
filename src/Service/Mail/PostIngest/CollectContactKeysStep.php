<?php

declare(strict_types=1);

namespace App\Service\Mail\PostIngest;

use App\Domain\DTO\Mail\PostIngestResult;
use App\Domain\Interface\PostIngestStepInterface;
use App\Infrastructure\Messaging\Message\CollectContactKeysMessage;
use App\Service\Crypto\ContactKeyCollector;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Queues a look for the sender's key in every message that just arrived with
 * an Autocrypt header or something that looks like an attached key.
 *
 * Encrypted mail included: its Autocrypt header sits outside the ciphertext.
 * Whether the sender is the person themselves is left to the job, which
 * needs their addresses — a query this step would otherwise make per batch
 * for the few messages that carry a key at all.
 */
final readonly class CollectContactKeysStep implements PostIngestStepInterface
{
    public function __construct(
        private MessageBusInterface $bus,
    ) {
    }

    public function afterCommit(PostIngestResult $result): void
    {
        foreach ($result->messages as $message) {
            if (null === $message->id || false === ContactKeyCollector::mayCarryKeys($message)) {
                continue;
            }

            $this->bus->dispatch(new CollectContactKeysMessage($message->id));
        }
    }
}
