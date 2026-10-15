<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Domain\Exception\GraphThrottledException;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Service\Mail\GraphApiClient;
use App\Service\OAuth\OAuthTokenManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * GraphApiClient::newestReceivedAt(), what an Outlook account is asked once
 * a minute in place of a webhook.
 *
 * Graph has no cheap "anything new?" call that leaves the delta chain alone —
 * reading a delta link would be the obvious one, and is the wrong one, because
 * it is the real sync's cursor. The newest message in the mailbox, whichever
 * folder it is in, is one projected timestamp, and a change in it is the signal.
 *
 * A timestamp and not an id, because receivedDateTime has a resolution of one
 * second and nothing promises that two messages sharing one are listed in the
 * same order twice: an id signal flips between them and queues a sync per flip.
 *
 * GraphApiClient is final, so it is built for real against a MockHttpClient.
 */
final class GraphApiClientInboxProbeTest extends TestCase
{
    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $requests = [];

    public function testItAsksForTheNewestMessageOfTheWholeMailboxAndNothingElse(): void
    {
        $client = $this->client($this->json(['value' => [['receivedDateTime' => '2026-10-10T12:00:05Z']]]));

        $client->newestReceivedAt($this->account());

        self::assertCount(1, $this->requests);

        $request = $this->requests[0];

        parse_str((string) parse_url($request['url'], PHP_URL_QUERY), $query);

        self::assertSame('GET', $request['method']);
        // Every folder, so Junk Email and the folders a rule files into count.
        // Not the inbox's: that path is what used to keep them from counting.
        self::assertStringEndsWith('/me/messages', (string) parse_url($request['url'], PHP_URL_PATH));
        self::assertSame('1', (string) $query['$top']);
        self::assertSame('receivedDateTime desc', $query['$orderby']);
        // The timestamp and no body, which is what keeps a minute-by-minute
        // call from being a minute-by-minute download.
        self::assertSame('receivedDateTime', $query['$select']);
    }

    public function testItLeavesDraftsOut(): void
    {
        // A draft being written is in /me/messages, and an edit of one may well
        // make it the newest message again and again: a sync per save. The
        // filter is the one thing that keeps it out. Graph is known to refuse
        // some $filter + $orderby pairs, so this exact pair was sent to two real
        // Outlook accounts on 2026-10-12 and answered 200 with the same newest
        // message as without it; it is pinned here because a refusal is a failed
        // look, which pauses the account for five minutes.
        $client = $this->client($this->json(['value' => []]));

        $client->newestReceivedAt($this->account());

        parse_str((string) parse_url($this->requests[0]['url'], PHP_URL_QUERY), $query);

        self::assertSame('receivedDateTime desc', $query['$orderby'], 'the question is still the newest message');
        self::assertSame('isDraft eq false', $query['$filter']);
    }

    public function testItNeverWaitsLongerThanTheSchedulerCanSpare(): void
    {
        $client = $this->client($this->json(['value' => []]));

        $client->newestReceivedAt($this->account());

        $timeout = $this->requests[0]['options']['timeout'] ?? null;

        self::assertIsFloat($timeout, 'Without a timeout a stalled connection holds the scheduler for the socket default.');
        self::assertGreaterThan(0.0, $timeout);
        self::assertLessThanOrEqual(15.0, $timeout);
    }

    public function testItReturnsWhenTheNewestMessageWasReceivedAndNotWhichMessageItIs(): void
    {
        // An id is present in the answer on purpose: returning it is the bug,
        // not a harmless alternative (see the class docblock).
        $client = $this->client($this->json(['value' => [
            ['id' => 'AAA', 'receivedDateTime' => '2026-10-10T12:00:05Z'],
            ['id' => 'BBB', 'receivedDateTime' => '2026-10-10T12:00:05Z'],
        ]]));

        self::assertSame('2026-10-10T12:00:05Z', $client->newestReceivedAt($this->account()));
    }

    public function testAnEmptyMailboxIsNull(): void
    {
        $client = $this->client($this->json(['value' => []]));

        self::assertNull($client->newestReceivedAt($this->account()));
    }

    public function testThrottlingCarriesWhatGraphSaidToWaitFor(): void
    {
        $client = $this->client(new MockResponse('{"error":{"code":"TooManyRequests"}}', [
            'http_code'        => 429,
            'response_headers' => ['content-type' => 'application/json', 'retry-after' => '90'],
        ]));

        try {
            $client->newestReceivedAt($this->account());
            self::fail('a 429 must not read as an empty inbox');
        } catch (GraphThrottledException $e) {
            self::assertSame(90, $e->getRetryAfterSeconds());
        }
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $body
     */
    private function json(array $body): MockResponse
    {
        return new MockResponse(json_encode($body, JSON_THROW_ON_ERROR), [
            'http_code'        => 200,
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }

    private function client(MockResponse $response): GraphApiClient
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($response): ResponseInterface {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return $response;
        });

        $tokenManager = $this->createStub(OAuthTokenManager::class);
        $tokenManager->method('getValidAccessToken')->willReturn('test-token');

        return new GraphApiClient($http, $tokenManager);
    }

    private function account(): Account
    {
        $account = new Account();
        $account->usr = new User();

        return $account;
    }
}
