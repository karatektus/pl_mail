<?php

namespace App\Domain\Model;

use App\Entity\User;
use LogicException;

class UserEntityModel
{
    public const ROLES = [
        User::ROLE_ADMIN,
    ];

    private ?string $plainPassword = null;

    public function addRole(string $role): User
    {
        if ($this instanceof User) {
            if (false === in_array($role, $this->getRoles()) && true === in_array($role, self::ROLES)) {
                $currentRoles = $this->getRoles();
                $currentRoles[] = $role;

                $this->setRoles($currentRoles);
            }

            return $this;
        }

        throw new LogicException();
    }

    public function removeRole(string $role): User
    {
        if ($this instanceof User) {
            if (true === in_array($role, $this->getRoles()) && true === in_array($role, self::ROLES)) {
                $currentRoles = $this->getRoles();
                $roleKey = array_search($role, $currentRoles);
                unset($currentRoles[$roleKey]);
                $this->setRoles($currentRoles);
            }

            return $this;
        }

        throw new LogicException();
    }

    public function getLocale(): string
    {
        return 'de';
    }

    public function getPlainPassword(): ?string
    {
        return $this->plainPassword;
    }

    public function getName(): string
    {
        if (true === $this instanceof User) {
            return sprintf('%s %s', $this->getNameFirst(), $this->getNameLast());
        }

        throw new LogicException('Not a User');
    }

    public function setPlainPassword(?string $plainPassword): self
    {
        $this->plainPassword = $plainPassword;

        return $this;
    }

    public function isDeleted(): ?bool
    {
        if (true === $this instanceof User) {
            return $this->getDeletedAt() !== null;
        }

        throw new LogicException('Not a User');
    }
}