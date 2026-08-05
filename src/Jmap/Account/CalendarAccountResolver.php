<?php

declare(strict_types=1);

namespace App\Jmap\Account;

use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Jmap\Protocol\Exception\MethodException;

/**
 * Which JMAP account a user's calendars are served from.
 *
 * A JMAP accountId is a connected mail Account (see AccountResolver), but a
 * plMail Calendar is the *user's* — user-scoped like Label and MailRule, with
 * the mail account only ever an optional owner for the one calendar extraction
 * files into. So the same list of calendars is reachable from every account a
 * user has connected, and there is no per-account identity for one the way
 * LabelBinding gives a label one.
 *
 * Serving that list from every account would therefore publish one calendar
 * under three accountIds. A client keys every object by (accountId, id), so it
 * would draw the calendar three times, and an event created on it would appear
 * to exist three times over — with no way for the client to tell that the three
 * are one. **Exactly one account serves calendars**, then: the one the Session
 * already names in primaryAccounts, which is the user's first. Any other
 * account is refused with "accountNotSupportedByMethod", RFC 8620's error for
 * precisely this — an accountId that exists but does not hold this object type.
 *
 * A user with no mail account has nowhere to serve calendars from and the
 * Session advertises none. That is a real state (a user can delete their last
 * account and keep a calendar) and it degrades to "this install has no calendar
 * account", not to an error at some other account's expense.
 *
 * Reads User::$accounts and Account::$id and nothing else, the same two
 * SessionBuilder and AccountResolver are coupled to; the "first account" rule
 * is spelled here so those two and every method cannot answer it differently.
 */
final class CalendarAccountResolver
{
    public function __construct(
        private readonly AccountResolver $accountResolver,
    ) {
    }

    /**
     * The account calendars belong to, or null when the user has none at all.
     */
    public function accountFor(User $user): ?Account
    {
        $account = $user->accounts->first();

        if (false === $account instanceof Account) {
            return null;
        }

        return $account;
    }

    /**
     * The account a calendar method was called with, having proved it is the
     * one that serves calendars.
     *
     * Goes through AccountResolver first so an unknown or foreign accountId is
     * still "accountNotFound" — telling a stranger that an id they do not own
     * is merely unsupported here would confirm the id exists.
     */
    public function resolve(User $user, mixed $accountId): Account
    {
        $account = $this->accountResolver->resolve($user, $accountId);
        $calendarAccount = $this->accountFor($user);

        if (null === $calendarAccount || $calendarAccount->id !== $account->id) {
            throw new MethodException(
                'accountNotSupportedByMethod',
                sprintf(
                    'Calendars are served from account "%s" only; they are the user\'s rather than one account\'s.',
                    null === $calendarAccount ? '' : (string) $calendarAccount->id,
                ),
            );
        }

        return $account;
    }
}
