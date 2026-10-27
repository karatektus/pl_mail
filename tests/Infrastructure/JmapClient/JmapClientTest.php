<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\JmapClient;

use App\Domain\Enum\Account\IncomingProtocol;
use App\Domain\Exception\JmapApiException;
use App\Domain\Exception\JmapResyncRequiredException;
use App\Domain\Exception\JmapThrottledException;
use App\Entity\Mail\Account;
use App\Infrastructure\JmapClient\JmapCapability;
use App\Infrastructure\JmapClient\JmapClient;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The JMAP transport: where the session is found, how it signs in, and what
 * every refusal turns into.
 *
 * The refusals are the point. A Messenger handler decides whether to retry by
 * the exception it is given, so a 429 that arrived as a plain failure would
 * spend the retry ladder on a server that asked for patience, and a
 * cannotCalculateChanges that arrived as a plain failure would retry a
 * `/changes` call that can never succeed — a sync stuck for good on an
 * account whose state had merely aged out.
 */
final class JmapClientTest extends TestCase
{
    /** @var list<array{method: string, url: string, headers: list<string>, body: string}> */
    private array $requests = [];

    protected function setUp(): void
    {
        $this->requests = [];
    }

    public function testTheSessionIsLookedForAtTheWellKnownPathOnTheIncomingHost(): void
    {
        $client = $this->client([$this->session()]);

        $client->session($this->account());

        self::assertSame('https://mail.example.test/.well-known/jmap', $this->requests[0]['url']);
    }

    /**
     * A port is part of the URL only when it is not HTTPS's own, so the
     * common case reads as the address somebody would type.
     */
    public function testANonStandardPortIsKeptInTheSessionUrl(): void
    {
        $account           = $this->account();
        $account->imapPort = 8443;

        self::assertSame('https://mail.example.test:8443/.well-known/jmap', $this->client([])->sessionUrl($account));
    }

    public function testTheOverrideWinsOverTheWellKnownPath(): void
    {
        $account                 = $this->account();
        $account->jmapSessionUrl = 'https://proxy.example.test/jmap/session';

        self::assertSame('https://proxy.example.test/jmap/session', $this->client([])->sessionUrl($account));
    }

    public function testBasicAuthCarriesTheUsernameAndPassword(): void
    {
        $client = $this->client([$this->session()]);

        $client->session($this->account());

        self::assertContains('Authorization: Basic ' . base64_encode('ada:app-password'), $this->requests[0]['headers']);
    }

    /**
     * Fastmail's shape: the token is the whole credential, and a username
     * sent alongside it would be a second, wrong one.
     */
    public function testTokenAuthSendsTheSecretAsABearerToken(): void
    {
        $account                = $this->account();
        $account->jmapTokenAuth = true;

        $this->client([$this->session()])->session($account);

        self::assertContains('Authorization: Bearer app-password', $this->requests[0]['headers']);
    }

    public function testMethodCallsAreSentToTheApiUrlWithCoreAddedToUsing(): void
    {
        $client = $this->client([
            $this->session(),
            $this->api([['Mailbox/get', ['list' => [['id' => 'inbox']]], 'm']]),
        ]);

        $result = $client->call($this->account(), [JmapCapability::MAIL], [
            ['Mailbox/get', ['accountId' => 'u1'], 'm'],
        ])->get('m', 'Mailbox/get');

        self::assertSame([['id' => 'inbox']], $result['list']);
        self::assertSame('https://mail.example.test/jmap/api/', $this->requests[1]['url']);

        $body = json_decode($this->requests[1]['body'], true);
        self::assertSame([JmapCapability::CORE, JmapCapability::MAIL], $body['using']);
    }

    /**
     * Fetched once and reused, so a sync of a hundred calls does not fetch
     * the session a hundred times.
     */
    public function testTheSessionIsFetchedOnceForASavedAccount(): void
    {
        $client  = $this->client([$this->session(), $this->api([]), $this->api([])]);
        $account = $this->saved($this->account());

        $client->call($account, [], []);
        $client->call($account, [], []);

        self::assertCount(3, $this->requests);
    }

