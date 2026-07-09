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

        throw new \LogicException('Not a Message');
    }

    public function addFlag(MessageFlag $flag): static
    {
        if ($this instanceof Message) {
            if (false === in_array($flag->value, $this->getFlags(), true)) {
                $this->setFlags([...$this->getFlags(), $flag->value]);
            }

            return $this;
        }

        throw new \LogicException('Not a Message');
    }

    public function removeFlag(MessageFlag $flag): static
    {
        if ($this instanceof Message) {
            $this->setFlags(array_values(array_filter(
                $this->getFlags(),
                static fn ($value) => $value !== $flag->value,
            )));

            return $this;
        }

        throw new \LogicException('Not a Message');
    }

}
