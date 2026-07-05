<?php

namespace App\Message;

readonly class SendMessageMessage
{
    public function __construct(public int $messageId)
    {
    }
}