    /**
     * RFC 8620 §3.4: a different sessionState means the session may name
     * other URLs or accounts now, and the next call must fetch it again.
     */
    public function testAChangedSessionStateDropsTheCachedSession(): void
    {
        $client  = $this->client([$this->session(), $this->api([], 'later'), $this->session(), $this->api([])]);
        $account = $this->saved($this->account());

        $client->call($account, [], []);
        $client->call($account, [], []);

        self::assertCount(4, $this->requests);
        self::assertStringEndsWith('/.well-known/jmap', $this->requests[2]['url']);
    }

    public function testRelativeSessionUrlsAreResolvedAgainstTheSessionUrl(): void
    {
        $session = $this->client([$this->session(['apiUrl' => '/jmap/api/', 'downloadUrl' => 'download/{accountId}/{blobId}/{name}?type={type}'])])
            ->session($this->account());

        self::assertSame('https://mail.example.test/jmap/api/', $session->apiUrl);
        self::assertSame('https://mail.example.test/.well-known/download/u1/B%2F1/m.eml?type=message%2Frfc822', $session->downloadUrlFor('u1', 'B/1', 'message/rfc822', 'm.eml'));
    }

    /**
     * A capability the server names no primary account for is the account's
     * that declares it — the mail account first. plMail's own server left
     * submission out of primaryAccounts, so the connection test promised JMAP
     * sending and the send went to SMTP.
     */
    public function testACapabilityWithoutAPrimaryAccountIsTheDeclaringAccounts(): void
    {
        $session = $this->client([$this->session([
            'capabilities' => [JmapCapability::CORE => [], JmapCapability::MAIL => [], JmapCapability::SUBMISSION => []],
            'accounts'     => [
                '1' => ['name' => 'other', 'accountCapabilities' => [JmapCapability::SUBMISSION => []]],
                '2' => ['name' => 'ada@example.test', 'accountCapabilities' => [JmapCapability::MAIL => [], JmapCapability::SUBMISSION => []]],
            ],
            'primaryAccounts' => [JmapCapability::MAIL => '2'],
        ])])->session($this->account());

        self::assertSame('2', $session->accountFor(JmapCapability::SUBMISSION));
        self::assertNull($session->accountFor(JmapCapability::CALENDARS), 'and none for a capability the server does not have');
    }

    public function testARefusedPasswordIsReportedAsAnAuthenticationFailure(): void
    {
        try {
            $this->client([new MockResponse('', ['http_code' => 401])])->session($this->account());
            self::fail('a 401 must throw');
        } catch (JmapApiException $refused) {
            self::assertTrue($refused->isAuthenticationFailure());
        }
    }

    /**
     * A 403 is a capability, not a password. Reading it as a bad password put
     * the sign-in-again card on accounts whose password was fine.
     */
    public function testAForbiddenRequestIsNotAnAuthenticationFailure(): void
    {
        try {
            $this->client([new MockResponse('', ['http_code' => 403])])->session($this->account());
            self::fail('a 403 must throw');
        } catch (JmapApiException $refused) {
            self::assertFalse($refused->isAuthenticationFailure());
            self::assertSame(403, $refused->getStatus());
        }
    }

    public function testATooManyRequestsAnswerIsThrottlingWithTheServersDelay(): void
    {
        try {
            $this->client([new MockResponse('', ['http_code' => 429, 'response_headers' => ['Retry-After: 12']])])
                ->session($this->account());
            self::fail('a 429 must throw');
        } catch (JmapThrottledException $throttled) {
            self::assertSame(12, $throttled->getRetryAfterSeconds());
            self::assertSame(12_000, $throttled->getRetryDelay());
        }
    }

    public function testARequestLevelProblemCarriesItsType(): void
    {
        $problem = new MockResponse(json_encode(['type' => 'urn:ietf:params:jmap:error:unknownCapability']), ['http_code' => 400]);

        try {
            $this->client([$this->session(), $problem])->call($this->account(), [JmapCapability::MAIL], []);
            self::fail('a 400 must throw');
        } catch (JmapApiException $refused) {
            self::assertSame('urn:ietf:params:jmap:error:unknownCapability', $refused->getType());
        }
    }

