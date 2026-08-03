<?php

namespace App\Domain\Model;

use App\Entity\Mail\Account;

class AccountModel
{
    public function getFromHeader(): string
    {
        if ($this instanceof Account) {
            if (null !== $this->name) {
                return sprintf('%s <%s>', $this->name, $this->email ?? $this->username);
            }

            return $this->email;
        }

        throw new \LogicException('Not an Account');
    }

    public function __toString(): string
    {
        return $this->getFromHeader();
    }
}
