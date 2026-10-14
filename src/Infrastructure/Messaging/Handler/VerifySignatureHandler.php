<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Entity\Mail\Message;
use App\Infrastructure\Messaging\Message\VerifySignatureMessage;
use App\Service\Crypto\SignatureVerifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Stores what checking a message's signature found.
 *
 * Nothing is stored when the check could not run — no raw source, no gpg
 * to run — so the message reads as signed but unchecked, which is the truth,
 * rather than as Invalid, which would not be.
 */
#[AsMessageHandler]
final readonly class VerifySignatureHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private SignatureVerifier      $verifier,
    ) {
    }

    public function __invoke(VerifySignatureMessage $job): void
    {
        $message = $this->entityManager->find(Message::class, $job->messageId);

        if (null === $message) {
            return;
        }

        $result = $this->verifier->verify($message);

        if (null === $result) {
            return;
        }

        $message->signatureState = $result['state'];
        $message->signer         = $result['signer'];

        $this->entityManager->flush();
    }
}
