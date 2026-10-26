<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Entity\Mail\Message;
use App\Infrastructure\Messaging\Message\CollectContactKeysMessage;
use App\Service\Crypto\ContactKeyCollector;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Keeps the keys a message brought for its sender.
 *
 * Whatever this records, ContactKeys asks for the sender's signed mail to be
 * checked again — this message included — so the order in which this job and
 * the message's own signature check run does not matter.
 */
#[AsMessageHandler]
final readonly class CollectContactKeysHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private ContactKeyCollector    $collector,
    ) {
    }

    public function __invoke(CollectContactKeysMessage $job): void
    {
        $message = $this->entityManager->find(Message::class, $job->messageId);

        if (null === $message) {
            return;
        }

        $this->collector->collect($message);
    }
}
