<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Domain\Exception\GmailApiException;
use App\Domain\Exception\GmailPermanentException;
use App\Domain\Exception\GmailThrottledException;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Service\Mail\GmailApiClient;
use App\Service\OAuth\OAuthTokenManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Exception\RecoverableExceptionInterface;
use Symfony\Component\Messenger\Exception\UnrecoverableExceptionInterface;

/**
 * How a Gmail failure reaches Messenger.
 *
 * The bug behind these tests: SyncAccountMessage died repeatedly on
 * `HTTP/2 403 returned for ".../labels"` and nobody could tell what the 403
 * meant, because toArray() raises before the body — the only place Google says
 * "userRateLimitExceeded" — is ever read. Gmail answers 403 rather than 429 for
 * quota, so the status genuinely cannot distinguish a rate limit from a
 * permissions failure.
 *
 * GmailApiClient is final, so it is built for real against a MockHttpClient
 * rather than doubled, matching GmailApiSyncerBackfillTest.
 */
final class GmailApiClientFailureTest extends TestCase
{
    public function testARateLimited403IsRetryableAndNamesTheReason(): void
    {
        $client = $this->client(403, [
            'error' => [
                'code'    => 403,
                'errors'  => [['reason' => 'userRateLimitExceeded', 'message' => 'User Rate Limit Exceeded']],
                'message' => 'User Rate Limit Exceeded',
            ],
        ]);

        try {
            $client->listLabels($this->account());
            self::fail('a 403 must not be swallowed');
        } catch (GmailThrottledException $e) {
            self::assertInstanceOf(RecoverableExceptionInterface::class, $e, 'Messenger must retry this');
            self::assertSame('userRateLimitExceeded', $e->getReason());
            self::assertStringContainsString('userRateLimitExceeded', $e->getMessage());
            self::assertStringContainsString('User Rate Limit Exceeded', $e->getMessage());
            self::assertStringContainsString('labels.list', $e->getMessage());
        }
    }

    public function testARateLimited403DoesNotRetryHarderThanTheTransportAllows(): void
    {
        // RecoverableExceptionInterface retries past max_retries by default,
        // which against something already rate-limiting us is the one outcome
        // worse than failing.
        $client = $this->client(403, $this->quotaBody('rateLimitExceeded'));

        try {
            $client->listLabels($this->account());
            self::fail('a 403 must not be swallowed');
        } catch (GmailThrottledException $e) {
            self::assertFalse($e->forceRetry());
        }
    }

    public function testTheBackoffIgnoresTheTransportsOneSecondBaseDelay(): void
    {
        // The async transport backs off 1s/2s/4s. A Gmail quota clears in
        // minutes, so deferring to that strategy is three more rejections.
        $client = $this->client(403, $this->quotaBody('userRateLimitExceeded'));

        try {
            $client->listLabels($this->account());
            self::fail('a 403 must not be swallowed');
        } catch (GmailThrottledException $e) {
            self::assertSame(60000, $e->getRetryDelay());
        }
    }

    public function testRetryAfterIsHonouredWhenGmailSendsOne(): void
    {
        $client = $this->client(
            403,
            $this->quotaBody('rateLimitExceeded'),
            ['retry-after' => '120'],
        );

        try {
            $client->listLabels($this->account());
            self::fail('a 403 must not be swallowed');
        } catch (GmailThrottledException $e) {
            self::assertSame(120, $e->getRetryAfterSeconds());
            self::assertSame(120000, $e->getRetryDelay());
        }
    }

    public function testAnHttpDateRetryAfterFallsBackInsteadOfBeingMisparsed(): void
    {
        $client = $this->client(
            403,
            $this->quotaBody('rateLimitExceeded'),
            ['retry-after' => 'Wed, 21 Oct 2026 07:28:00 GMT'],
        );

        try {
            $client->listLabels($this->account());
            self::fail('a 403 must not be swallowed');
        } catch (GmailThrottledException $e) {
            self::assertNull($e->getRetryAfterSeconds());
            self::assertSame(60000, $e->getRetryDelay());
        }
    }

