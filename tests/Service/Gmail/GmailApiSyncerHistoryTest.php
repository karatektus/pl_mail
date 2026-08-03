<?php

declare(strict_types=1);

namespace App\Tests\Service\Gmail;

use App\Domain\Exception\GmailApiException;
use App\Domain\Exception\GmailPermanentException;
use App\Domain\Exception\GmailThrottledException;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Repository\Mail\MessageRepository;
use App\Service\Gmail\GmailApiSyncer;
use App\Service\Mail\GmailApiClient;
use App\Service\OAuth\OAuthTokenManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * When an incremental sync gives up on its cursor and re-lists the mailbox.
 *
 * The bug behind these tests: the decision was
 * str_contains($e->getMessage(), '404'). Gmail's error bodies are prose — a
 * quota message quoting a limit, a proxy's error page, a label id — so any
 * failure whose wording happened to contain those three digits threw away a
 * working historyId and re-listed the entire mailbox, on an account that was
 * only being rate-limited and would have been fine a minute later.
 *
 * GmailApiClient is final, so it is built for real against a MockHttpClient
 * rather than doubled, matching GmailApiSyncerBackfillTest.
 */
final class GmailApiSyncerHistoryTest extends TestCase
{
    /** @var list<string> requested URLs, so a re-sync is provable */
    private array $requests = [];

    protected function setUp(): void
    {
        $this->requests = [];
    }

    public function testAnExpiredHistoryIdRestartsFromScratch(): void
    {
        // 404 is what Gmail answers once startHistoryId falls off the back of
        // the history it keeps. The cursor is unrecoverable, so re-listing is
        // the only way back.
        $account = $this->account();

        $this->syncer($this->response(404, [
            'error' => ['code' => 404, 'errors' => [['reason' => 'notFound']], 'message' => 'Requested entity was not found.'],
        ]))->syncIncremental($account);

        self::assertTrue($this->profileWasFetched(), 'an expired cursor must trigger a full re-sync');
        self::assertSame('999', $account->gmailHistoryId, 'the fresh cursor replaces the dead one');
    }

    public function testAGoneHistoryIdRestartsToo(): void
    {
        $account = $this->account();

        $this->syncer($this->response(410, [
            'error' => ['code' => 410, 'message' => 'Gone'],
        ]))->syncIncremental($account);

        self::assertTrue($this->profileWasFetched());
    }

    public function testAQuotaRejectionThatMentions404KeepsTheCursor(): void
    {
        // The regression this file exists for. The wording is Gmail's own
        // phrasing for a quota rejection, and it names a status in prose;
        // matching on the text answers a rate limit by re-listing the mailbox
        // and losing a cursor that was still perfectly good.
        $account = $this->account();

        $syncer = $this->syncer($this->response(403, [
            'error' => [
                'code'    => 403,
                'errors'  => [['reason' => 'userRateLimitExceeded']],
                'message' => 'User Rate Limit Exceeded. Retry after checking https://developers.google.com/gmail/api/guides/handle-errors#code-404',
            ],
        ]));

        try {
            $syncer->syncIncremental($account);
            self::fail('a rate limit must not be mistaken for an expired cursor');
        } catch (GmailThrottledException $e) {
            self::assertSame(403, $e->getStatus());
        }

        self::assertFalse($this->profileWasFetched(), 'a rate limit must not re-list the mailbox');
        self::assertSame('12345', $account->gmailHistoryId, 'the cursor must survive');
    }

    public function testAPermissionsFailureKeepsTheCursorEvenSpelledWith404(): void
    {
        $account = $this->account();

        $syncer = $this->syncer($this->response(403, [
            'error' => [
                'code'    => 403,
                'errors'  => [['reason' => 'insufficientPermissions']],
                'message' => 'Request had insufficient authentication scopes (404 scopes granted).',
            ],
        ]));

        $this->expectException(GmailPermanentException::class);

        try {
            $syncer->syncIncremental($account);
        } finally {
            self::assertFalse($this->profileWasFetched());
            self::assertSame('12345', $account->gmailHistoryId);
        }
    }

    public function testAServerErrorPropagatesInsteadOfReListing(): void
    {
        // A 500 is transient; re-listing the whole mailbox is the most
        // expensive possible reaction to it.
        $account = $this->account();

        $syncer = $this->syncer($this->response(500, ['error' => ['code' => 500, 'message' => 'Backend Error']]));

        $this->expectException(GmailApiException::class);

        try {
            $syncer->syncIncremental($account);
        } finally {
            self::assertFalse($this->profileWasFetched());
        }
    }

    public function testAReadableHistoryAdvancesTheCursorWithoutReListing(): void
    {
        $account = $this->account();

        $this->syncer($this->response(200, ['history' => [], 'historyId' => '67890']))
            ->syncIncremental($account);

        self::assertFalse($this->profileWasFetched());
        self::assertSame('67890', $account->gmailHistoryId);
    }

    // ── Fixture ──────────────────────────────────────────────────────────────

    private function profileWasFetched(): bool
    {
        foreach ($this->requests as $url) {
            if (true === str_contains($url, '/profile')) {
                return true;
            }
        }

        return false;
    }

    /**
     * A syncer whose history.list answers $history and whose re-sync path — the
     * profile and the backlog listing — answers successfully, so the only thing
     * under test is which of the two the failure sends it down.
     */
    private function syncer(MockResponse $history): GmailApiSyncer
    {
        $client = new MockHttpClient(function (string $method, string $url) use ($history): ResponseInterface {
            $this->requests[] = $url;

            if (true === str_contains($url, '/history')) {
                return $history;
            }

            if (true === str_contains($url, '/profile')) {
                return new MockResponse(
                    json_encode(['emailAddress' => 'a@example.com', 'historyId' => '999'], JSON_THROW_ON_ERROR),
                    ['response_headers' => ['content-type' => 'application/json']],
                );
            }

            return new MockResponse(
                json_encode(['messages' => []], JSON_THROW_ON_ERROR),
                ['response_headers' => ['content-type' => 'application/json']],
            );
        });

        $tokenManager = $this->createStub(OAuthTokenManager::class);
        $tokenManager->method('getValidAccessToken')->willReturn('test-token');

        $messageRepository = $this->createStub(MessageRepository::class);
        $messageRepository->method('findSyncedGmailIdsForUser')->willReturn([]);

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturn(new Envelope(new \stdClass()));

        return new GmailApiSyncer(
            new GmailApiClient($client, $tokenManager),
            $messageRepository,
            $this->createStub(EntityManagerInterface::class),
            $bus,
            new NullLogger(),
        );
    }

    /**
     * @param array<string,mixed> $json
     */
    private function response(int $status, array $json): MockResponse
    {
        return new MockResponse(json_encode($json, JSON_THROW_ON_ERROR), [
            'http_code'        => $status,
            'response_headers' => ['content-type' => 'application/json'],
        ]);
    }

    private function account(): Account
    {
        $account = new Account();
        $account->usr = new User();
        $account->syncLimit = 500;
        $account->gmailHistoryId = '12345';

        return $account;
    }
}
