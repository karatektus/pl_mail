<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Interface\MailSenderInterface;
use App\Entity\Account;

/**
 * Picks the sender for an account. Senders are injected highest-priority first
 * (API senders before SMTP), so the first that supports the account wins.
 */
class MailSenderRegistry
{
    /**
     * @param iterable<MailSenderInterface> $senders
     */
    public function __construct(
        private readonly iterable $senders,
    ) {
    }

    public function resolve(Account $account): MailSenderInterface
    {
        foreach ($this->senders as $sender) {
            if (true === $sender->supports($account)) {
                return $sender;
            }
        }

        throw new \RuntimeException(sprintf(
            'No mail sender supports account %d.',
            (int) $account->getId(),
        ));
    }
}
