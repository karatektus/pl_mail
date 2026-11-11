<?php

declare(strict_types=1);

namespace App\Tests\Service\Integration;

use App\Domain\Enum\Integration\Provider;
use App\Domain\Exception\IntegrationException;
use App\Entity\Integration\Integration;
use App\Entity\Integration\IntegrationProviderConfig;
use App\Entity\Integration\TrustedHost;
use App\Entity\User\User;
use App\Repository\Integration\IntegrationProviderConfig\IntegrationProviderConfigRepository;
use App\Repository\Integration\TrustedHost\TrustedHostRepository;
use App\Service\Integration\IntegrationUrlValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The SSRF guard. A logged-in user typing a server address is aiming our
 * outbound HTTP client, and the app runs on a container network next to
 * Postgres, Mercure and the workers — so "reject private ranges" here is the
 * difference between a self-hosting feature and an internal port scanner.
 */
final class IntegrationUrlValidatorTest extends TestCase
{
    #[DataProvider('forbiddenUrls')]
    public function testInternalAndMalformedAddressesAreRefused(string $url, string $why): void
    {
        $this->expectException(IntegrationException::class);

        (new IntegrationUrlValidator())->assertAllowed($url);
    }

    /**
     * @return iterable<string,array{string,string}>
     */
    public static function forbiddenUrls(): iterable
    {
        yield 'loopback by name'    => ['https://localhost/dav', 'reaches this container'];
        yield 'loopback by ip'      => ['https://127.0.0.1/dav', 'reaches this container'];
        yield 'ipv6 loopback'       => ['https://[::1]/dav', 'reaches this container'];
        yield 'docker network'      => ['https://172.17.0.3/dav', 'reaches a sibling container'];
        yield 'rfc1918'             => ['https://192.168.1.10/dav', 'reaches the host LAN'];
        yield 'cloud metadata'      => ['https://169.254.169.254/latest/meta-data', 'leaks instance credentials'];
        yield 'ipv6 unique local'   => ['https://[fd00::1]/dav', 'reaches a private network'];
        // Spellings a string check missed and the resolver does not.
        yield 'short ipv4'          => ['https://127.1/dav', 'libc reads it as 127.0.0.1'];
        yield 'decimal ipv4'        => ['https://2130706433/dav', 'libc reads it as 127.0.0.1'];
        yield 'ipv4-mapped ipv6'    => ['https://[::ffff:127.0.0.1]/dav', 'is loopback in IPv6 clothing'];
        yield 'mapped metadata'     => ['https://[::ffff:169.254.169.254]/', 'is the metadata endpoint'];
        yield 'unspecified ipv6'    => ['https://[::]/dav', 'connects to this machine'];
        yield 'credentials in url'  => ['https://user:pw@cloud.example.com', 'overrides the stored credential'];
        yield 'not a url'           => ['cloud.example.com', 'has no scheme'];
        yield 'wrong scheme'        => ['file:///etc/passwd', 'is not http'];
    }

    public function testPublicHttpsIsAllowed(): void
    {
        $this->expectNotToPerformAssertions();

        (new IntegrationUrlValidator())->assertAllowed('https://cloud.example.com/nextcloud');
    }

    public function testPlainHttpIsRefusedUnlessTheAdminAllowsIt(): void
    {
        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessageMatches('/https/');

        (new IntegrationUrlValidator())->assertAllowed('http://cloud.example.com');
    }

    public function testAnAllowedHostExemptsAPrivateAddress(): void
    {
        $this->expectNotToPerformAssertions();

        // How a self-hoster reaches their own LAN server on purpose (#564).
        (new IntegrationUrlValidator($this->trustedHosts(new TrustedHost('10.0.0.5'))))
            ->assertAllowed('https://10.0.0.5:8443/dav.php');
    }

    /**
     * Allowing a host on the network is not allowing plain http to it. The two
     * were separate environment variables and stay separate decisions: a
     * server with a certificate gains nothing from http, and its app passwords
     * would cross the network in the clear.
     */
    public function testAnAllowedHostStillNeedsHttpsUnlessHttpIsTickedForIt(): void
    {
        $validator = new IntegrationUrlValidator($this->trustedHosts(
            new TrustedHost('10.0.0.5'),
            new TrustedHost('10.0.0.6', allowHttp: true),
        ));

        $validator->assertAllowed('http://10.0.0.6:8080/dav.php');

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessageMatches('/https/');

        $validator->assertAllowed('http://10.0.0.5:8080/dav.php');
    }

    /**
     * Plain http is per host now, not an install-wide switch: ticking it for
     * the NAS does not let users send passwords in the clear to the internet.
     */
    public function testPlainHttpForOneHostIsNotPlainHttpForAnother(): void
    {
        $validator = new IntegrationUrlValidator($this->trustedHosts(
            new TrustedHost('10.0.0.6', allowHttp: true),
        ));

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessageMatches('/https/');

        $validator->assertAllowed('http://cloud.example.com/remote.php/dav');
    }

    /**
     * The URL spells the host however the user typed it; the list stores it
     * the one way TrustedHostList::normalise() does. Without the same
     * normalisation on both sides `NAS.lan` stays refused after `nas.lan` was
     * allowed, and an IPv6 host never matches at all because the URL carries
     * it in brackets.
     */
    public function testTheHostMatchesHoweverTheAddressSpellsIt(): void
    {
        $this->expectNotToPerformAssertions();

        $validator = new IntegrationUrlValidator($this->trustedHosts(
            new TrustedHost('fd00::5'),
            new TrustedHost('10.0.0.5'),
        ));

        $validator->assertAllowed('https://[FD00::5]/dav');
        $validator->assertAllowed('https://10.0.0.5./dav');
    }

