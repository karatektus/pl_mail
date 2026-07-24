<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Interface\MailSenderInterface;
use App\Entity\Account;
use App\Enum\AuthType;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;

/**
 * SMTP sending for generic password accounts. OAuth accounts are handled by a
 * provider-API sender instead, so this deliberately declines them.
 */
class SmtpMailSender implements MailSenderInterface
{
    public function __construct(
        private readonly SmtpDsnFactory $dsnFactory,
    ) {
    }

    public function supports(Account $account): bool
    {
        if (AuthType::OAuth2->value === $account->getAuthType()) {
            return false;
        }

        return true;
    }

    public function filesSentCopy(): bool
    {
        return false;
    }

    public function send(Email $email, Account $account): bool
    {
        try {
            $mailer = new Mailer(Transport::fromDsn($this->dsnFactory->forAccount($account)));
            $mailer->send($email);
        } catch (TransportExceptionInterface $e) {
            return false;
        }

        return true;
    }
}
