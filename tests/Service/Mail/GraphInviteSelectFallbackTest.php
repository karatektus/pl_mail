<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Entity\Mail\Account;
use App\Service\Mail\GraphApiClient;
use App\Service\OAuth\OAuthTokenManager;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * The property that can take a whole mailbox off the air.
 *
 * meetingMessageType is declared on Graph's event-message type rather than on
 * the base message. Named unqualified it is not ignored: the $select is
 * rejected before any id is looked at, so every sub-request in the batch
 * answers 400 and the account fetches nothing, indefinitely. That is what it
 * did, and the log said only "status: 400".
 *
 * Some mailboxes — the ones still served by the older Outlook broker, which is
 * where this was found — refuse even the cast. So the client retries once
 * without the flag and remembers, and this is the test for that path, because
 * production is otherwise the only place it ever runs.
 */
final class GraphInviteSelectFallbackTest extends TestCase
{
    /** @var list<string> the $select of every sub-request, in order */
    private array $selects = [];

    protected function setUp(): void
    {
        $this->selects = [];
    }

    public function testTheInviteFlagIsAskedForThroughItsOwnType(): void
    {
        $client = $this->client([$this->batch([$this->ok('AAA')])]);

        $client->batchGetMessages($this->account(), ['AAA']);

        self::assertCount(1, $this->selects);
        self::assertStringContainsString(
            'microsoft.graph.eventMessage/meetingMessageType',
            $this->selects[0],
        );
    }

    /**
     * A mailbox that refuses it must still sync. The retry is what turns "this
     * account is broken forever" into one wasted batch.
     */
    public function testAMailboxThatRefusesItIsRetriedWithoutIt(): void
    {
        $client = $this->client([
            $this->batch([$this->rejectsInviteSelect('AAA')]),
            $this->batch([$this->ok('AAA')]),
        ]);

        $result = $client->batchGetMessages($this->account(), ['AAA']);

        self::assertCount(2, $this->selects, 'the batch should have been retried');
        self::assertStringNotContainsString('meetingMessageType', $this->selects[1]);

        // And the caller gets the message, rather than a failure it would log
        // and drop.
        self::assertCount(1, $result['messages']);
        self::assertSame([], $result['failed']);
    }

    /**
     * Remembered per mailbox, so the cost is one batch rather than one per
     * batch — an account with ten thousand messages would otherwise pay the
     * doubled round trip five hundred times.
     */
    public function testTheRefusalIsRememberedForTheNextBatch(): void
    {
        $client = $this->client([
            $this->batch([$this->rejectsInviteSelect('AAA')]),
            $this->batch([$this->ok('AAA')]),
            $this->batch([$this->ok('BBB')]),
        ]);

        $account = $this->account();

        $client->batchGetMessages($account, ['AAA']);
        $client->batchGetMessages($account, ['BBB']);

        self::assertCount(3, $this->selects);
        self::assertStringNotContainsString('meetingMessageType', $this->selects[2]);
    }

    /**
     * A 400 about something else is a fact about that message, not about the
     * mailbox. Retrying the batch for it would hide a real failure behind a
     * second identical one — and would drop the invite flag for every later
     * message on an account that was serving it perfectly well.
     */
    public function testAnUnrelated400IsNotRetried(): void
    {
        $client = $this->client([
            $this->batch([[
                'id'     => '0',
                'status' => 400,
                'body'   => ['error' => ['code' => 'ErrorInvalidIdMalformed', 'message' => 'Id is malformed.']],
            ]]),
        ]);

        $result = $client->batchGetMessages($this->account(), ['AAA']);

        self::assertCount(1, $this->selects);
        self::assertSame(['AAA' => 400], $result['failed']);
        self::assertStringContainsString('ErrorInvalidIdMalformed', $result['errors']['AAA']);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    /** @param list<MockResponse> $responses */
    private function client(array $responses): GraphApiClient
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): ResponseInterface {
            $body = json_decode((string) ($options['body'] ?? '{}'), true);

            foreach ($body['requests'] ?? [] as $request) {
                $query = parse_url((string) $request['url'], PHP_URL_QUERY) ?? '';
                parse_str($query, $parsed);

                $this->selects[] = (string) ($parsed['$select'] ?? '');
            }

            return array_shift($responses) ?? new MockResponse('{}');
        });

        return new GraphApiClient($http, $this->tokenManager());
    }

    /** @param list<array<string,mixed>> $subResponses */
    private function batch(array $subResponses): MockResponse
    {
        return new MockResponse(
            json_encode(['responses' => $subResponses], JSON_THROW_ON_ERROR),
            ['response_headers' => ['content-type' => 'application/json']],
        );
    }

    /** @return array<string,mixed> */
    private function ok(string $id): array
    {
        return [
            'id'     => '0',
            'status' => 200,
            'body'   => ['id' => $id, 'internetMessageId' => '<' . $id . '@example.test>'],
        ];
    }

    /**
     * Graph's actual wording, which is the only thing distinguishing this 400
     * from any other.
     *
     * @return array<string,mixed>
     */
    private function rejectsInviteSelect(string $id): array
    {
        return [
            'id'     => '0',
            'status' => 400,
            'body'   => ['error' => [
                'code'    => 'RequestBroker--ParseUri',
                'message' => "Could not find a property named 'meetingMessageType' on type "
                    . "'Microsoft.OutlookServices.Message'.",
            ]],
        ];
    }

    private function account(): Account
    {
        $account                   = new Account();
        $account->email            = 'graph@example.test';
        $account->authType         = 'oauth';
        $account->oauthProvider    = 'microsoft';
        $account->oauthAccessToken = 'test-access-token';
        $account->oauthTokenExpiry = new DateTimeImmutable('+1 day');

        return $account;
    }

    /**
     * A stub, not a mock: nothing here is about how the token is obtained, and
     * a mock with no expectations is one PHPUnit rightly complains about.
     */
    private function tokenManager(): OAuthTokenManager
    {
        return $this->createStub(OAuthTokenManager::class);
    }
}
