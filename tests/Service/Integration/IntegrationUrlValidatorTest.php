<?php

declare(strict_types=1);

namespace App\Tests\Service\Integration;

use App\Domain\Enum\Integration\Provider;
use App\Domain\Exception\IntegrationException;
use App\Entity\Integration\Integration;
use App\Entity\Integration\IntegrationProviderConfig;
use App\Entity\User\User;
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

        (new IntegrationUrlValidator(allowHttp: true))->assertAllowed($url);
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

        (new IntegrationUrlValidator(allowHttp: false))->assertAllowed('http://cloud.example.com');
    }

    public function testAllowedHostsExemptAPrivateAddress(): void
    {
        $this->expectNotToPerformAssertions();

        // How a self-hoster reaches their own LAN server on purpose.
        (new IntegrationUrlValidator(allowHttp: true, allowedHosts: 'nextcloud.lan, 10.0.0.5'))
            ->assertAllowed('http://10.0.0.5:8080');
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
}