    public function testAPermissions403IsNotRetried(): void
    {
        $client = $this->client(403, [
            'error' => [
                'code'    => 403,
                'errors'  => [['reason' => 'insufficientPermissions', 'message' => 'Insufficient Permission']],
                'message' => 'Insufficient Permission',
            ],
        ]);

        try {
            $client->listLabels($this->account());
            self::fail('a 403 must not be swallowed');
        } catch (GmailPermanentException $e) {
            self::assertInstanceOf(UnrecoverableExceptionInterface::class, $e, 'Messenger must not retry this');
            self::assertStringContainsString('insufficientPermissions', $e->getMessage());
        }
    }

    public function testTheDailyLimitIsNotRetriedEither(): void
    {
        // Nothing resets before midnight Pacific, so a retry loop only spends
        // quota the account no longer has.
        $client = $this->client(403, $this->quotaBody('dailyLimitExceeded'));

        $this->expectException(GmailPermanentException::class);

        $client->listLabels($this->account());
    }

    public function testA429IsRetryableWhateverTheBodySays(): void
    {
        $client = $this->client(429, []);

        $this->expectException(GmailThrottledException::class);

        $client->listLabels($this->account());
    }

    public function testAnEmptyBodyStillProducesAStatusRatherThanAParseError(): void
    {
        $client = $this->client(403, null, [], '');

        try {
            $client->listLabels($this->account());
            self::fail('a 403 must not be swallowed');
        } catch (GmailApiException $e) {
            self::assertNotInstanceOf(RecoverableExceptionInterface::class, $e, 'unknown reason must not force a retry');
            self::assertNotInstanceOf(UnrecoverableExceptionInterface::class, $e, 'unknown reason must not be written off');
            self::assertSame(403, $e->getStatus());
            self::assertSame('', $e->getReason());
            self::assertStringContainsString('403', $e->getMessage());
        }
    }

    public function testAnHtmlErrorPageIsReportedInsteadOfCrashing(): void
    {
        // A proxy in front of the API answers HTML, not JSON. json_decode has
        // to be allowed to fail without turning the 403 into a JsonException.
        $client = $this->client(403, null, [], '<html><body>502 Bad Gateway (edge-proxy-7)</body></html>');

        try {
            $client->listLabels($this->account());
            self::fail('a 403 must not be swallowed');
        } catch (GmailApiException $e) {
            self::assertStringContainsString('edge-proxy-7', $e->getMessage());
        }
    }

    public function testA404CarriesItsStatusAndKeepsItInTheMessage(): void
    {
        // GmailApiSyncer::syncIncremental() reads getStatus() to spot an
        // expired historyId; the text keeps the status too, because that is
        // what survives into a log line.
        $client = $this->client(404, [
            'error' => ['code' => 404, 'errors' => [['reason' => 'notFound']], 'message' => 'Requested entity was not found.'],
        ]);

        try {
            $client->listHistory($this->account(), '12345');
            self::fail('a 404 must not be swallowed');
        } catch (GmailApiException $e) {
            self::assertSame(404, $e->getStatus());
            self::assertStringContainsString('404', $e->getMessage());
        }
    }

    public function testASuccessfulListingIsUnchanged(): void
    {
        $client = $this->client(200, [
            'labels' => [
                ['id' => 'INBOX', 'name' => 'INBOX', 'type' => 'system'],
                ['id' => 'Label_7', 'name' => 'Work/Invoices', 'type' => 'user'],
            ],
        ]);

        self::assertSame(
            ['INBOX', 'Label_7'],
            array_column($client->listLabels($this->account()), 'id'),
        );
    }

    public function testDeletingAnAlreadyDeletedLabelIsNotAFailure(): void
    {
        // The caller's job is to make the label not exist. Requeueing a delete
        // that can never succeed just dead-letters it three attempts later.
        $client = $this->client(404, ['error' => ['code' => 404, 'message' => 'Not Found']]);

        $client->deleteLabel($this->account(), 'Label_7');

        $this->expectNotToPerformAssertions();
    }

