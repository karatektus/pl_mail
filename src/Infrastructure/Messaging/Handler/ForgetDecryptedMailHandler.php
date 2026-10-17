<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Domain\Enum\Crypto\PlaintextSource;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Infrastructure\Messaging\Message\ForgetDecryptedMailMessage;
use App\Service\Crypto\DecryptedMail;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Works through an account's decrypted mail in batches, forgetting each.
 *
 * Does nothing if the switch is on again by the time it runs: somebody who
 * turned it off and on within a minute meant "on", and their mail would only
 * be decrypted all over again.
 */
#[AsMessageHandler]
final readonly class ForgetDecryptedMailHandler
{
    private const int BATCH = 100;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private DecryptedMail          $decryptedMail,
    ) {
    }

    public function __invoke(ForgetDecryptedMailMessage $job): void
    {
        do {
            $account = $this->entityManager->find(Account::class, $job->accountId);

            if (null === $account || true === $account->keepDecrypted) {
                return;
            }

            /** @var list<Message> $messages */
            $messages = $this->entityManager->createQuery(
                'SELECT m FROM ' . Message::class . ' m WHERE m.account = :account AND m.plaintextSource = :client ORDER BY m.id ASC',
            )
                ->setParameter('account', $account)
                ->setParameter('client', PlaintextSource::Client)
                ->setMaxResults(self::BATCH)
                ->getResult();

            foreach ($messages as $message) {
                $this->decryptedMail->forget($message);
            }

            $this->entityManager->flush();
            $this->entityManager->clear();
        } while (self::BATCH === count($messages));
    }
}
