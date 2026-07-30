<?php

declare(strict_types=1);

namespace App\Tests\Service\OAuth;

use App\Domain\Enum\Account\MailProvider;
use App\Entity\Integration\MailProviderConfig;
use App\Repository\Integration\MailProviderConfigRepository;
use App\Service\OAuth\OAuthProviderFactory;
use League\OAuth2\Client\Provider\Google;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use TheNetworg\OAuth2\Client\Provider\Azure;

/**
 * Where mail OAuth credentials come from.
 *
 * This is the one place in the integrations work that can break something that
 * already works: every existing Gmail and Microsoft account depends on this
 * factory, and until now it read only env vars. The rule is database-first with
 * an env fallback, and "first" must not mean "a half-filled row wins" — an admin
 * who saves a client id and no secret must not silently disable a working .env.
 */
final class OAuthProviderFactoryTest extends TestCase
{
    public function testFallsBackToTheEnvironmentWhenNothingIsStored(): void
    {
        $provider = $this->factory(null)->create(MailProvider::Google);

        self::assertInstanceOf(Google::class, $provider);
        self::assertSame('env-google-id', $this->clientIdOf($provider));
    }

    public function testAStoredCredentialWins(): void
    {
        $config = new MailProviderConfig(MailProvider::Google);
        $config->clientId = 'db-google-id';
        $config->clientSecret = 'db-google-secret';

        self::assertSame(
            'db-google-id',
            $this->clientIdOf($this->factory($config)->create(MailProvider::Google)),
        );
    }

    public function testAHalfFilledRowDoesNotShadowAWorkingEnvironment(): void
    {
        // The dangerous case: an admin types an id, saves, and leaves the secret
        // blank. Treating the row as authoritative would break every existing
        // account with an empty secret.
        $config = new MailProviderConfig(MailProvider::Google);
        $config->clientId = null;
        $config->clientSecret = null;

        self::assertSame(
            'env-google-id',
            $this->clientIdOf($this->factory($config)->create(MailProvider::Google)),
        );
    }

    public function testTheAzureTenantAlsoFallsBack(): void
    {
        $fromEnv = $this->factory(null)->create(MailProvider::Microsoft);
        self::assertInstanceOf(Azure::class, $fromEnv);
        self::assertSame('common', $fromEnv->tenant);

        $config = new MailProviderConfig(MailProvider::Microsoft);
        $config->clientId = 'db-ms-id';
        $config->clientSecret = 'db-ms-secret';
        $config->setTenant('contoso.onmicrosoft.com');

        $stored = $this->factory($config)->create(MailProvider::Microsoft);
        self::assertInstanceOf(Azure::class, $stored);
        self::assertSame('contoso.onmicrosoft.com', $stored->tenant);
        self::assertSame('db-ms-id', $this->clientIdOf($stored));
    }

    public function testAnEmptyTenantStringIsTreatedAsUnset(): void
    {
        $config = new MailProviderConfig(MailProvider::Microsoft);
        $config->setTenant('   ');

        self::assertNull($config->getTenant());
        self::assertSame('common', $this->factory($config)->create(MailProvider::Microsoft)->tenant);
    }

    public function testIsCompleteNeedsBothHalves(): void
    {
        $config = new MailProviderConfig(MailProvider::Google);
        self::assertFalse($config->isComplete(), 'nothing set');

        $config->clientId = 'id';
        self::assertFalse($config->isComplete(), 'id alone is not enough to run a flow');

        $config->clientSecret = 'secret';
        self::assertTrue($config->isComplete());
    }

    /**
     * The client id the provider was actually built with.
     *
     * Read by reflection because league keeps clientId protected and exposes no
     * getter. The obvious alternative — reading it back out of
     * getAuthorizationUrl() — works for Google but not for Azure, whose
     * implementation performs live OpenID discovery over the network and so
     * cannot run in a unit test at all.
     */
    private function clientIdOf(object $provider): ?string
    {
        $property = new \ReflectionProperty($provider, 'clientId');

        $value = $property->getValue($provider);

        return null === $value ? null : (string) $value;
    }

    private function factory(?MailProviderConfig $config): OAuthProviderFactory
    {
        $repository = $this->createStub(MailProviderConfigRepository::class);
        $repository->method('findOneByProvider')->willReturn($config);

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturn('https://mail.example.com/oauth/callback');

        return new OAuthProviderFactory(
            $urlGenerator,
            $repository,
            'env-google-id',
            'env-google-secret',
            'env-ms-id',
            'env-ms-secret',
            'common',
        );
    }
}
