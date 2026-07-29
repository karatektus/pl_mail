<?php

declare(strict_types=1);

namespace App\Domain\Enum\Account;

/**
 * Transport security for an IMAP or SMTP connection.
 */
enum ConnectionEncryption: string
{
    case Ssl = 'ssl';
    case StartTls = 'starttls';
    case None = 'none';
}
