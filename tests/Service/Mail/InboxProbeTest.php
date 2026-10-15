<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Account\MailProvider;
use App\Domain\Exception\OAuthGrantRevokedException;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Service\Gmail\GmailQuotaPacer;
use App\Service\Mail\GmailApiClient;
use App\Service\Mail\GraphApiClient;
use App\Service\Mail\InboxProbe;
use App\Service\OAuth\OAuthTokenManager;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Whether the periodic look at an OAuth account has found mail worth a
 * sync — and, as much, when it must say "no" or must not look at all.
 *
 * With no public address there is no push, so this stands in for it. A stand-in
 * that runs every 30 seconds has one way to do real damage, and it is the one this
 * codebase has already paid for once (ImapIdleCommand::SYNC_MIN_GAP): a signal
 * that stays true until the sync it triggered has finished queues another sync
 * on the next tick, and the next. Gmail's cursor only moves when the real sync
 * runs, which sits behind a single shared queue worker, and an Outlook signal
 * that can flicker is announced as often as it does. So most of the tests below
 * are about the same arrival being announced once.
 *
 * Both API clients are final and are built for real against one MockHttpClient
 * that routes by host; the clock is a MockClock so a back-off can be waited out
 * without waiting.
 */
final class InboxProbeTest extends TestCase
{
    private const string GMAIL_HOST = 'gmail.googleapis.com';
    private const string GRAPH_HOST = 'graph.microsoft.com';

    /** Two moments a second apart, as Graph spells a receivedDateTime. */
    private const string T1 = '2026-10-10T12:00:05Z';
    private const string T2 = '2026-10-10T12:00:06Z';

    /** @var array<string, list<ResponseInterface>> host => answers, in order */
    private array $script = [];

    /** @var list<string> the host and bearer token of every request made, in order */
    private array $requests = [];

    private MockClock $clock;

    private InboxProbe $probe;

    protected function setUp(): void
    {
        $this->script   = [self::GMAIL_HOST => [], self::GRAPH_HOST => []];
        $this->requests = [];
        $this->clock    = new MockClock('2026-10-10 12:00:00');
        $this->probe    = $this->probe();
    }

    // ── Gmail ─────────────────────────────────────────────────────────────

    public function testAGmailAccountWithoutACursorIsLeftToTheScheduledSync(): void
    {
        // No historyId means the first sync has not finished, and that sync is
        // not this class's to start.
        self::assertFalse($this->probe->shouldSync($this->gmail(1, historyId: null)));
        self::assertSame([], $this->requests);
    }

    public function testNothingNewIsNoSync(): void
    {
        $this->script[self::GMAIL_HOST][] = $this->gmailQuiet();

        self::assertFalse($this->probe->shouldSync($this->gmail(1)));
        self::assertCount(1, $this->requests, 'It did look; there was nothing there.');
    }

    public function testNewInboxMailIsASync(): void
    {
        $this->script[self::GMAIL_HOST][] = $this->gmailArrival('705');

        self::assertTrue($this->probe->shouldSync($this->gmail(1)));
    }

    public function testTheSameArrivalIsAnnouncedOnce(): void
    {
        // The cursor only moves when the queued sync has actually run, and the
        // queue can be minutes deep. Until then the next tick sees the very
        // same arrival, and must not queue another sync for it.
        $account = $this->gmail(1);

        $this->script[self::GMAIL_HOST] = [$this->gmailArrival('705'), $this->gmailArrival('705'), $this->gmailArrival('705')];

        self::assertTrue($this->probe->shouldSync($account), 'the first sight is news');
        self::assertFalse($this->probe->shouldSync($account), 'the second is the same news');
        self::assertFalse($this->probe->shouldSync($account), 'and so is the third');
    }

    public function testLaterMailIsAnotherSync(): void
    {
        $account = $this->gmail(1);

        $this->script[self::GMAIL_HOST] = [$this->gmailArrival('705'), $this->gmailArrival('712')];

        self::assertTrue($this->probe->shouldSync($account));
        self::assertTrue($this->probe->shouldSync($account), 'mail that arrived while the first sync waited is new');
    }

