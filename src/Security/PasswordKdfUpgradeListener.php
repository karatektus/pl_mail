<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User\User;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Event\CheckPassportEvent;

/**
 * Moves an account onto a browser-stretched password the first time its owner
 * signs in through the form.
 *
 * The form sends the password itself as well as the auth hash only when the
 * prelogin said `migrate`, and LoginFormAuthenticator turns that into a
 * PasswordKdfUpgradeBadge. By the time this runs, CheckCredentialsListener has
 * verified the password against the old hash, so the auth hash beside it was
 * stretched from the right password — and it becomes the new stored hash.
 *
 * On CheckPassportEvent, after the credential check (priority 0) and not on
 * LoginSuccessEvent, because the remember-me cookie is signed over the
 * password hash during LoginSuccessEvent. A cookie signed over the old hash
 * would stop verifying the moment this wrote the new one.
 *
 * It runs before the second factor is asked for. That is harmless: the
 * password has been proven either way, and which of two hashes of it is
 * stored is not a decision a code should gate.
 *
 * Signing in moves the account over and that ends every other session it had —
 * Symfony logs a session out when the stored hash it was opened against
 * changes. Once, on the day the update reaches each person; the changelog says
 * so.
 */
final readonly class PasswordKdfUpgradeListener implements EventSubscriberInterface
{
    public function __construct(
        private PasswordKdfUpgrader $upgrader,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [CheckPassportEvent::class => ['upgrade', -10]];
    }

    public function upgrade(CheckPassportEvent $event): void
    {
        $passport = $event->getPassport();
        $badge    = $passport->getBadge(PasswordKdfUpgradeBadge::class);

        if (false === $badge instanceof PasswordKdfUpgradeBadge) {
            return;
        }

        // Resolved whatever happens below: this badge is an offer to tidy up,
        // and a sign-in that is otherwise good must not fail because the offer
        // could not be taken.
        $badge->markResolved();

        $credentials = $passport->getBadge(PasswordCredentials::class);
        $user        = $passport->getUser();

        if (null === $credentials || false === $credentials->isResolved() || false === $user instanceof User) {
            return;
        }

        // Already moved — by another tab, a moment ago — or the browser sent
        // something that is not an auth hash: the upgrader leaves it alone,
        // the old hash stands and the next sign-in asks the prelogin again.
        $this->upgrader->upgrade($user, $badge->authHash, $badge->email);
    }
}
