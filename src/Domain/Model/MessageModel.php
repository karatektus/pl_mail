<?php

namespace App\Domain\Model;

use App\Domain\Enum\Mail\MessageFlag;
use App\Entity\Mail\Message;

class MessageModel
{
    /**
     * Stays a method: it asks whether one entry is present in a JSON list, which
     * is an interpretation of that list rather than a read of a boolean column.
     */
    public function isDraft(): bool
    {
        if ($this instanceof Message) {
            return in_array(MessageFlag::DRAFT->value, $this->flags);
        }

        throw new \LogicException('Not a Message');
    }

    public function addFlag(MessageFlag $flag): static
    {
        if ($this instanceof Message) {
            if (false === in_array($flag->value, $this->flags, true)) {
                $this->flags = [...$this->flags, $flag->value];
            }

            return $this;
        }

        throw new \LogicException('Not a Message');
    }

    public function removeFlag(MessageFlag $flag): static
    {
        if ($this instanceof Message) {
            $this->flags = array_values(array_filter(
                $this->flags,
                static fn ($value) => $value !== $flag->value,
            ));

            return $this;
        }

        throw new \LogicException('Not a Message');
    }
}
