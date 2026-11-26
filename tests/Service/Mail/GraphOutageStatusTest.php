<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Exception\GraphApiException;
use App\Domain\Exception\GraphThrottledException;
use App\Domain\Exception\GraphUnavailableException;
use App\Entity\Mail\Account;
use App\Service\Mail\GraphApiClient;
use App\Service\OAuth\OAuthTokenManager;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * What Graph's own failures are turned into (#603).
 *
 * The answer Exchange Online gave all through its incident of 12–13 November
 * 2026 — a 502 with `"code": "UnknownError"` and nothing else — used to be a
 * plain GraphApiException, indistinguishable from a request plMail got wrong.
 * The end of the retry ladder reads the class, so the class is what is pinned.
 */
final class GraphOutageStatusTest extends TestCase
{
    /** @return iterable<string, array{int}> */
    public static function microsoftsSide(): iterable
    {
        yield '500' => [500];
        yield '502' => [502];
        yield '504' => [504];
    }

    #[DataProvider('microsoftsSide')]
    public function testMicrosoftFailingIsAnOutage(int $status): void
    {
        $failure = $this->listFoldersAnswering($status);

        self::assertInstanceOf(GraphUnavailableException::class, $failure);
        self::assertSame($status, $failure->getStatus());
        self::assertStringStartsWith(
            sprintf('Graph request failed with %d: ', $status),
            $failure->getMessage(),
            'worded as before, so a search of older logs still finds it',
        );
    }

    /** 503 stays a throttle: it is how Graph says "busy", with Retry-After. */
    public function testA503IsStillThrottling(): void
    {
        self::assertInstanceOf(GraphThrottledException::class, $this->listFoldersAnswering(503));
    }

    /** A request Graph refuses is our problem, and must not be waved through. */
    public function testARefusalIsNotAnOutage(): void
    {
        $failure = $this->listFoldersAnswering(400);

        self::assertInstanceOf(GraphApiException::class, $failure);
        self::assertNotInstanceOf(GraphUnavailableException::class, $failure);
    }

    private function listFoldersAnswering(int $status): GraphApiException
    {
        // No GraphRetryStrategy here: this is the client on its own, and what
        // it throws once the HTTP layer has given up.
        $http = new MockHttpClient(new MockResponse(
            '{"error":{"code":"UnknownError","message":""}}',
            ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json']],
        ));

        $client = new GraphApiClient($http, $this->createStub(OAuthTokenManager::class));

        try {
            $client->listFolders($this->account());
        } catch (GraphApiException $e) {
            return $e;
        }

        self::fail('listFolders() should have thrown');
    }

    private function account(): Account
    {
        $account                   = new Account();
        $account->email            = 'graph@example.test';
        $account->authType         = AuthType::OAuth2->value;
        $account->oauthProvider    = 'microsoft';
        $account->oauthAccessToken = 'test-access-token';
        $account->oauthTokenExpiry = new DateTimeImmutable('+1 day');

        return $account;
    }
}
