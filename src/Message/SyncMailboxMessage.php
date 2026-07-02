<?php

namespace App\Message;

readonly class SyncMailboxMessage
{
    public function __construct(
        public int $mailboxId,
    ) {}
}
