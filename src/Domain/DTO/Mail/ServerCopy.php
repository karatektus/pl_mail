<?php

declare(strict_types=1);

namespace App\Domain\DTO\Mail;

use App\Entity\Mail\Mailbox;

/**
 * One place an IMAP server keeps a message: a folder and the UID it has there.
 * See App\Service\Mail\MessageCopies.
 */
final readonly class ServerCopy
{
    public function __construct(
        public Mailbox $mailbox,
        public int     $uid,
    ) {}
}
