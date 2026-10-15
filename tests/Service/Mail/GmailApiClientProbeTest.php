<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Domain\Exception\GmailApiException;
use App\Domain\Exception\GmailThrottledException;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Service\Gmail\GmailQuotaPacer;
use App\Service\Mail\GmailApiClient;
use App\Service\OAuth\OAuthTokenManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * GmailApiClient::probeArrivals(), the every-30-seconds "is there new mail?"
 * question that stands in for push on an install nobody can reach from the
 * internet.
 *
 * Two properties carry the feature and both are asserted here rather than
 * assumed: the question is narrow (messages added, in any folder but not the
 * user's own drafts and sent copies — anything wider would wake the sync for
 * every read flag the user's own phone sets), and it
 * is cheap and bounded (one small request, a short timeout, a page cap),
 * because it runs on the scheduler's worker beside everything else scheduled.
 *
 * GmailApiClient is final, so it is built for real against a MockHttpClient.
 */
final class GmailApiClientProbeTest extends TestCase
{
    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $requests = [];

    public function testItAsksAboutMailAddedToAnyLabel(): void
    {
        $client = $this->client([$this->page([])]);

        $client->probeArrivals($this->account(), '700');

        self::assertCount(1, $this->requests);

        $request = $this->requests[0];
        $query   = $this->queryOf($request['url']);

        self::assertSame('GET', $request['method']);
        self::assertStringEndsWith('/history', (string) parse_url($request['url'], PHP_URL_PATH));
        self::assertSame('700', $query['startHistoryId']);
        // The type, and no label. The type is what keeps the question narrow:
        // without it every read flag the user's own phone sets is a change. The
        // label is what used to keep spam, and mail a filter kept out of the
        // inbox, from being an arrival at all.
        self::assertSame('messageAdded', $query['historyTypes']);
        self::assertArrayNotHasKey('labelId', $query);
    }

    public function testItNeverWaitsLongerThanTheSchedulerCanSpare(): void
    {
        $client = $this->client([$this->page([])]);

        $client->probeArrivals($this->account(), '700');

        $timeout = $this->requests[0]['options']['timeout'] ?? null;

        self::assertIsFloat($timeout, 'Without a timeout a stalled connection holds the scheduler for the socket default.');
        self::assertGreaterThan(0.0, $timeout);
        self::assertLessThanOrEqual(15.0, $timeout);
    }

    public function testNothingAddedIsNull(): void
    {
        // Gmail omits `history` altogether when there is none, which is the
        // overwhelmingly common answer.
        $client = $this->client([$this->page(null)]);

        self::assertNull($client->probeArrivals($this->account(), '700'));
    }

    public function testArrivalsAreIdentifiedByTheNewestRecord(): void
    {
        $client = $this->client([$this->page([
            ['id' => '701', 'messagesAdded' => [['message' => ['id' => 'aaa']]]],
            ['id' => '705', 'messagesAdded' => [['message' => ['id' => 'bbb']]]],
        ])]);

        // The newest, not the first: this is the value the caller remembers to
        // tell "mail it has already queued a sync for" from "more mail".
        self::assertSame('705', $client->probeArrivals($this->account(), '700'));
    }

    public function testSpamAndMailAFilterKeptOutOfTheInboxAreArrivals(): void
    {
        // Gmail files spam under SPAM and a skip-the-inbox filter under nothing
        // but UNREAD (and, for the tabs, a CATEGORY_ label). Both were invisible
        // to the probe while it asked for INBOX alone.
        $client = $this->client([
            $this->page([$this->added('701', ['SPAM', 'UNREAD'])]),
            $this->page([$this->added('702', ['CATEGORY_PROMOTIONS', 'UNREAD'])]),
        ]);

        self::assertSame('701', $client->probeArrivals($this->account(), '700'));
        self::assertSame('702', $client->probeArrivals($this->account(), '700'));
    }

    public function testADraftAndASentCopyAreNotArrivals(): void
    {
        // Gmail writes a draft as a NEW message on every save, so one being
        // typed is a messageAdded every few seconds, and the user's own sends
        // are one each. Neither is mail that came in.
        $client = $this->client([
            $this->page([$this->added('701', ['DRAFT']), $this->added('702', ['SENT'])]),
            // The presence companion: the same two, with real mail beside them.
            $this->page([$this->added('701', ['DRAFT']), $this->added('702', ['SENT']), $this->added('703', ['SPAM', 'UNREAD'])]),
        ]);

        self::assertNull($client->probeArrivals($this->account(), '700'), 'nothing here came in');
        self::assertSame('703', $client->probeArrivals($this->account(), '700'), 'and the mail beside them did');
    }

    public function testTheSignalIsTheNewestRecordThatCameInNotTheNewestRecord(): void
    {
        // The caller remembers this value to tell "already announced" from
        // "more mail". A draft saved after the last arrival must not move it,
        // or every save would be announced as a new arrival.
        $client = $this->client([$this->page([$this->added('701', ['INBOX', 'UNREAD']), $this->added('709', ['DRAFT'])])]);

        self::assertSame('701', $client->probeArrivals($this->account(), '700'));
    }

    public function testAMessageSentToYourselfIsAnArrival(): void
    {
        // SENT and INBOX together: the copy in the sent folder is not news, the
        // one in the inbox is.
        $client = $this->client([$this->page([$this->added('701', ['SENT', 'INBOX', 'UNREAD'])])]);

        self::assertSame('701', $client->probeArrivals($this->account(), '700'));
    }

    public function testARecordWithOneMessageThatCameInIsAnArrival(): void
    {
        $client = $this->client([$this->page([
            ['id' => '701', 'messagesAdded' => [
                ['message' => ['id' => 'a', 'labelIds' => ['DRAFT']]],
                ['message' => ['id' => 'b', 'labelIds' => ['INBOX']]],
            ]],
        ])]);

        self::assertSame('701', $client->probeArrivals($this->account(), '700'));
    }

    public function testAnAdditionThatNamesNoLabelsIsAnArrival(): void
    {
        // "Could not rule it out" is a reason to let the real sync look, the
        // same call the page cap makes below.
        $client = $this->client([$this->page([['id' => '701', 'messagesAdded' => [['message' => ['id' => 'a']]]]])]);

        self::assertSame('701', $client->probeArrivals($this->account(), '700'));
    }

    public function testARecordThatListsNoMessagesIsAnArrival(): void
    {
        // Only a record that SAYS every message it added was a draft or a sent
        // copy is left out. One that says nothing is not ruled out.
        $client = $this->client([$this->page([['id' => '701']])]);

        self::assertSame('701', $client->probeArrivals($this->account(), '700'));
    }

    public function testAnEmptyPageWithAMoreTokenIsFollowed(): void
    {
        $client = $this->client([
            $this->page(null, nextPageToken: 'next-1'),
            $this->page([['id' => '710', 'messagesAdded' => [['message' => ['id' => 'ccc']]]]]),
        ]);

        self::assertSame('710', $client->probeArrivals($this->account(), '700'));
        self::assertCount(2, $this->requests);
        self::assertSame('next-1', $this->queryOf($this->requests[1]['url'])['pageToken']);
    }

    public function testItStopsFollowingPagesAndStillReportsAnArrival(): void
    {
        // A page that is always empty and always has a successor. Bounded,
        // because an unbounded loop here is the scheduler's worker spinning on
        // Google; and reported as an arrival, because the safe answer to "I
        // could not rule it out" is to let the real sync look.
        $client = $this->client(array_fill(0, 20, $this->page(null, nextPageToken: 'again')));

        $newest = $client->probeArrivals($this->account(), '700');

        self::assertNotNull($newest);
        self::assertCount(5, $this->requests);
    }

    public function testAQuotaRejectionIsThrottling(): void
    {
        $client = $this->client([
            new MockResponse(
                json_encode(['error' => ['code' => 403, 'errors' => [['reason' => 'userRateLimitExceeded']]]], JSON_THROW_ON_ERROR),
                ['http_code' => 403, 'response_headers' => ['content-type' => 'application/json', 'retry-after' => '120']],
            ),
        ]);

        try {
            $client->probeArrivals($this->account(), '700');
            self::fail('a rejection must not read as "nothing new"');
        } catch (GmailThrottledException $e) {
            self::assertSame(120, $e->getRetryAfterSeconds());
        }
    }

    public function testAnExpiredCursorKeepsItsStatus(): void
    {
        $client = $this->client([
            new MockResponse('{"error":{"code":404}}', ['http_code' => 404, 'response_headers' => ['content-type' => 'application/json']]),
        ]);

        try {
            $client->probeArrivals($this->account(), '700');
            self::fail('a 404 must not read as "nothing new"');
        } catch (GmailApiException $e) {
            // The caller tells an expired cursor from an outage by this.
            self::assertSame(404, $e->getStatus());
        }
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /**
     * One history record that added one message with these labels.
     *
     * @param list<string> $labelIds
     *
     * @return array<string, mixed>
     */
    private function added(string $recordId, array $labelIds): array
    {
        return ['id' => $recordId, 'messagesAdded' => [['message' => ['id' => 'm-' . $recordId, 'labelIds' => $labelIds]]]];
    }

    /**
     * @param list<array<string, mixed>>|null $history
     */
    private function page(?array $history, ?string $nextPageToken = null): MockResponse
    {
        $body = ['historyId' => '999'];

        if (null !== $history) {
            $body['history'] = $history;
        }

        if (null !== $nextPageToken) {
            $body['nextPageToken'] = $nextPageToken;
        }

        return new MockResponse(json_encode($body, JSON_THROW_ON_ERROR), [
            'http_code'        => 200,
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }

    /**
     * @param list<ResponseInterface> $responses answered in order
     */
    private function client(array $responses): GmailApiClient
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$responses): ResponseInterface {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            $next = array_shift($responses);

            if (null === $next) {
                self::fail('The probe asked for more pages than the test scripted.');
            }

            return $next;
        });

        $tokenManager = $this->createStub(OAuthTokenManager::class);
        $tokenManager->method('getValidAccessToken')->willReturn('test-token');

        return new GmailApiClient($http, $tokenManager, new GmailQuotaPacer(new MockClock()));
    }

    /**
     * @return array<string, string>
     */
    private function queryOf(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return array_map(strval(...), $query);
    }

    private function account(): Account
    {
        $account = new Account();
        $account->usr = new User();

        return $account;
    }
}
