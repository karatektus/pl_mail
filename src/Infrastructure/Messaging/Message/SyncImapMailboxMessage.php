<?php

namespace App\Infrastructure\Messaging\Message;

use App\Domain\Enum\Mail\SyncTrigger;

readonly class SyncImapMailboxMessage
{
    public function __construct(
        public int $mailboxId,
        // `??` when reading: see SyncAccountMessage.
        public ?SyncTrigger $trigger = null,
    ) {}
}