    public function testSpamIsASyncToo(): void
    {
        // It used to wait for the quarter-hourly sweep: the probe asked about the
        // inbox, and Gmail files spam under SPAM.
        $this->script[self::GMAIL_HOST][] = $this->gmailArrival('705', ['SPAM', 'UNREAD']);

        self::assertTrue($this->probe->shouldSync($this->gmail(1)));
    }

    public function testADraftBeingWrittenIsNeverASync(): void
    {
        // Each save of a Gmail draft is a new message with a new history record.
        // Announced, that is a full sync every few seconds for as long as
        // somebody types.
        $account = $this->gmail(1);

        $this->script[self::GMAIL_HOST] = [
            $this->gmailArrival('705', ['DRAFT']),
            $this->gmailArrival('706', ['DRAFT']),
            // The presence companion: the same account does announce mail.
            $this->gmailArrival('707', ['SPAM', 'UNREAD']),
        ];

        self::assertFalse($this->probe->shouldSync($account), 'a draft');
        self::assertFalse($this->probe->shouldSync($account), 'and the next save of it');
        self::assertTrue($this->probe->shouldSync($account), 'but mail is mail');
    }

    public function testTheCursorBelongsToTheRealSyncAndIsNeverMoved(): void
    {
        // If the probe advanced it, the sync it just asked for would find the
        // window empty and fetch nothing.
        $account = $this->gmail(1, historyId: '700');

        $this->script[self::GMAIL_HOST][] = $this->gmailArrival('705');

        self::assertTrue($this->probe->shouldSync($account));
        self::assertSame('700', $account->gmailHistoryId);
    }

    public function testAnExpiredCursorIsOneSyncToRecoverItAndThenQuiet(): void
    {
        // Recovery is the real sync's job (GmailApiSyncer::syncIncremental
        // answers 404 by re-listing the mailbox). The probe's only part is to
        // start it — once, and not on every tick until it has run.
        $account = $this->gmail(1);

        $this->script[self::GMAIL_HOST] = [$this->httpStatus(404), $this->httpStatus(404)];

        self::assertTrue($this->probe->shouldSync($account));
        self::assertFalse($this->probe->shouldSync($account));
    }

    public function testThrottlingPausesTheAccountForAsLongAsGoogleAsked(): void
    {
        $account = $this->gmail(1);

        $this->script[self::GMAIL_HOST] = [$this->throttled(retryAfter: 120), $this->gmailArrival('705')];

        self::assertFalse($this->probe->shouldSync($account), 'a refusal is not news');
        self::assertCount(1, $this->requests);

        $this->clock->sleep(119);
        self::assertFalse($this->probe->shouldSync($account));
        self::assertCount(1, $this->requests, 'Asking again inside the window is what a rate limit punishes.');

        $this->clock->sleep(2);
        self::assertTrue($this->probe->shouldSync($account), 'and the window does end');
        self::assertCount(2, $this->requests);
    }

    public function testAnyOtherFailurePausesTheAccountForFiveMinutes(): void
    {
        $account = $this->gmail(1);

        $this->script[self::GMAIL_HOST] = [$this->httpStatus(500), $this->gmailArrival('705')];

        self::assertFalse($this->probe->shouldSync($account));

        $this->clock->sleep(299);
        self::assertFalse($this->probe->shouldSync($account));
        self::assertCount(1, $this->requests);

        $this->clock->sleep(2);
        self::assertTrue($this->probe->shouldSync($account));
        self::assertCount(2, $this->requests);
    }

    public function testARevokedGrantPausesTheAccountToo(): void
    {
        // The token manager has already recorded the revocation on the account;
        // what is left for the probe is not to try again a minute later, and a
        // minute after that, against an identity endpoint.
        $probe = $this->probe(tokenFailure: new OAuthGrantRevokedException('revoked'));

        self::assertFalse($probe->shouldSync($this->gmail(1)));
        self::assertFalse($probe->shouldSync($this->gmail(1)));
        self::assertSame([], $this->requests, 'There was never a token to send a request with.');
    }

