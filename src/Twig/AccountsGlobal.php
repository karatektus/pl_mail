<?php

namespace App\Twig;

use App\Entity\Mail\Account;
use App\Service\Mail\UserAccounts;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Service\ResetInterface;
use Traversable;

/**
 * The user's active accounts, as a Twig global.
 *
 * Memoised, and that is the whole of the change: getIterator() used to run the
 * query every time anything touched `accounts`, and the thread row touches it
 * once per row — `accounts|length > 1` decides whether the account corner
 * wedge is drawn at all. A fifty-row list therefore issued fifty-odd identical
 * `SELECT … FROM account WHERE usr_id = ? AND is_active = true` statements,
 * which was the larger half of the list view's query count and looked in a
 * profile exactly like a lazy association, because that is what it behaves
 * like. It is not one: nothing here is mapped lazy, the object simply had no
 * memory.
 *
 * Countable as well as iterable, so `|length` answers from the cached array
 * instead of counting an iterator — and so a template asking only for the
 * count never builds one.
 *
 * ResetInterface for the same worker-mode hygiene App\Service\Mail\SidebarCounts has: a
 * long-running process serves more than one user, and a list of accounts held
 * across requests would be somebody else's.
 *
 * @implements IteratorAggregate<int, Account>
 */
class AccountsGlobal implements IteratorAggregate, Countable, ResetInterface
{
    /** @var list<Account>|null */
    private ?array $accounts = null;

    public function __construct(
        private readonly UserAccounts $userAccounts,
        private readonly Security     $security,
    ) {}

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->all());
    }

    public function count(): int
    {
        return count($this->all());
    }

    /**
     * @return list<Account>
     */
    public function all(): array
    {
        if (null !== $this->accounts) {
            return $this->accounts;
        }

        $user = $this->security->getUser();

        if (null === $user) {
            return $this->accounts = [];
        }

        // The same ordering settings uses, because the drag handles there are
        // meant to arrange this list and an ordering of its own made them a
        // control over nothing. See findActiveForUserOrdered().
        //
        // Through UserAccounts rather than straight at the repository since the
        // topbar's health dot asks the same table for the same user on the same
        // render — see that class. The memo here is still worth keeping on top
        // of it: this one is what stops `accounts|length` in the thread row
        // rebuilding the filtered list fifty times a page, which is a PHP cost
        // rather than a query cost and so invisible to the query budget.
        return $this->accounts = $this->userAccounts->active($user);
    }

    public function reset(): void
    {
        $this->accounts = null;
    }
}
