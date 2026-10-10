<?php

declare(strict_types=1);
namespace App\Tests\Service\Gmail;
use App\Entity\Mail\Account;
use App\Service\Gmail\GmailQuotaPacer;
use App\Service\Mail\GmailApiClient;
use App\Service\OAuth\OAuthTokenManager;
use App\Domain\Exception\GmailPermanentException;
use App\Domain\Exception\GmailThrottledException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GmailQuotaApiTest extends TestCase
{
    public function testOldHundredMessageJobsAreSplitIntoFiftyAndEveryGetCostsTwenty(): void
    {
        $clock = new MockClock('2026-10-10 12:00:00 UTC');
        $pacer = new GmailQuotaPacer($clock);
        $counts = [];
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$counts): MockResponse {
            preg_match_all('/Content-ID: <([^>]+)>/i', (string) $options['body'], $ids);
            $counts[] = count($ids[1]);
            $body = '';
            foreach ($ids[1] as $id) { $body .= "--response\r\nContent-ID: <response-$id>\r\n\r\nHTTP/1.1 200 OK\r\n\r\n" . json_encode(['id' => $id]) . "\r\n"; }
            return new MockResponse($body . "--response--\r\n");
        });
        $client = $this->client($http, $pacer);
        $account = new Account(); $account->email = 'batch@example.test';
        $result = $client->getMessages($account, array_map(strval(...), range(1, 100)));
        self::assertSame([50, 50], $counts);
        self::assertCount(100, $result['payloads']);
        self::assertGreaterThanOrEqual(15, $clock->now()->getTimestamp() - strtotime('2026-10-10 12:00:00 UTC'));
    }

    public function testPartialQuotaAndPermanent403RemainDistinctAndCooldownBlocksOtherMethods(): void
    {
        $clock = new MockClock('2026-10-10 12:00:00 UTC'); $pacer = new GmailQuotaPacer($clock);
        $body = "--response\r\nContent-ID: <response-good>\r\n\r\nHTTP/1.1 200 OK\r\n\r\n{\"id\":\"good\"}\r\n"
            . "--response\r\nContent-ID: <response-quota>\r\n\r\nHTTP/1.1 403 Forbidden\r\nRetry-After: 120\r\n\r\n{\"error\":{\"errors\":[{\"reason\":\"userRateLimitExceeded\"}]}}\r\n"
            . "--response\r\nContent-ID: <response-permanent>\r\n\r\nHTTP/1.1 403 Forbidden\r\n\r\n{\"error\":{\"errors\":[{\"reason\":\"insufficientPermissions\"}]}}\r\n--response--\r\n";
        $http = new MockHttpClient([new MockResponse($body)]);
        $client = $this->client($http, $pacer); $account = new Account(); $account->email = 'partial@example.test';
        $result = $client->getMessages($account, ['good','quota','permanent']);
        self::assertSame(['quota'], $result['retryable']); self::assertSame(['permanent'], $result['gone']); self::assertArrayHasKey('good', $result['payloads']);
        self::assertGreaterThanOrEqual(120000, $client->retryDelay($account, 0));
        $this->expectException(GmailThrottledException::class);
        $client->getProfile($account);
    }

    public function testWholeBatchQuotaIsClassifiedAndHttpDateRetryAfterIsHonoured(): void
    {
        $clock = new MockClock('2026-10-10 12:00:00 UTC'); $pacer = new GmailQuotaPacer($clock);
        $http = new MockHttpClient([new MockResponse('{"error":{"errors":[{"reason":"userRateLimitExceeded"}]}}', ['http_code' => 403, 'response_headers' => ['Retry-After: Sat, 10 Oct 2026 12:02:00 GMT']])]);
        try { $this->client($http, $pacer)->getMessages(new Account(), ['a']); self::fail('Must classify whole-batch failure'); }
        catch (GmailThrottledException $error) { self::assertSame(120, $error->getRetryAfterSeconds()); }
    }

    public function testWholeFailedBatchRemainsVisibleAfterUnrelatedRecovery(): void
    {
        $clock = new MockClock('2026-10-10 12:00:00 UTC'); $pacer = new GmailQuotaPacer($clock);
        $account = new Account(); $account->email = 'whole-failure@example.test';
        $http = new MockHttpClient([
            new MockResponse('{"error":{"errors":[{"reason":"userRateLimitExceeded"}]}}', ['http_code' => 403]),
            new MockResponse("--response\r\nContent-ID: <response-b>\r\n\r\nHTTP/1.1 200 OK\r\n\r\n{\"id\":\"b\"}\r\n--response--\r\n"),
            new MockResponse("--response\r\nContent-ID: <response-a>\r\n\r\nHTTP/1.1 200 OK\r\n\r\n{\"id\":\"a\"}\r\n--response--\r\n"),
        ]);
        $client = $this->client($http, $pacer);
        try { $client->getMessages($account, ['a']); self::fail('Expected quota rejection'); }
        catch (GmailThrottledException) {}
        $clock->sleep(61);
        $client->getMessages($account, ['b']); self::assertNotNull($pacer->health($account)['warning']);
        $client->getMessages($account, ['a']); self::assertNull($pacer->health($account)['warning']);
    }

    public function testMalformedSuccessfulPartIsRetryableInsteadOfGone(): void
    {
        $clock = new MockClock(); $pacer = new GmailQuotaPacer($clock);
        $http = new MockHttpClient([new MockResponse("--response\r\nContent-ID: <response-a>\r\n\r\nHTTP/1.1 200 OK\r\n\r\n{}\r\n--response--\r\n")]);
        $account = new Account();
        $result = $this->client($http, $pacer)->getMessages($account, ['a']);
        self::assertSame(['a'], $result['retryable']); self::assertSame([], $result['gone']);
        self::assertSame('incomplete', $pacer->health($account)['warning']);
    }

    public function testWholeRetryBackoffIsBoundedAndDoesNotShortenRetryAfter(): void
    {
        $error = new GmailThrottledException('synthetic quota', 403, 'userRateLimitExceeded');
        self::assertSame(60500, $error->withBackoff(0, 500)->getRetryDelay());
        self::assertSame(120500, $error->withBackoff(1, 500)->getRetryDelay());
        self::assertSame(300500, $error->withBackoff(4, 500)->getRetryDelay());
        self::assertSame(900500, (new GmailThrottledException('synthetic quota', 429, '', 900))->withBackoff(4, 500)->getRetryDelay());
    }

    public function testWholePermanentBatchAndServerErrorsLeaveVisibleIncompleteMail(): void
    {
        $clock = new MockClock(); $pacer = new GmailQuotaPacer($clock);
        $account = new Account(); $account->email = 'permanent-whole@example.test';
        $http = new MockHttpClient([new MockResponse('{"error":{"errors":[{"reason":"insufficientPermissions"}]}}', ['http_code' => 403])]);
        try { $this->client($http, $pacer)->getMessages($account, ['a']); self::fail('Expected permanent failure'); }
        catch (GmailPermanentException $error) { self::assertSame('insufficientPermissions', $error->getReason()); }
        self::assertSame('permanent', $pacer->health($account)['warning']);
        $account->email = 'server-whole@example.test';
        $http = new MockHttpClient([new MockResponse('{}', ['http_code' => 503])]);
        try { $this->client($http, $pacer)->getMessages($account, ['b']); self::fail('Expected server failure'); }
        catch (\App\Domain\Exception\GmailApiException) {}
        self::assertSame('incomplete', $pacer->health($account)['warning']);
    }

    public function testTransportFailureLeavesVisibleIncompleteMail(): void
    {
        $pacer = new GmailQuotaPacer(new MockClock());
        $account = new Account(); $account->email = 'transport@example.test';
        $http = new MockHttpClient(static function (): never {
            throw new \Symfony\Component\HttpClient\Exception\TransportException('Synthetic connection failure');
        });
        try { $this->client($http, $pacer)->getMessages($account, ['a']); self::fail('Expected transport failure'); }
        catch (\Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface) {}
        self::assertSame('incomplete', $pacer->health($account)['warning']);
        self::assertNull($pacer->health($account)['retryAt']);
    }

    private function client(MockHttpClient $http, GmailQuotaPacer $pacer): GmailApiClient
    {
        $tokens = $this->createStub(OAuthTokenManager::class);
        $tokens->method('getValidAccessToken')->willReturn('synthetic-token');
        return new GmailApiClient($http, $tokens, $pacer);
    }
}
