<?php

namespace App\Twig;

use App\Repository\Mail\AccountRepository;
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

        // The same ordering settings uses, because the drag handles there are
        // meant to arrange this list and an ordering of its own made them a
        // control over nothing. See findActiveForUserOrdered().
        return new ArrayIterator($this->accounts->findActiveForUserOrdered($user));
    }
}
