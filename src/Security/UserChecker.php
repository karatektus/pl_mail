<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The two states of an account that hold a correct password at arm's length.
 *
 * A user an administrator has suspended, and a user they have removed. Both are
 * rows that still exist and still carry everything the person owns; neither may
 * be authenticated. This is where that is decided, once, for every way in —
 * the login form, a remember-me cookie, and the app passwords the JMAP firewall
 * accepts, which is why the checker is registered on both firewalls rather than
 * only on `main`. An app password is a credential that outlives the browser
 * session it was minted from, so a suspension enforced only at the login form
 * would leave every already-connected mail client running.
 *
 * ── Why the refusal is in checkPostAuth, not checkPreAuth ────────────────
 * checkPreAuth is the conventional place and is deliberately empty. It runs
 * BEFORE the password is verified, so refusing there answers "is this address
 * a suspended account on this install?" to anybody who types the address —
 * Symfony's own `hide_user_not_found` exists to stop exactly that question
 * being answerable, and this would hand it back for the accounts most worth
 * asking about. checkPostAuth runs once the credentials have already checked
 * out, so the only person who learns an account is suspended is the person who
 * could otherwise have signed into it, and who is entitled to be told why they
 * cannot.
 *
 * The cost is real and is the right trade: a failed sign-in here has already
 * spent a password verification, and it counts against the firewall's login
 * throttle like any other failure.
 *
 * ── What this does not cover ─────────────────────────────────────────────
 * A session that is ALREADY open. Symfony runs user checkers at authentication
 * only — ContextListener rehydrates a session token through the user provider
 * and never consults a checker — so suspending somebody who is signed in would
 * otherwise take effect whenever their session happened to end. That half is
 * UserProvider::refreshUser(), which refuses the same two states on every
 * request. The pair is deliberate: this one decides who may come in, that one
 * decides who may stay.
 */
final class UserChecker implements UserCheckerInterface
{
    /**
     * Deliberately empty. See the class docblock: the checks below have to run
     * after the password has been verified, or the failure message becomes an
     * oracle for which addresses exist.
     */
    public function checkPreAuth(UserInterface $user): void
    {
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        if (false === $user instanceof User) {
            return;
        }

        // Removed first, because it is the stronger statement and the message
        // for it says less. A removed row cannot normally reach this point —
        // the admin delete action blanks the hash, so no password matches —
        // but that is a side effect of freeing the address rather than a rule
        // anybody wrote down, and it would stop holding the moment a hash got
        // written back by a restore, a backup import, or a repair by hand.
        if (true === $user->isDeleted()) {
            throw new CustomUserMessageAccountStatusException('account.removed');
        }

        if (true === $user->isDeactivated) {
            throw new CustomUserMessageAccountStatusException('account.deactivated');
        }
    }
}
