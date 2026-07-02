<?php

namespace App\Twig;

use App\Repository\AccountRepository;
use ArrayIterator;
use IteratorAggregate;
use Symfony\Bundle\SecurityBundle\Security;
use Traversable;

readonly class AccountsGlobal implements IteratorAggregate
{
    public function __construct(
        private AccountRepository $accounts,
        private Security          $security,
    ) {}

    public function getIterator(): Traversable
    {
        $user = $this->security->getUser();

        if ($user === null) {
            return new ArrayIterator([]);
        }

        return new ArrayIterator($this->accounts->findBy(['usr' => $user, 'isActive' => true]));
    }
}
