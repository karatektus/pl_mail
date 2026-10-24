<?php

declare(strict_types=1);

namespace App\Tests\Service\Oidc;

use App\Repository\Oidc\OidcConfigRepository;
use App\Service\OAuth\OAuthStateStore;
use App\Service\Oidc\IdTokenValidator;
use App\Service\Oidc\OidcClient;
use App\Service\Oidc\OidcDiscovery;
use App\Service\Oidc\OidcLoginFailed;
use App\Service\Oidc\OidcSettings;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Nothing in a callback's query is believed until its state has matched.
 *
 * The callback is a GET anybody can link to. It used to read `error` before it
 * compared the state, so a link carrying `?error=…` — with no sign-in behind
 * it at all — was answered as the provider turning the person away, and its
 * `error_description` went into the log at warning level in its author's own
 * words. The state is what says a callback answers a sign-in this browser
 * started, so it is compared first.
 */
final class OidcClientTest extends TestCase
{
    public function testAnErrorOnACallbackWithoutTheSessionsStateIsNotBelieved(): void
    {
        $request = $this->callbackRequest(['error' => 'access_denied', 'error_description' => 'forged'], 'the-state');

        try {
            $this->client()->complete($request);
            self::fail('expected a refusal');
        } catch (OidcLoginFailed $e) {
            self::assertSame('oidc.failed', $e->messageKey);
            self::assertStringNotContainsString('forged', $e->getMessage());
        }
    }

    public function testAnErrorCarryingTheSessionsStateIsTheProvidersRefusal(): void
    {
        $request = $this->callbackRequest(['error' => 'access_denied', 'state' => 'the-state'], 'the-state');

        try {
            $this->client()->complete($request);
            self::fail('expected a refusal');
        } catch (OidcLoginFailed $e) {
            self::assertSame('oidc.denied', $e->messageKey);
        }
    }

    /**
     * @param array<string, string> $query
     */
    private function callbackRequest(array $query, string $sessionState): Request
    {
        $session = new Session(new MockArraySessionStorage());
        new OAuthStateStore()->remember($session, 'oidc_login', $sessionState);

        $request = Request::create('/login/oidc/callback', 'GET', $query);
        $request->setSession($session);

        return $request;
    }

    private function client(): OidcClient
    {
        return new OidcClient(
            new OidcSettings(self::createStub(OidcConfigRepository::class), 'https://auth.example.com/', 'client', 'secret', '', false, 'true', 'openid email'),
            // Neither refusal gets as far as the provider: a request here
            // would be the test failing, and MockHttpClient throws on one.
            new OidcDiscovery(new MockHttpClient([]), new ArrayAdapter()),
            new IdTokenValidator(),
            new OAuthStateStore(),
            $this->createStub(UrlGeneratorInterface::class),
            new MockClock(),
            new NullLogger(),
        );
    }
}
