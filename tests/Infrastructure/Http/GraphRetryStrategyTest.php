<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Http;

use App\Infrastructure\Http\GraphRetryStrategy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpClient\RetryableHttpClient;

/**
 * What the Graph client repeats, and what it must not.
 *
 * Driven through RetryableHttpClient rather than by calling shouldRetry()
 * directly, because the decision that matters is the one the wired-up client
 * makes: the strategy is only half of it, and a status code list that reads
 * correctly can still be applied to the wrong method.
 */
final class GraphRetryStrategyTest extends TestCase
{
    /**
     * The whole point. Graph blinks, the second attempt succeeds, and the
     * syncer never learns about it — where before, that folder sat out a
     * fifteen-minute cycle and left an ERROR behind.
     */
    public function testItRepeatsAReadThatGraphAnsweredWithFiveHundredTwo(): void
    {
        $client = $this->client([
            new MockResponse('{"error":{"code":"UnknownError","message":""}}', ['http_code' => 502]),
            new MockResponse('{"value":[]}', ['http_code' => 200]),
        ]);

        $response = $client->request('GET', 'https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages/delta');

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * THE GUARD, and the reason the list is per-method rather than Symfony's
     * default — which retries 502 whatever the method was.
     *
     * A 502 means the gateway gave up on the answer, not that Exchange never
     * acted. /me/sendMail is a POST, and a mail that may already be gone is not
     * something to send again on the strength of a missing reply.
     */
    public function testItDoesNotRepeatASendThatGraphAnsweredWithFiveHundredTwo(): void
    {
        $client = $this->client([
            new MockResponse('{"error":{"code":"UnknownError","message":""}}', ['http_code' => 502]),
            new MockResponse('', ['http_code' => 202]),
        ]);

        $response = $client->request('POST', 'https://graph.microsoft.com/v1.0/me/sendMail');

        self::assertSame(502, $response->getStatusCode(), 'a POST that may have landed was repeated');
    }

    /**
     * Throttling belongs to GraphThrottledException and the handlers that
     * requeue by hand, not here — see the strategy's docblock. Retrying it at
     * this level would spend one of the mailbox's four concurrent requests
     * arguing with a limit that has already said how long to wait.
     */
    public function testItLeavesThrottlingToTheLayerThatOwnsIt(): void
    {
        $client = $this->client([
            new MockResponse('{"error":{"code":"ApplicationThrottled"}}', ['http_code' => 429]),
            new MockResponse('{"value":[]}', ['http_code' => 200]),
        ]);

        $response = $client->request('GET', 'https://graph.microsoft.com/v1.0/me/messages');

        self::assertSame(429, $response->getStatusCode());
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function client(array $responses): RetryableHttpClient
    {
        // max_retries matches framework.yaml. Kept in step by hand: the config
        // holds the count and this holds the policy, and only the policy is
        // worth a test of its own.
        return new RetryableHttpClient(new MockHttpClient($responses), new GraphRetryStrategy(), 2);
    }
}
