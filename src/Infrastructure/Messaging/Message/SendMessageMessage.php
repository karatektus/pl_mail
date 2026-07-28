<?php

namespace App\Infrastructure\Messaging\Message;

readonly class SendMessageMessage
{
    public function __construct(public int $messageId)
    {
    }
}
