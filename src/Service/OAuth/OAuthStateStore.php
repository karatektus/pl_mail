<?php

declare(strict_types=1);

namespace App\Service\OAuth;

use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * The anti-CSRF `state` parameter's round trip through the session.
 *
 * Both OAuth entry points — mail accounts and file-store integrations — mint a
 * state on the way out and have to find it again on the way back. The mechanism
 * is identical and it is the only thing standing between a callback and an
 * attacker-supplied code, so it lives in one place rather than being written
 * twice and corrected once.
 *
 * What each caller does with a mismatch is *not* here: one raises 403 and the
 * other flashes and returns to settings, and that is a presentation decision.
 *
 * Namespaced by caller so the two flows cannot read each other's state. A single
 * shared key would let a handshake begun for a mailbox be completed as an
 * integration, which is the confused-deputy version of not checking at all.
 */
final readonly class OAuthStateStore
{
    /**
     * @param string|null $provider stored beside the state where the callback
     *                              route carries a provider in its path: without
     *                              it, a state minted for one provider could be
     *                              replayed against another
     */
    public function remember(
        SessionInterface $session,
        string $namespace,
        string $state,
        ?string $provider = null,
    ): void {
        $session->set($this->stateKey($namespace), $state);

        if (null !== $provider) {
            $session->set($this->providerKey($namespace), $provider);
        }
    }

    /**
     * Read the handshake back and forget it in the same breath.
     *
     * Single-use by construction: a state that survived its callback could be
     * replayed, and a stale one left behind would be matched by the next
     * handshake that never got to set its own.
     *
     * @return array{state: mixed, provider: mixed}
     */
    public function consume(SessionInterface $session, string $namespace): array
    {
        $state    = $session->get($this->stateKey($namespace));
        $provider = $session->get($this->providerKey($namespace));

        $session->remove($this->stateKey($namespace));
        $session->remove($this->providerKey($namespace));

        return ['state' => $state, 'provider' => $provider];
    }

    private function stateKey(string $namespace): string
    {
        return $namespace . '_state';
    }

    private function providerKey(string $namespace): string
    {
        return $namespace . '_provider';
    }
}
