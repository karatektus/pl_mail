<?php

declare(strict_types=1);

namespace App\Tests\Service\Integration;

use App\Domain\Enum\Integration\Provider;
use App\Entity\Integration\Integration;
use App\Entity\User\User;
use App\Repository\Integration\IntegrationProviderConfigRepository;
use App\Service\Integration\IntegrationOAuthProviderFactory;
use App\Service\Integration\IntegrationTokenManager;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Shared harness for the four OAuth drivers.
 *
 * Records every request, draining a streamed body at send time — the client
 * consumes it on the way out, so reading it at assertion time always yields an
 * empty string.
 *
 * The token manager is stubbed rather than exercised: these tests are about
 * each service's API shape, and the refresh flow has its own test.
 */
abstract class OAuthDriverTestCase extends TestCase
{
    /** @var list<array{method:string,url:string,options:array<string,mixed>,body:string}> */
    protected array $requests = [];

    protected function setUp(): void
    {
        $this->requests = [];
    }

    /**
     * @param list<ResponseInterface> $responses
     */
    protected function client(array $responses): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): ResponseInterface {
            $this->requests[] = [
                'method'  => $method,
                'url'     => $url,
                'options' => $options,
                'body'    => $this->drain($options['body'] ?? ''),
            ];

            return array_shift($responses) ?? new MockResponse('', ['http_code' => 500]);
        });
    }

    /**
     * The real token manager, not a double — it is final, and building it means
     * the drivers exercise the genuine "stored token is still valid" path. The
     * refresh branch is never reached here because the fixture integration
     * carries a future expiry; refreshing has its own test.
     */
    protected function tokens(): IntegrationTokenManager
    {
        return new IntegrationTokenManager(
            new IntegrationOAuthProviderFactory(
                $this->createStub(IntegrationProviderConfigRepository::class),
                $this->createStub(UrlGeneratorInterface::class),
            ),
            $this->createStub(EntityManagerInterface::class),
        );
    }

    protected function integration(Provider $provider): Integration
    {
        $integration = new Integration(new User(), $provider, $provider->label());
        $integration->oauthAccessToken = 'access-token';
        // Comfortably outside the manager's refresh buffer, so the stored token
        // is used as-is.
        $integration->oauthTokenExpiry = new DateTimeImmutable('+1 hour');

        return $integration;
    }

    /**
     * The bearer token actually sent on a recorded request.
     *
     * Read back off the Authorization header rather than the auth_bearer
     * option: the client normalises the latter away before the callback sees
     * it, so checking the option would report null even when a token was sent.
     */
    protected function bearerOf(int $index): ?string
    {
        $header = $this->requests[$index]['options']['normalized_headers']['authorization'][0] ?? null;

        if (null === $header) {
            return null;
        }

        return str_starts_with($header, 'Authorization: Bearer ')
            ? substr($header, strlen('Authorization: Bearer '))
            : $header;
    }

    /**
     * @return array<string,mixed>
     */
    protected function jsonBodyOf(int $index): array
    {
        $decoded = json_decode($this->requests[$index]['body'], true);

        return is_array($decoded) ? $decoded : [];
    }

    protected function drain(mixed $body): string
    {
        if (true === is_string($body)) {
            return $body;
        }

        $buffer = '';

        if ($body instanceof \Closure) {
            while ('' !== ($chunk = $body(16372))) {
                $buffer .= $chunk;
            }

            return $buffer;
        }

        if (true === is_iterable($body)) {
            foreach ($body as $chunk) {
                $buffer .= $chunk;
            }
        }

        return $buffer;
    }

    /**
     * A temp file with known contents, cleaned up by the caller's finally.
     */
    protected function tempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'drv');
        self::assertIsString($path);
        file_put_contents($path, $contents);

        return $path;
    }
}
