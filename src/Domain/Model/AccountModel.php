<?php

namespace App\Domain\Model;

use App\Entity\Account;

class AccountModel
{
    public function getFromHeader(): string
    {
        if ($this instanceof Account) {
            if (null !== $this->getName()) {
                return sprintf('%s <%s>', $this->getName(), $this->getEmail() ?? $this->getUsername());
            }

            return $this->getEmail();
        }

        throw new \LogicException('Not an Account');
    }

    public function __toString(): string
    {
        return $this->getFromHeader();
    }
}
