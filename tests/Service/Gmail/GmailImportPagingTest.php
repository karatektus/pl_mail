<?php

declare(strict_types=1);

namespace App\Tests\Service\Gmail;

use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Message\SyncGmailMessageBatchMessage;
use App\Repository\Mail\MessageRepository;
use App\Service\Gmail\GmailApiSyncer;
use App\Service\Gmail\GmailBackfillStep;
use App\Service\Gmail\GmailQuotaPacer;
use App\Service\Mail\GmailApiClient;
use App\Service\Mail\MessageEraser;
use App\Service\Mail\SyncOrigin;
use App\Service\OAuth\OAuthTokenManager;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * A Gmail mailbox is listed a page at a time, the Inbox first, off the queue
 * new mail arrives on.
 *
 * The first thing a new Gmail account did was list every message id it had —
 * forty requests for twenty thousand messages, in one job, before fetching
 * any — and then queue all of it in front of whatever arrived next (#42).
 * backfillPage() is one request and what follows from it; these pin that it
 * stops after one, remembers where it was, and sends its batches to the
 * import queue.
 *
 * GmailApiSyncerBackfillTest covers what a whole listing concludes — the
 * cooldown, the attempts, the settling — through backfill(), which is these
 * pages run to the end.
 */
final class GmailImportPagingTest extends TestCase
{
    /** @var list<string> every URL asked for, in order */
    private array $requests = [];

    /** @var list<Envelope> every dispatch, with its stamps */
    private array $dispatched = [];

    public function testOnePageIsOneRequestAndLeavesTheTokenForTheNext(): void
    {
        $account = $this->account();

        $step = $this->syncer([['a', 'b'], ['c']])->backfillPage($account);

        self::assertSame(GmailBackfillStep::More, $step);
        self::assertCount(1, $this->requests, 'the second page is another job\'s');
        self::assertSame('page-1', $account->backfillPageToken);
        self::assertSame(2, $account->backfillPending);
        self::assertTrue($account->needsBackfill(), 'a listing in the middle of itself has concluded nothing');
    }

    public function testTheLastPageSettlesTheListingAndClearsTheToken(): void
    {
        $account = $this->account();
        $syncer  = $this->syncer([['a'], ['b']]);

        $syncer->backfillPage($account);
        $step = $syncer->backfillPage($account);

        self::assertStringContainsString('pageToken=page-1', $this->requests[1]);
        self::assertNull($account->backfillPageToken);
        self::assertSame(0, $account->backfillPending);

        // Two messages were found unfetched, so the mailbox is not in yet: the
        // next listing waits out its hour.
        self::assertSame(GmailBackfillStep::Waiting, $step);
        self::assertSame(1, $account->backfillAttempts);
    }

    public function testAListingThatFindsNothingNewFinishesTheImport(): void
    {
        $account = $this->account();

        $step = $this->syncer([['a']], synced: ['a'])->backfillPage($account);

        self::assertSame(GmailBackfillStep::Finished, $step);
        self::assertFalse($account->needsBackfill());
    }

    /** History goes to the import queue and its worker; see messenger.yaml. */
    public function testAnImportsBatchesAreSentToTheImportQueue(): void
    {
        $this->syncer([['a', 'b']])->backfillPage($this->account());

        self::assertCount(1, $this->dispatched);
        self::assertInstanceOf(SyncGmailMessageBatchMessage::class, $this->dispatched[0]->getMessage());
        self::assertSame(
            [GmailApiSyncer::IMPORT_QUEUE],
            $this->dispatched[0]->last(TransportNamesStamp::class)?->getTransportNames(),
        );
    }

    /**
     * The contrast: the one caller that still lists a whole mailbox in a go —
     * the recovery from an expired history cursor — keeps the queue it had.
     */
    public function testAWholeListingInOneGoStaysOnTheLiveQueue(): void
    {
        $this->syncer([['a'], ['b']])->backfill($this->account());

        self::assertCount(2, $this->requests);
        self::assertCount(2, $this->dispatched);
        self::assertNull($this->dispatched[0]->last(TransportNamesStamp::class));
    }

    /**
     * The whole-mailbox listing is newest first with every label mixed. One
     * request asks for the Inbox by name, so its newest mail is queued ahead
     * of a morning's promotions.
     */
    public function testTheInboxIsAskedForByNameAheadOfTheMailboxAtLarge(): void
    {
        $this->syncer([['inbox-1', 'inbox-2']])->importInboxFirst($this->account());

        self::assertCount(1, $this->requests);
        self::assertStringContainsString('labelIds=INBOX', $this->requests[0]);
        self::assertStringContainsString('maxResults=200', $this->requests[0]);

        self::assertSame(['inbox-1', 'inbox-2'], $this->dispatched[0]->getMessage()->gmailIds);
        self::assertSame(
            [GmailApiSyncer::IMPORT_QUEUE],
            $this->dispatched[0]->last(TransportNamesStamp::class)?->getTransportNames(),
        );
    }

    // ── Fixture ───────────────────────────────────────────────────────────

    /**
     * @param list<list<string>> $pages  what each listing request answers with, in order
     * @param list<string>       $synced ids that are stored already
     */
    private function syncer(array $pages, array $synced = []): GmailApiSyncer
    {
        $call = 0;

        $http = new MockHttpClient(function (string $method, string $url) use ($pages, &$call): ResponseInterface {
            $this->requests[] = $url;

            $page = $pages[$call] ?? [];
            $last = $call >= count($pages) - 1;
            ++$call;

            return new JsonMockResponse(array_filter([
                'messages'      => array_map(static fn (string $id): array => ['id' => $id, 'threadId' => 't-' . $id], $page),
                'nextPageToken' => true === $last ? null : sprintf('page-%d', $call),
            ]));
        });

        $tokens = $this->createStub(OAuthTokenManager::class);
        $tokens->method('getValidAccessToken')->willReturn('test-token');

        $messages = $this->createStub(MessageRepository::class);
        $messages->method('findSyncedGmailIdsForUser')->willReturn($synced);

        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(function (object $message, array $stamps = []): Envelope {
            return $this->dispatched[] = new Envelope($message, $stamps);
        });

        return new GmailApiSyncer(
            new GmailApiClient($http, $tokens, new GmailQuotaPacer(new MockClock())),
            $messages,
            $this->createStub(EntityManagerInterface::class),
            $bus,
            new NullLogger(),
            $this->createStub(MessageEraser::class),
            new SyncOrigin(),
        );
    }

    private function account(): Account
    {
        $account      = new Account();
        $account->usr = new User();

        return $account;
    }
}
