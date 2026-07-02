<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Transport security for an IMAP or SMTP connection.
 */
enum ConnectionEncryption: string
{
    case Ssl = 'ssl';
    case StartTls = 'starttls';
    case None = 'none';
}
