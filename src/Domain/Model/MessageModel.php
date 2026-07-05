<?php

namespace App\Domain\Model;

use App\Domain\Enum\MessageFlag;
use App\Entity\Message;

class MessageModel
{
    public function isDraft(): bool
    {
        if ($this instanceof Message) {
            return in_array(MessageFlag::DRAFT->value, $this->getFlags());
        }

        throw new \LogicException('Not an Account');
    }

}
