<?php

declare(strict_types=1);

namespace App\Security\TwoFactor;

use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvent;
use Scheb\TwoFactorBundle\Security\TwoFactor\Event\TwoFactorAuthenticationEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

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
     * ATTEMPT is dispatched by scheb's authenticator immediately before the
     * code reaches a provider, so every attempt passes through here and none
     * reaches the verifier once the limit is spent. It also carries the
     * half-authenticated token, which is where the key comes from.
     */
    #[AsEventListener(event: TwoFactorAuthenticationEvents::ATTEMPT)]
    public function onAttempt(TwoFactorAuthenticationEvent $event): void
    {
        $key = $this->keyFor($event->getToken());

        if (null === $key) {
            return;
        }

        if (false === $this->twoFactorCodeLimiter->create($key)->consume()->isAccepted()) {
            // A 429 rather than a redirect back to the form: the form would
            // invite another attempt, and this is the one answer a script
            // cannot usefully retry. An HTTP exception, not an authentication
            // one, so the authenticator's failure handler never sees it.
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
        $key = $this->keyFor($event->getToken());

        if (null === $key) {
            return;
        }

        $this->twoFactorCodeLimiter->create($key)->reset();
    }

    /**
     * The pending user's identifier, never the session.
     *
     * This used to key on the session id, which is the one thing the attacker
     * controls: a fresh password login is a fresh session and a fresh five
     * guesses, so a stolen password bought unlimited attempts at the code. The
     * user identifier on the two-factor token is the account being attacked,
     * and it survives any number of re-logins.
     *
     * Both callers derive the key the same way on purpose: consuming under one
     * key and resetting under another would mean the reset never cleared what
     * the consume counted.
     */
    private function keyFor(TokenInterface $token): ?string
    {
        $identifier = $token->getUserIdentifier();

        if ('' === $identifier) {
            return null;
        }

        return 'user:' . mb_strtolower($identifier);
    }
}
