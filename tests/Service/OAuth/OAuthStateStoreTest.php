<?php

declare(strict_types=1);

namespace App\Tests\Service\OAuth;

use App\Service\OAuth\OAuthStateStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * The `state` parameter is the only thing standing between an OAuth callback
 * and an attacker-supplied authorization code, so what this class remembers and
 * — more importantly — what it forgets is worth pinning.
 *
 * The session key names are asserted literally on purpose. Two live handshakes
 * use them (mail accounts under `oauth2`, integrations under
 * `integration_oauth`) and renaming a key silently fails every callback that
 * was already in flight, which is exactly the kind of breakage nothing else
 * would catch.
 */
final class OAuthStateStoreTest extends TestCase
{
    private OAuthStateStore $store;
    private Session $session;

    protected function setUp(): void
    {
        $this->store = new OAuthStateStore();
        $this->session = new Session(new MockArraySessionStorage());
    }

    public function testTheStateComesBackUnderTheKeyTheLiveFlowsAlreadyUse(): void
    {
        $this->store->remember($this->session, 'oauth2', 'abc123');

        self::assertSame('abc123', $this->session->get('oauth2_state'));
        self::assertSame('abc123', $this->store->consume($this->session, 'oauth2')['state']);
    }

    /**
     * A state that survived its own callback could be replayed, so consuming
     * has to be destructive. This is the assertion that fails if `consume`
     * ever becomes a plain read.
     */
    public function testAStateCannotBeUsedTwice(): void
    {
        $this->store->remember($this->session, 'oauth2', 'abc123');

        $this->store->consume($this->session, 'oauth2');

        self::assertNull($this->store->consume($this->session, 'oauth2')['state']);
        self::assertFalse($this->session->has('oauth2_state'));
    }

    public function testAHandshakeThatNeverStartedYieldsNothingToCompareAgainst(): void
    {
        $handshake = $this->store->consume($this->session, 'oauth2');

        self::assertNull($handshake['state']);
        self::assertNull($handshake['provider']);
    }

    /**
     * The confused-deputy case: a handshake begun for a mailbox must not be
     * completable as a file-store integration. Namespacing is what prevents it,
     * so one namespace must never see the other's state.
     */
    public function testOneFlowCannotReadTheOthersState(): void
    {
        $this->store->remember($this->session, 'oauth2', 'mail-state');

        self::assertNull($this->store->consume($this->session, 'integration_oauth')['state']);
        // And reading the wrong namespace must not have consumed the right one.
        self::assertSame('mail-state', $this->store->consume($this->session, 'oauth2')['state']);
    }

    /**
     * The integration callback carries a provider in its path, so the provider
     * the handshake was begun for is remembered beside the state — without it a
     * state minted for Dropbox could be replayed against Google Drive.
     */
    public function testTheProviderIsRememberedAndForgottenWithTheState(): void
    {
        $this->store->remember($this->session, 'integration_oauth', 'xyz', 'dropbox');

        self::assertSame('dropbox', $this->session->get('integration_oauth_provider'));

        $handshake = $this->store->consume($this->session, 'integration_oauth');

        self::assertSame('xyz', $handshake['state']);
        self::assertSame('dropbox', $handshake['provider']);
        self::assertFalse($this->session->has('integration_oauth_provider'));
    }

    /**
     * A stale provider left over from an abandoned handshake would be compared
     * against the next callback's path, so starting a flow without one must not
     * leave the previous value in place to be matched.
     */
    public function testAFlowWithNoProviderLeavesNoneBehind(): void
    {
        $this->store->remember($this->session, 'integration_oauth', 'first', 'dropbox');
        $this->store->consume($this->session, 'integration_oauth');

        $this->store->remember($this->session, 'integration_oauth', 'second');

        self::assertNull($this->store->consume($this->session, 'integration_oauth')['provider']);
    }
}
