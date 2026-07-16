<?php

declare(strict_types=1);

namespace App\Domain\Interface;

use App\Entity\Account;
use Symfony\Component\Mime\Email;

interface MailSenderInterface
{
    /**
     * Whether this sender handles the given account.
     */
    public function supports(Account $account): bool;

    /**
     * Send a fully built MIME email. Returns false on a handled failure.
     */
    public function send(Email $email, Account $account): bool;

    /**
     * Whether this sender files the Sent copy itself (true for provider APIs),
     * so the caller should skip the manual IMAP append.
     */
    public function filesSentCopy(): bool;
}
