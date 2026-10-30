<?php

declare(strict_types=1);

namespace App\Tests\Service\Integration;

use App\Domain\Enum\Integration\Provider;
use App\Entity\Integration\IntegrationProviderConfig;
use App\Repository\Integration\IntegrationProviderConfigRepository;
use App\Service\Integration\IntegrationOAuthProviderFactory;
use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Guzzle's default is no timeout at all, and a token refresh runs ahead of a
 * page's first call to a service (#449). All four OAuth services, because they
 * are built three different ways.
 */
final class IntegrationOAuthProviderFactoryTest extends TestCase
{
    public function testEveryProviderIsGivenAClientThatGivesUp(): void
    {
        foreach ([Provider::GoogleDrive, Provider::GooglePhotos, Provider::OneDrive, Provider::Dropbox] as $provider) {
            $config               = new IntegrationProviderConfig($provider);
            $config->clientId     = 'id';
            $config->clientSecret = 'secret';

            $configs = $this->createStub(IntegrationProviderConfigRepository::class);
            $configs->method('findOneByProvider')->willReturn($config);

            $urls = $this->createStub(UrlGeneratorInterface::class);
            $urls->method('generate')->willReturn('https://plmail.test/callback');

            $client = new IntegrationOAuthProviderFactory($configs, $urls)->create($provider)->getHttpClient();

            self::assertInstanceOf(Client::class, $client);
            self::assertGreaterThan(0, $client->getConfig('timeout'), $provider->value);
        }
    }
}
