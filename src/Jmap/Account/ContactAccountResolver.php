<?php

declare(strict_types=1);

namespace App\Jmap\Account;

use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Jmap\Protocol\Exception\MethodException;

/**
 * Which JMAP account a user's contacts are served from, under RFC 9610.
 *
 * Contacts are the user's, as calendars are, so CalendarAccountResolver's
 * argument holds word for word: served from every account, one card would be
 * three to a client keying by (accountId, id). So exactly one serves them, and
 * it is the same one — asked of that class, so the two can never disagree about
 * which account holds a user's own things.
 *
 * Only for the RFC methods. plMail's own Contact/* methods answer on every
 * account; see Capability::CONTACTS.
 */
final class ContactAccountResolver
{
    public function __construct(
        private readonly AccountResolver $accountResolver,
        private readonly CalendarAccountResolver $calendarAccounts,
    ) {
    }

    public function accountFor(User $user): ?Account
    {
        return $this->calendarAccounts->accountFor($user);
    }

    /**
     * The account an RFC 9610 method was called with, having proved it is
     * the one that serves contacts.
     */
    public function resolve(User $user, mixed $accountId): Account
    {
        $account  = $this->accountResolver->resolve($user, $accountId);
        $contacts = $this->accountFor($user);

        if (null === $contacts || $contacts->id !== $account->id) {
            throw new MethodException(
                'accountNotSupportedByMethod',
                sprintf('Contacts are served from account "%s" only; they are the user\'s rather than one account\'s.', null === $contacts ? '' : (string) $contacts->id),
            );
        }

        return $account;
    }
}