    public function testAThrottledLabelDeleteIsRetryable(): void
    {
        // 204-with-no-body used to mean the response was never read at all, so
        // a rate-limited delete looked exactly like a successful one.
        $client = $this->client(403, $this->quotaBody('rateLimitExceeded'));

        $this->expectException(GmailThrottledException::class);

        $client->deleteLabel($this->account(), 'Label_7');
    }

    public function testASuccessfulLabelDeleteReadsNoBody(): void
    {
        $client = $this->client(204, null, [], '');

        $client->deleteLabel($this->account(), 'Label_7');

        $this->expectNotToPerformAssertions();
    }

    public function testAThrottledBatchModifyIsRetryable(): void
    {
        // The response was never read at all, so this call could not fail:
        // archiving a thread during a rate limit dropped the push silently.
        $client = $this->client(403, $this->quotaBody('userRateLimitExceeded'));

        try {
            $client->batchModify($this->account(), ['m1', 'm2'], ['Label_7'], ['INBOX']);
            self::fail('a rate-limited batchModify must not look successful');
        } catch (GmailThrottledException $e) {
            self::assertInstanceOf(RecoverableExceptionInterface::class, $e);
            self::assertStringContainsString('messages.batchModify', $e->getMessage());
        }
    }

    public function testAPermanentlyRefusedBatchModifyIsUnrecoverable(): void
    {
        $client = $this->client(403, [
            'error' => [
                'code'    => 403,
                'errors'  => [['reason' => 'insufficientPermissions']],
                'message' => 'Request had insufficient authentication scopes.',
            ],
        ]);

        try {
            $client->batchModify($this->account(), ['m1'], ['Label_7'], []);
            self::fail('a refused batchModify must not look successful');
        } catch (GmailPermanentException $e) {
            self::assertInstanceOf(UnrecoverableExceptionInterface::class, $e);
            self::assertSame('insufficientPermissions', $e->getReason());
        }
    }

    public function testASuccessfulBatchModifyReadsNoBody(): void
    {
        // Gmail answers 204 with nothing in it; asserting the status must not
        // turn that into a parse error.
        $client = $this->client(204, null, [], '');

        $client->batchModify($this->account(), ['m1'], ['Label_7'], ['INBOX']);

        $this->expectNotToPerformAssertions();
    }

    public function testAnEmptyBatchModifyStillCostsNothing(): void
    {
        // No ids means no request, so the failing response is never reached.
        $client = $this->client(403, $this->quotaBody('rateLimitExceeded'));

        $client->batchModify($this->account(), [], ['Label_7'], []);

        $this->expectNotToPerformAssertions();
    }

    // ── Fixture ──────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed>|null $json     decoded body, or null to send $raw verbatim
     * @param array<string,string>     $headers
     */
    private function client(int $status, ?array $json, array $headers = [], string $raw = ''): GmailApiClient
    {
        $body = null !== $json ? json_encode($json, JSON_THROW_ON_ERROR) : $raw;

        $http = new MockHttpClient(new MockResponse($body, [
            'http_code' => $status,
            'response_headers' => array_merge(['content-type' => 'application/json'], $headers),
        ]));

        $tokenManager = $this->createStub(OAuthTokenManager::class);
        $tokenManager->method('getValidAccessToken')->willReturn('test-token');

        return new GmailApiClient($http, $tokenManager);
    }

    /**
     * @return array<string,mixed>
     */
    private function quotaBody(string $reason): array
    {
        return [
            'error' => [
                'code'    => 403,
                'errors'  => [['reason' => $reason, 'message' => 'Quota exceeded']],
                'message' => 'Quota exceeded',
            ],
        ];
    }

    private function account(): Account
    {
        $account = new Account();
        $account->usr = new User();

        return $account;
    }
}
