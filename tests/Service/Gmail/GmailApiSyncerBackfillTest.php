<?php

declare(strict_types=1);

namespace App\Tests\Service\Gmail;

use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Message\SyncGmailMessageBatchMessage;
use App\Repository\Mail\MessageRepository;
use App\Service\Gmail\GmailApiSyncer;
use App\Service\Mail\GmailApiClient;
use App\Service\OAuth\OAuthTokenManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Backfill scheduling: when a sync run lists the mailbox again, and when it
 * stops.
 *
 * The bug behind these tests: whether the backlog had been fetched was
 * inferred from the stored historyId, which is written before the first
 * message is fetched, so an initial sync cut short by a restart was
 * indistinguishable from a finished one. A recorded target is what replaced
 * that inference, and 0 — the whole mailbox — is now its only settled value.
 *
 * GmailApiClient is final, so it is built for real against a MockHttpClient
 * rather than doubled — which has the side benefit of exercising the listing
 * and its pagination instead of stubbing them away.
 */
final class GmailApiSyncerBackfillTest extends TestCase
{
    private MessageRepository $messageRepository;

    /** A stub by default; tests that assert on dispatching swap in a mock. */
    private MessageBusInterface $bus;

    /** @var list<string> requested URLs, so a skipped listing is provable */
    private array $requests = [];

    protected function setUp(): void
    {
        $this->messageRepository = $this->createStub(MessageRepository::class);
        $this->bus               = $this->createStub(MessageBusInterface::class);
        $this->requests          = [];

        // Envelope is final, so the return value cannot be auto-generated.
        $this->bus->method('dispatch')->willReturn($this->envelope());
    }

    public function testBackfillListsWhenNoneHasCompleted(): void
    {
        $account = $this->account();
        $this->synced();

        $this->expectDispatch(self::once());

        $this->syncer(['a', 'b'])->backfill($account);

        self::assertCount(1, $this->requests);
    }

    public function testBackfillIsSkippedOnceTheWholeMailboxIsIn(): void
    {
        $account = $this->account();
        $account->backfillTarget = 0;

        $this->syncer(['a'])->backfill($account);

        self::assertSame([], $this->requests, 'settled account must not list');
    }

    public function testAnAccountLeftCappedByTheOldLimitListsAgain(): void
    {
        // The upgrade path, as a test: a run that settled at 500 because the
        // retired sync cap said so still owes everything below it, and the
        // first sync after the removal is what goes and gets it.
        $account = $this->account();
        $account->backfillTarget = 500;
        $this->synced();

        $this->expectDispatch(self::once());

        $this->syncer(['older'])->backfill($account);

        self::assertCount(1, $this->requests);
    }

    public function testBackfillWaitsOutTheCooldownWhileBatchesDrain(): void
    {
        $account = $this->account();
        $account->backfillRanAt = new \DateTimeImmutable('-5 minutes');

        $this->syncer(['a'])->backfill($account);

        self::assertSame([], $this->requests, 'a draining backfill must not re-dispatch');
    }

    public function testBackfillListsAgainOnceTheCooldownHasPassed(): void
    {
        $account = $this->account();
        $account->backfillRanAt = new \DateTimeImmutable('-2 hours');
        $this->synced();


        $this->syncer(['a'])->backfill($account);

        self::assertCount(1, $this->requests);
    }

    public function testAListingWithNothingUnfetchedCompletesTheBackfill(): void
    {
        $account = $this->account();
        $this->synced('a', 'b');

        $this->expectDispatch(self::never());

        $this->syncer(['a', 'b'])->backfill($account);

        self::assertSame(0, $account->backfillTarget);
        self::assertFalse($account->needsBackfill());
    }

    public function testAnUnfinishedBackfillCountsAttemptsInsteadOfCompleting(): void
    {
        $account = $this->account();
        $this->synced();


        $this->syncer(['a'])->backfill($account);

        self::assertNull($account->backfillTarget, 'still outstanding, so not complete');
        self::assertSame(1, $account->backfillAttempts);
    }

    public function testBackfillStopsRetryingAtTheAttemptCeiling(): void
    {
        // Messages the handler declines to store are listed forever and stored
        // never. Without a ceiling this account re-lists its mailbox hourly for
        // as long as it exists.
        $account = $this->account();
        $account->backfillAttempts = 23;
        $this->synced();


        $this->syncer(['unattributable'])->backfill($account);

        self::assertSame(0, $account->backfillTarget, 'gives up rather than looping');
        self::assertSame(0, $account->backfillAttempts);
        self::assertFalse($account->needsBackfill());
    }

    public function testDispatchedBatchesCarryOnlyUnsyncedIds(): void
    {
        $account = $this->account();
        $this->synced('have');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(
                static fn (SyncGmailMessageBatchMessage $message): bool => ['want'] === $message->gmailIds,
            ))
            ->willReturn($this->envelope());

        $this->bus = $bus;

        $this->syncer(['have', 'want'])->backfill($account);
    }

    public function testTheListingPagesThroughTheWholeMailbox(): void
    {
        // Nothing stops the walk early any more, so both pages are paid for.
        // This is the cost the retired cap existed to avoid, and now the point:
        // a mailbox is not synced until its last page has been listed.
        $account = $this->account();
        $this->synced();


        $this->syncer(['a', 'b'], pageSize: 1)->backfill($account);

        self::assertCount(2, $this->requests, 'every page must be listed');
    }

    // ── Fixture ──────────────────────────────────────────────────────────────

    /**
     * A syncer whose Gmail listing serves $ids, split into pages of $pageSize.
     *
     * @param list<string> $ids
     */
    private function syncer(array $ids, int $pageSize = 500): GmailApiSyncer
    {
        $pages = array_chunk($ids, $pageSize);
        $call  = 0;

        $client = new MockHttpClient(function (string $method, string $url) use ($pages, &$call): ResponseInterface {
            $this->requests[] = $url;

            $page = $pages[$call] ?? [];
            $last = $call >= count($pages) - 1;
            ++$call;

            return new JsonMockResponse(array_filter([
                'messages'      => array_map(
                    static fn (string $id): array => ['id' => $id, 'threadId' => 't-'.$id],
                    $page,
                ),
                'nextPageToken' => $last ? null : 'page-'.$call,
            ]));
        });

        $tokenManager = $this->createStub(OAuthTokenManager::class);
        $tokenManager->method('getValidAccessToken')->willReturn('test-token');

        return new GmailApiSyncer(
            new GmailApiClient($client, $tokenManager),
            $this->messageRepository,
            $this->createStub(EntityManagerInterface::class),
            $this->bus,
            new NullLogger(),
        );
    }

    /**
     * Swap the bus stub for a mock carrying an expectation on dispatch().
     */
    private function expectDispatch(\PHPUnit\Framework\MockObject\Rule\InvocationOrder $rule): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($rule)->method('dispatch')->willReturn($this->envelope());

        $this->bus = $bus;
    }

    private function synced(string ...$gmailIds): void
    {
        $this->messageRepository
            ->method('findSyncedGmailIdsForUser')
            ->willReturn($gmailIds);
    }

    private function envelope(): Envelope
    {
        return new Envelope(new \stdClass());
    }

    private function account(): Account
    {
        $account = new Account();
        $account->usr = new User();

        return $account;
    }
}