    public function testOneAccountBackingOffDoesNotPauseAnother(): void
    {
        $this->script[self::GMAIL_HOST] = [$this->throttled(retryAfter: 600), $this->gmailArrival('705')];

        self::assertFalse($this->probe->shouldSync($this->gmail(1)));
        self::assertTrue($this->probe->shouldSync($this->gmail(2)));
    }

    public function testTwoAccountsKeepTheirOwnMemoryOfWhatTheyHaveAnnounced(): void
    {
        $this->script[self::GMAIL_HOST] = [$this->gmailArrival('705'), $this->gmailArrival('705')];

        self::assertTrue($this->probe->shouldSync($this->gmail(1)));
        self::assertTrue($this->probe->shouldSync($this->gmail(2)), 'the same id on another account is not the same news');
    }

    // ── Microsoft ─────────────────────────────────────────────────────────

    public function testAGraphAccountThatHasNeverSyncedIsLeftToTheScheduledSync(): void
    {
        self::assertFalse($this->probe->shouldSync($this->graph(10, synced: false)));
        self::assertSame([], $this->requests);
    }

    public function testTheFirstLookOnlyRemembersWhatIsThere(): void
    {
        // Nothing here says the newest message is unfamiliar — there is no
        // earlier look to compare it with — and a sync per account at every
        // restart would be noise.
        $this->script[self::GRAPH_HOST][] = $this->graphNewest(self::T1);

        self::assertFalse($this->probe->shouldSync($this->graph(10)));
    }

    public function testAnUnchangedNewestMessageIsNoSync(): void
    {
        $account = $this->graph(10);

        $this->script[self::GRAPH_HOST] = [$this->graphNewest(self::T1), $this->graphNewest(self::T1)];

        self::assertFalse($this->probe->shouldSync($account));
        self::assertFalse($this->probe->shouldSync($account));
        self::assertCount(2, $this->requests);
    }

    public function testANewerMessageIsASyncAnnouncedOnce(): void
    {
        $account = $this->graph(10);

        $this->script[self::GRAPH_HOST] = [
            $this->graphNewest(self::T1),
            $this->graphNewest(self::T2),
            $this->graphNewest(self::T2),
        ];

        self::assertFalse($this->probe->shouldSync($account), 'remembered');
        self::assertTrue($this->probe->shouldSync($account), 'a later newest message is mail');
        self::assertFalse($this->probe->shouldSync($account), 'and a sync is already queued for it');
    }

    /**
     * The regression this signal was changed for. Graph's receivedDateTime has
     * a resolution of one second, so a burst of mail — three messages at once —
     * puts several under one value, and nothing promises that the query lists
     * them in the same order twice. Keyed by the newest message's id the probe
     * flipped A, B, A, B and queued a full sync per flip until something newer
     * arrived: the sync loop this class exists not to cause.
     */
    public function testTwoMessagesReceivedInOneSecondDoNotMakeTheSignalFlicker(): void
    {
        $account = $this->graph(10);

        $this->script[self::GRAPH_HOST] = [
            $this->graphNewest(self::T1, id: 'A'),
            $this->graphNewest(self::T1, id: 'B'),
            $this->graphNewest(self::T1, id: 'A'),
            $this->graphNewest(self::T1, id: 'B'),
            // The presence companion: the same account does announce real mail.
            $this->graphNewest(self::T2, id: 'C'),
        ];

        self::assertFalse($this->probe->shouldSync($account), 'remembered');
        self::assertFalse($this->probe->shouldSync($account), 'same second, different id: not news');
        self::assertFalse($this->probe->shouldSync($account));
        self::assertFalse($this->probe->shouldSync($account));
        self::assertTrue($this->probe->shouldSync($account), 'a later second is news');
    }

    public function testAnEmptyMailboxIsNoSync(): void
    {
        $this->script[self::GRAPH_HOST][] = $this->graphNewest(null);

        self::assertFalse($this->probe->shouldSync($this->graph(10)));
    }

