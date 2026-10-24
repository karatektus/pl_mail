<?php

declare(strict_types=1);

namespace App\Service\Mail\PostIngest;

use App\Domain\DTO\Mail\PostIngestResult;
use App\Domain\Interface\PostIngestStepInterface;
use App\Infrastructure\Messaging\Message\VerifySignatureMessage;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Queues a signature check for every signed message that just arrived.
 *
 * Signed, not encrypted: an encrypted message's signature is inside the
 * ciphertext, and only the browser that decrypts it can check it. After the
 * commit, because the check reads the message back by id.
 */
final readonly class VerifySignaturesStep implements PostIngestStepInterface
{
    public function __construct(
        private MessageBusInterface $bus,
    ) {
    }

    public function afterCommit(PostIngestResult $result): void
    {
        foreach ($result->messages as $message) {
            if (null === $message->id || null === $message->crypto || true === $message->crypto->isEncrypted()) {
                continue;
            }

            $this->bus->dispatch(new VerifySignatureMessage($message->id));
        }
    }
}
