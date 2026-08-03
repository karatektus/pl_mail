<?php

declare(strict_types=1);

namespace App\Jmap\Account;

use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Jmap\Protocol\Exception\MethodException;

/**
 * Resolves and ownership-checks the accountId that every mail method carries.
 * A JMAP accountId maps 1:1 onto a connected mail Account; the lookup runs
 * over the user's own accounts, so a foreign or unknown id can never resolve.
 *
 * The second (and last) place coupled to the account entity shape, alongside
 * SessionBuilder: it uses User::getMailAccounts() and Account::getId(), both
 * already proven by the working /jmap/session response.
 */
final class AccountResolver
{
    public function resolve(User $user, mixed $accountId): Account
    {
        if (false === is_string($accountId) || '' === $accountId) {
            throw new MethodException('invalidArguments', 'A string "accountId" is required.');
        }

        foreach ($user->accounts as $account) {
            if ((string) $account->getId() === $accountId) {
                return $account;
            }
        }

        throw new MethodException('accountNotFound', sprintf('No account "%s" for this user.', $accountId));
    }
}