    public function testGraphThrottlingPausesTheAccountForAsLongAsMicrosoftAsked(): void
    {
        $account = $this->graph(10);

        $this->script[self::GRAPH_HOST] = [
            $this->throttled(retryAfter: 90, status: 429),
            $this->graphNewest(self::T1),
        ];

        self::assertFalse($this->probe->shouldSync($account));

        $this->clock->sleep(89);
        self::assertFalse($this->probe->shouldSync($account));
        self::assertCount(1, $this->requests);

        $this->clock->sleep(2);
        self::assertFalse($this->probe->shouldSync($account), 'it looks again (and only remembers: first sight)');
        self::assertCount(2, $this->requests);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function probe(?\Throwable $tokenFailure = null): InboxProbe
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options): ResponseInterface {
            $host = (string) parse_url($url, PHP_URL_HOST);

            // MockHttpClient has already folded auth_bearer into the headers.
            $this->requests[] = $host . ' ' . str_replace('Authorization: Bearer ', '', (string) ($options['normalized_headers']['authorization'][0] ?? ''));

            $next = array_shift($this->script[$host]);

            if (null === $next) {
                self::fail('Unscripted request to ' . $host . ': the probe asked more often than the test allows for.');
            }

            return $next;
        });

        $tokenManager = $this->createStub(OAuthTokenManager::class);

        if (null !== $tokenFailure) {
            $tokenManager->method('getValidAccessToken')->willThrowException($tokenFailure);
        } else {
            $tokenManager->method('getValidAccessToken')->willReturnCallback(
                static fn (Account $account): string => 'token-' . $account->id,
            );
        }

        return new InboxProbe(
            new GmailApiClient($http, $tokenManager, new GmailQuotaPacer($this->clock)),
            new GraphApiClient($http, $tokenManager),
            new ArrayAdapter(),
            $this->clock,
            new NullLogger(),
        );
    }

    private function gmail(int $id, ?string $historyId = '700'): Account
    {
        $account = $this->account($id);

        $account->oauthProvider = MailProvider::Google->value;
        $account->gmailHistoryId = $historyId;

        return $account;
    }

    private function graph(int $id, bool $synced = true): Account
    {
        $account = $this->account($id);

        $account->oauthProvider = MailProvider::Microsoft->value;
        $account->lastSyncedAt  = true === $synced ? new DateTimeImmutable('2026-10-10 11:45:00') : null;

        return $account;
    }

    private function account(int $id): Account
    {
        $account = new Account();
        $account->usr      = new User();
        $account->authType = AuthType::OAuth2->value;

        // Doctrine's to assign, and the probe keys everything it remembers by it.
        (new \ReflectionProperty(Account::class, 'id'))->setValue($account, $id);

        return $account;
    }

    /**
     * @param list<string>|null $labelIds null leaves the key out, as an older
     *                                    fixture here did: not ruled out, so an arrival
     */
    private function gmailArrival(string $newestRecordId, ?array $labelIds = null): MockResponse
    {
        $message = ['id' => 'm-' . $newestRecordId];

        if (null !== $labelIds) {
            $message['labelIds'] = $labelIds;
        }

        return $this->json(['historyId' => '999', 'history' => [
            ['id' => $newestRecordId, 'messagesAdded' => [['message' => $message]]],
        ]]);
    }

    private function gmailQuiet(): MockResponse
    {
        return $this->json(['historyId' => '999']);
    }

    /**
     * What Graph answers for "the newest message in the mailbox", with the id it
     * happens to list first: the probe must not care which.
     */
    private function graphNewest(?string $receivedAt, string $id = 'x'): MockResponse
    {
        return $this->json(['value' => null === $receivedAt ? [] : [['id' => $id, 'receivedDateTime' => $receivedAt]]]);
    }

    private function throttled(int $retryAfter, int $status = 403): MockResponse
    {
        return new MockResponse(
            json_encode(['error' => ['code' => $status, 'errors' => [['reason' => 'rateLimitExceeded']]]], JSON_THROW_ON_ERROR),
            ['http_code' => $status, 'response_headers' => ['content-type' => 'application/json', 'retry-after' => (string) $retryAfter]],
        );
    }

    private function httpStatus(int $status): MockResponse
    {
        return new MockResponse('{"error":{"code":' . $status . '}}', [
            'http_code'        => $status,
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }

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
}
