<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Entity\Mail\Account;
use App\Repository\Mail\AccountRepository;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The From selector: who the user picked to send as.
 *
 * The compose form offers one option per sendable address — an account and each
 * of its aliases — and carries the choice as a single "accountId|address"
 * token, because a Symfony choice value is one string and the answer is two
 * things. ComposeType writes those tokens; this reads them back.
 *
 * Reading one is an authorisation decision as much as a parse: the token comes
 * back from the browser, so an id in it is a claim, not a fact. A token naming
 * an account the user does not own, or one that has been deactivated since the
 * window opened, resolves to nothing and the caller falls back — it is never
 * trusted far enough to send from.
 */
final readonly class SenderResolver
{
    public function __construct(
        private AccountRepository $accountRepository,
    ) {
    }

    /** The token that pre-selects an account in a freshly opened window. */
    public function token(Account $account): string
    {
        return sprintf('%d|%s', $account->id, $account->displayAddress ?? $account->email ?? '');
    }

    /**
     * The account a submitted token selects, or null when it selects nothing
     * the user may send from.
     */
    public function accountFor(mixed $token, ?UserInterface $user): ?Account
    {
        $parsed = $this->parse($token, $user);

        return null !== $parsed ? $parsed[0] : null;
    }

    /**
     * The exact From address the user picked, falling back to the account's
     * display address when the token is absent or points elsewhere.
     *
     * "Points elsewhere" is the case that matters: the caller may have settled
     * on a different account than the token names (a stale window, a token that
     * no longer resolves), and an alias of one account is not an address of
     * another.
     */
    public function addressFor(mixed $token, Account $account, ?UserInterface $user): string
    {
        $parsed = $this->parse($token, $user);

        if (null !== $parsed && $parsed[0] === $account) {
            return $parsed[1];
        }

        return $account->displayAddress ?? $account->email ?? '';
    }

    /**
     * @return array{0: Account, 1: string}|null
     */
    private function parse(mixed $token, ?UserInterface $user): ?array
    {
        if (false === is_string($token) || false === str_contains($token, '|')) {
            return null;
        }

        [$id, $address] = explode('|', $token, 2);
        $account = $this->accountRepository->find((int) $id);

        if (
            null === $account
            || $account->usr !== $user
            || false === (bool) $account->isActive
        ) {
            return null;
        }

        return [$account, $address];
    }
}
