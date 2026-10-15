<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Repository\Oidc\OidcConfigRepository;
use App\Repository\User\UserRepository;
use App\Security\OidcAuthenticator;
use App\Service\OAuth\OAuthStateStore;
use App\Service\Oidc\IdTokenValidator;
use App\Service\Oidc\OidcClient;
use App\Service\Oidc\OidcDiscovery;
use App\Service\Oidc\OidcIdentity;
use App\Service\Oidc\OidcSettings;
use App\Service\Oidc\OidcUserResolver;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\EventListener\CheckRememberMeConditionsListener;

/**
 * Signing in through the provider is remembered, which is what keeps the
 * person signed in past the PHP session.
 *
 * Without the badge the only thing holding them in was a session cookie that
 * ends with the browser, in a file cleaned up after 24 minutes without a
 * request and lost with the container on every deploy. The callback is a GET
 * from the provider with no form, so nothing on the request asks for
 * remember-me; the passport has to.
 *
 * Judged where the firewall judges it: the listener that decides whether the
 * badge is switched on, given the request the callback is, which carries no
 * `_remember_me`.
 */
final class OidcAuthenticatorRememberMeTest extends TestCase
{
    public function testASignInFromTheProviderTurnsRememberMeOnWithoutAnythingOnTheRequest(): void
    {
        $passport = $this->authenticator()->passportFor($this->identity());

        $this->runTheFirewallsRememberMeCheck($passport);

        self::assertTrue($passport->hasBadge(RememberMeBadge::class), 'the passport carries no remember-me badge');
        self::assertTrue($this->badge($passport)->isEnabled(), 'the badge is there but the firewall left it switched off');
    }

    /**
     * The presence companion: the same check, on the same kind of request,
     * leaves a plain badge off. A listener that switched every badge on would
     * make the test above pass whatever the authenticator did, and would also
     * mean the password form's unticked checkbox stopped working.
     */
    public function testTheSameCheckLeavesAnOrdinaryBadgeOffWhenNothingAsksForIt(): void
    {
        $passport = new SelfValidatingPassport(
            new UserBadge('someone', static fn () => new InMemoryUser('someone', null)),
            [new RememberMeBadge()],
        );

        $this->runTheFirewallsRememberMeCheck($passport);

        self::assertFalse($this->badge($passport)->isEnabled());
    }

    private function runTheFirewallsRememberMeCheck(Passport $passport): void
    {
        // The options the `main` firewall runs with: always_remember_me is not
        // set, so only the badge or the request can ask.
        $listener = new CheckRememberMeConditionsListener();

        $listener->onSuccessfulLogin(new LoginSuccessEvent(
            $this->authenticator(),
            $passport,
            new NullToken(),
            Request::create('/login/oidc/callback', 'GET', ['code' => 'c', 'state' => 's']),
            null,
            'main',
        ));
    }

    private function badge(Passport $passport): RememberMeBadge
    {
        $badge = $passport->getBadge(RememberMeBadge::class);
        self::assertInstanceOf(RememberMeBadge::class, $badge);

        return $badge;
    }

    private function identity(): OidcIdentity
    {
        return new OidcIdentity('https://auth.example.com', 'subject-1', 'someone@example.com', true, null, null, null);
    }

    private function authenticator(): OidcAuthenticator
    {
        $settings = new OidcSettings(self::createStub(OidcConfigRepository::class), 'https://auth.example.com/', 'client', 'secret', '', false, 'true', 'openid email');

        return new OidcAuthenticator(
            $settings,
            new OidcClient(
                $settings,
                new OidcDiscovery(new MockHttpClient([]), new ArrayAdapter()),
                new IdTokenValidator(),
                new OAuthStateStore(),
                self::createStub(UrlGeneratorInterface::class),
                new MockClock(),
                new NullLogger(),
            ),
            new OidcUserResolver($settings, self::createStub(UserRepository::class), self::createStub(EntityManagerInterface::class), new NullLogger()),
            self::createStub(EntityManagerInterface::class),
            self::createStub(UrlGeneratorInterface::class),
            new NullLogger(),
        );
    }
}