    public function testCannotCalculateChangesAsksForAResync(): void
    {
        $client = $this->client([
            $this->session(),
            $this->api([['error', ['type' => 'cannotCalculateChanges'], 'c']]),
        ]);

        $response = $client->call($this->account(), [JmapCapability::MAIL], [['Email/changes', [], 'c']]);

        $this->expectException(JmapResyncRequiredException::class);
        $response->get('c', 'Email/changes');
    }

    /**
     * An implicit call answers under the id of the call that caused it, so
     * the lookup is by name as well as id.
     */
    public function testAResponseIsFoundByNameAmongSeveralUnderOneCallId(): void
    {
        $client = $this->client([
            $this->session(),
            $this->api([
                ['EmailSubmission/set', ['created' => ['s' => ['id' => 'S1']]], 'send'],
                ['Email/set', ['updated' => ['E1' => null]], 'send'],
            ]),
        ]);

        $response = $client->call($this->account(), [JmapCapability::SUBMISSION], []);

        self::assertSame(['E1' => null], $response->get('send', 'Email/set')['updated']);
        self::assertSame('S1', $response->get('send', 'EmailSubmission/set')['created']['s']['id']);
    }

    public function testASessionWithoutTheCoreCapabilityIsRefused(): void
    {
        $this->expectException(JmapApiException::class);

        $this->client([$this->session(['capabilities' => [JmapCapability::MAIL => []]])])->session($this->account());
    }

    public function testUploadHandsBackTheBlobId(): void
    {
        $client = $this->client([$this->session(), new MockResponse(json_encode(['blobId' => 'G1', 'size' => 3]))]);

        self::assertSame('G1', $client->upload($this->account(), 'u1', 'abc', 'message/rfc822'));
        self::assertSame('https://mail.example.test/jmap/upload/u1/', $this->requests[1]['url']);
        self::assertSame('abc', $this->requests[1]['body']);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param list<MockResponse> $responses
     */
    private function client(array $responses): JmapClient
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): MockResponse {
            $this->requests[] = [
                'method'  => $method,
                'url'     => $url,
                'headers' => $options['headers'] ?? [],
                'body'    => is_string($options['body'] ?? null) ? $options['body'] : '',
            ];

            return array_shift($responses) ?? new MockResponse('', ['http_code' => 500]);
        });

        return new JmapClient($http);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function session(array $overrides = []): MockResponse
    {
        return new MockResponse(json_encode($overrides + [
            'capabilities'    => [JmapCapability::CORE => ['maxCallsInRequest' => 32], JmapCapability::MAIL => []],
            'accounts'        => ['u1' => ['name' => 'ada@example.test']],
            'primaryAccounts' => [JmapCapability::MAIL => 'u1'],
            'username'        => 'ada',
            'apiUrl'          => 'https://mail.example.test/jmap/api/',
            'downloadUrl'     => 'https://mail.example.test/jmap/download/{accountId}/{blobId}/{name}?accept={type}',
            'uploadUrl'       => 'https://mail.example.test/jmap/upload/{accountId}/',
            'eventSourceUrl'  => 'https://mail.example.test/jmap/eventsource/?types={types}&closeafter={closeafter}&ping={ping}',
            'state'           => 'first',
        ]));
    }

    /**
     * @param list<array{0: string, 1: array<string, mixed>, 2: string}> $invocations
     */
    private function api(array $invocations, string $sessionState = 'first'): MockResponse
    {
        return new MockResponse(json_encode(['methodResponses' => $invocations, 'sessionState' => $sessionState]));
    }

    private function account(): Account
    {
        $account                   = new Account();
        $account->email            = 'ada@example.test';
        $account->username         = 'ada';
        $account->password         = 'app-password';
        $account->authType         = 'password';
        $account->incomingProtocol = IncomingProtocol::Jmap;
        $account->imapHost         = 'mail.example.test';
        $account->imapPort         = 443;

        return $account;
    }

    private function saved(Account $account): Account
    {
        new ReflectionProperty(Account::class, 'id')->setValue($account, 7);

        return $account;
    }
}
