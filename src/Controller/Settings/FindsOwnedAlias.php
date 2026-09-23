<?php

declare(strict_types=1);

namespace App\Controller\Settings;

use App\Entity\Mail\Account;
use App\Entity\Mail\EmailAlias;

/**
 * The alias with this id, but only among the account's own.
 *
 * The three settings controllers that act on one alias (aliases, signatures,
 * compose defaults) each carried this loop verbatim. Walking the account's
 * collection rather than finding by id is the ownership check: the account has
 * already passed the voter, so an alias id from somebody else's account is not
 * in the list and is a 404 — never a way to edit that alias.
 */
trait FindsOwnedAlias
{
    private function ownedAlias(Account $account, int $aliasId): EmailAlias
    {
        foreach ($account->aliases as $alias) {
            if ($alias->id === $aliasId) {
                return $alias;
            }
        }

        throw $this->createNotFoundException('No such alias on this account.');
    }
}