    /**
     * The error is what a user reads, and it used to name an environment
     * variable a Docker Compose install could not even set (#564). It names
     * the admin page instead.
     */
    /**
     * A LAN server nobody allowed, typed with http://, is refused for being
     * on the LAN. Checked the other way round it was told to use https — a
     * server without a certificate cannot, and https would not have let it
     * through either, so the user had nothing true to pass on.
     */
    public function testAnUnlistedLanServerOnHttpIsRefusedForTheNetworkNotTheScheme(): void
    {
        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessageMatches('/private network/');

        (new IntegrationUrlValidator())->assertAllowed('http://192.168.1.10/dav.php');
    }

    public function testThePrivateNetworkRefusalSaysWhereAnAdministratorAllowsIt(): void
    {
        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessageMatches('/Admin → Integrations/');

        (new IntegrationUrlValidator())->assertAllowed('https://192.168.1.10/dav');
    }

    public function testAPinnedServerAddressExemptsItsHostFromThePrivateNetworkRule(): void
    {
        // The documented way to say "our Nextcloud is the container next
        // door". CalDavClient checks each request through assertAllowed(), so
        // the pin has to count there and not only in UserUrlHttpClient.
        $config = new IntegrationProviderConfig(Provider::Nextcloud);
        // Addresses rather than container names: a name this machine cannot
        // resolve is not refused at all, which would pass the test vacuously.
        $config->baseUrl = 'http://10.0.0.5:8080';

        // http:// in the pin is the administrator allowing plain http to
        // that host, as deliberately as ticking it would be.
        $validator = new IntegrationUrlValidator(configs: $this->configs($config));

        $validator->assertAllowed('http://10.0.0.5:8080/remote.php/dav/calendars/alice/');

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessageMatches('/private network/');

        // Only the pinned host: its neighbours on the same network stay refused.
        $validator->assertAllowed('http://10.0.0.6:5432/');
    }

    /**
     * A NAME that resolves into loopback is refused as surely as the address.
     * `localhost.` with the trailing dot slips past a comparison against
     * "localhost" and is still answered by the resolver.
     */
    public function testAHostnameResolvingToLoopbackIsRefused(): void
    {
        $this->expectException(IntegrationException::class);

        (new IntegrationUrlValidator())->assertAllowed('https://localhost./dav');
    }

    public function testAPinnedAdminUrlWinsOverTheUsersOwn(): void
    {
        $config = new IntegrationProviderConfig(Provider::Nextcloud);
        $config->baseUrl = 'https://company.example.com/';

        $integration = new Integration(new User(), Provider::Nextcloud, 'Home');
        // Even an address that would otherwise be refused outright is simply
        // ignored once an admin has pinned one.
        $integration->baseUrl = 'http://169.254.169.254';

        $resolved = (new IntegrationUrlValidator())->resolve($integration, $config);

        self::assertSame('https://company.example.com', $resolved);
    }

    public function testUsersCanOnlyEditTheAddressForSelfHostedUnpinnedProviders(): void
    {
        $validator = new IntegrationUrlValidator();

        $pinned = new IntegrationProviderConfig(Provider::Nextcloud);
        $pinned->baseUrl = 'https://company.example.com';

        self::assertTrue($validator->isUserEditable(Provider::Nextcloud, null));
        self::assertFalse($validator->isUserEditable(Provider::Nextcloud, $pinned), 'a pinned address removes the field');
        self::assertFalse($validator->isUserEditable(Provider::GoogleDrive, null), 'SaaS providers have one canonical host');
    }

    public function testAConnectionWithNoAddressAnywhereFails(): void
    {
        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessageMatches('/No server address/');

        (new IntegrationUrlValidator())->resolve(new Integration(new User(), Provider::Nextcloud, 'Home'), null);
    }

    /** A repository holding exactly these allowed hosts, with no Doctrine behind it. */
    private function trustedHosts(TrustedHost ...$hosts): TrustedHostRepository
    {
        return new class (array_values($hosts)) extends TrustedHostRepository {
            /** @param list<TrustedHost> $hosts */
            public function __construct(private readonly array $hosts)
            {
                // Deliberately does not call the parent: it wants a
                // ManagerRegistry, and findOneByHost() is all the validator asks.
            }

            public function findOneByHost(string $host): ?TrustedHost
            {
                foreach ($this->hosts as $trusted) {
                    if ($trusted->host === $host) {
                        return $trusted;
                    }
                }

                return null;
            }
        };
    }

    /** A repository holding exactly these provider configs, with no Doctrine behind it. */
    private function configs(IntegrationProviderConfig ...$configs): IntegrationProviderConfigRepository
    {
        return new class (array_values($configs)) extends IntegrationProviderConfigRepository {
            /** @param list<IntegrationProviderConfig> $configs */
            public function __construct(private readonly array $configs)
            {
                // Deliberately does not call the parent: it wants a
                // ManagerRegistry, and findAll() is all this test asks.
            }

            /**
             * @return list<IntegrationProviderConfig>
             *
             * @phpstan-pure Doctrine marks findAll() impure; this one only returns the array.
             */
            public function findAll(): array
            {
                return $this->configs;
            }
        };
    }
}
