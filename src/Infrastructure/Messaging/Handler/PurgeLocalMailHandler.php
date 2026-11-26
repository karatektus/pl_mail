<?php

declare(strict_types=1);

namespace App\Infrastructure\Messaging\Handler;

use App\Infrastructure\Messaging\Message\PurgeLocalMailMessage;
use App\Repository\Mail\Account\AccountRepository;
use App\Service\Mail\LocalMailPurger;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Runs LocalMailPurger for the account the message names.
 *
 * Deliberately no check of the mail switch: the purge sends nothing anywhere
 * in either position, so there is nothing for the switch to guard here (see
 * LocalMailPurger). An account deleted in the meantime took its mail with it.
 */
#[AsMessageHandler]
final readonly class PurgeLocalMailHandler
{
    public function __construct(
        private AccountRepository $accounts,
        private LocalMailPurger   $purger,
    ) {
    }

    public function __invoke(PurgeLocalMailMessage $message): void
    {
        $account = $this->accounts->find($message->accountId);

        if (null === $account) {
            return;
        }

        $this->purger->purge($account);
    }
}
