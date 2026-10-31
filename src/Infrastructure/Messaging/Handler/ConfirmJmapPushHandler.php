<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Infrastructure\Messaging\Message\ConfirmJmapPushMessage;
use App\Repository\Mail\AccountRepository;
use App\Service\JmapClient\JmapPushSubscriptionManager;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Echoes a JMAP PushVerification back, so the server starts pushing.
 *
 * A confirmation that fails is not retried here: the subscription stays
 * unverified, the EventSource stream goes on carrying the account's push, and
 * the next renewal registers a fresh subscription that gets a fresh code.
 */
#[AsMessageHandler]
final readonly class ConfirmJmapPushHandler
{
    public function __construct(
        private AccountRepository           $accounts,
        private JmapPushSubscriptionManager $manager,
    ) {
    }

    public function __invoke(ConfirmJmapPushMessage $message): void
    {
        $account = $this->accounts->find($message->accountId);

        if (null === $account || false === $account->isJmap()) {
            return;
        }

        $this->manager->confirm($account, $message->subscriptionId, $message->verificationCode);
    }
}
