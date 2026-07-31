<?php

declare(strict_types=1);

namespace App\Security\TwoFactor;

use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvent;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Rate limits the second factor.
 *
 * The firewall's login_throttling stops at the password form. Everything past
 * it — the six-digit code, and the backup codes — is guarded by nothing, and
 * that is the form an attacker reaches holding a password they stole or
 * phished. Six digits is 10^6, inside a window otphp widens to about a minute
 * by design; unthrottled, that is a few hours of requests.
 *
 * Counted per user rather than per IP. The secret being guessed belongs to one
 * account, and an IP key would let anyone sharing an address — a household
 * behind one NAT, an office — lock everyone else out of theirs.
 *
 * Only failures consume tokens, and a success clears the count. Someone who
 * fumbles two codes and then gets one right has done nothing suspicious and
 * should not be walking around on a shortened leash for the next quarter hour.
 */
final class TwoFactorThrottle
{
    public function __construct(
        private readonly RateLimiterFactoryInterface $twoFactorCodeLimiter,
    ) {
    }

    /**
     * Refuse before the code is checked, not after.
     *
     * A limiter consulted on failure only still lets every attempt reach the
     * verifier; the request has to be stopped on the way in for the limit to
     * mean anything.
     */
    #[AsEventListener(event: RequestEvent::class, priority: 8)]
    public function onRequest(RequestEvent $event): void
    {
        if (false === $event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if ('app_2fa_login_check' !== $request->attributes->get('_route')) {
            return;
        }

        $key = $this->keyFor($request);

        if (null === $key) {
            return;
        }

        if (false === $this->twoFactorCodeLimiter->create($key)->consume()->isAccepted()) {
            // A 429 rather than a redirect back to the form: the form would
            // invite another attempt, and this is the one answer a script
            // cannot usefully retry.
            throw new TooManyRequestsHttpException(
                null,
                'Too many two-factor attempts. Try again later.',
            );
        }
    }

    /**
     * A correct code clears the count.
     *
     * Without this the limiter punishes the legitimate user for the attacker's
     * attempts: they arrive, authenticate correctly, and are still one fumble
     * away from being locked out for fifteen minutes.
     */
    #[AsEventListener(event: TwoFactorAuthenticationEvents::SUCCESS)]
    public function onSuccess(TwoFactorAuthenticationEvent $event): void
    {
        $key = $this->keyFor($event->getRequest());

        if (null === $key) {
            return;
        }

        $this->twoFactorCodeLimiter->create($key)->reset();
    }

    /**
     * The session stands in for the user identity.
     *
     * The code form runs before authentication completes, so there is no
     * settled user on the request — but the half-authenticated state is pinned
     * to the session, which is as good a key here and does not require reaching
     * into token storage from a request listener.
     *
     * Both callers derive the key the same way on purpose: consuming under the
     * session and resetting under the username would mean the reset never
     * cleared what the consume counted, and the limit would look like it worked
     * while never actually being lifted.
     */
    private function keyFor(Request $request): ?string
    {
        $sessionId = $request->hasSession() ? $request->getSession()->getId() : null;

        if (null === $sessionId || '' === $sessionId) {
            return null;
        }

        return 'session:' . $sessionId;
    }
}
