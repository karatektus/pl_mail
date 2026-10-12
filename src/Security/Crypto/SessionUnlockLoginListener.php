<?php

declare(strict_types=1);

namespace App\Security\Crypto;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Keeps the unlock key the login form sent, so the keyring opens on every
 * page of the session without the password being asked for again.
 *
 * The form makes the key, wraps the password-derived key under it in
 * localStorage, and posts only the key — see SessionUnlock and
 * assets/controllers/auth/login_controller.js. Kept from the password step,
 * before a second factor: the session it goes into is the one the code form
 * completes, and an unanswered code leaves nothing it could open.
 */
final readonly class SessionUnlockLoginListener implements EventSubscriberInterface
{
    public function __construct(
        private SessionUnlock $sessionUnlock,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [LoginSuccessEvent::class => 'onLoginSuccess'];
    }

    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $unlockKey = $event->getRequest()->request->get('unlock_key');

        if ('main' === $event->getFirewallName() && true === SessionUnlock::isWellFormed($unlockKey)) {
            $this->sessionUnlock->store($unlockKey);
        }
    }
}
