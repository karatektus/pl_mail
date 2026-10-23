<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Messaging;

use App\Infrastructure\Messaging\Message\SyncAccountMessage;
use App\Infrastructure\Messaging\Message\SyncGmailMessageBatchMessage;
use App\Infrastructure\Messaging\Middleware\WaitOutConnectionFailures;
use App\Infrastructure\Messaging\Stamp\ConnectionWaitStamp;
use App\Infrastructure\Messaging\Stamp\ThrottleDeferralStamp;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Middleware\StackMiddleware;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Throwable;

/**
 * An account sync that could not reach the provider waits and tries again
 * without a stack trace, and is dropped with one warning if the outage lasts
 * (#332).
 *
 * As with GiveUpQuietlyOnThrottlingTest, most of what is pinned is what keeps
 * this narrow: everything it does not take must still fail.
 */
final class WaitOutConnectionFailuresTest extends TestCase
{
    private InMemoryTransport $ingest;
    private BufferingLogger $logger;

    protected function setUp(): void
    {
        $this->ingest = new InMemoryTransport();
        $this->logger = new BufferingLogger();
    }

    /** A BufferingLogger left with lines prints them to stderr on its way out. */
    protected function tearDown(): void
    {
        $this->logger->cleanLogs();
    }

    public function testAnAccountSyncThatCouldNotConnectIsPutBackOnItsOwnQueue(): void
    {
        $message  = new SyncAccountMessage(7, requestedAt: 1_700_000_000);
        $envelope = $this->received($message);

        $returned = $this->handle($envelope, $this->couldNotConnect());

        self::assertSame($envelope, $returned, 'returned means acknowledged: Messenger logs no retry');

        $sent = $this->ingest->getSent();

        self::assertCount(1, $sent);
        self::assertSame($message, $sent[0]->getMessage(), 'the same request, so a sync since then makes it redundant');
        self::assertSame(1, $sent[0]->last(ConnectionWaitStamp::class)?->count);

        $delay = $sent[0]->last(DelayStamp::class)?->getDelay();

        self::assertGreaterThan(5_000, $delay);
        self::assertLessThanOrEqual(15_000, $delay);
        self::assertSame([['info', 'Sync put back: the mail provider could not be reached']], $this->logged());
    }

    /**
     * The ingest ladder's own steps, so an outage is waited out for about as
     * long as it was before — just without the traces.
     */
    public function testTheWaitsGrowLikeTheIngestLadderAndStopAtFiveMinutes(): void
    {
        $this->handle($this->received(new SyncAccountMessage(7))->with(new ConnectionWaitStamp(2)), $this->couldNotConnect());
        $this->handle($this->received(new SyncAccountMessage(7))->with(new ConnectionWaitStamp(4)), $this->couldNotConnect());

        [$third, $fifth] = $this->ingest->getSent();

        self::assertGreaterThan(45_000, $third->last(DelayStamp::class)?->getDelay());
        self::assertLessThanOrEqual(55_000, $third->last(DelayStamp::class)?->getDelay());
        self::assertGreaterThan(300_000, $fifth->last(DelayStamp::class)?->getDelay());
        self::assertLessThanOrEqual(310_000, $fifth->last(DelayStamp::class)?->getDelay());
        self::assertSame(5, $fifth->last(ConnectionWaitStamp::class)?->count);
    }

    /**
     * "See what is new" resumes from a cursor that did not move, and is asked
     * for again within the quarter-hour. A parked copy would be a dead job.
     */
    public function testAnOutageThatLastsIsDroppedWithOneWarning(): void
    {
        $envelope = $this->received(new SyncAccountMessage(7))
            ->with(new ConnectionWaitStamp(WaitOutConnectionFailures::MAX_WAITS));

        $returned = $this->handle($envelope, $this->couldNotConnect());

        self::assertSame($envelope, $returned, 'acknowledged: not retried, not parked');
        self::assertSame([], $this->ingest->getSent());
        $logged = $this->logged();

        self::assertCount(1, $logged);
        self::assertSame('warning', $logged[0][0]);
    }

    /**
     * Waiting out a quota first must not have spent this count.
     */
    public function testAQuotaWaitIsNotCountedAsAConnectionWait(): void
    {
        $envelope = $this->received(new SyncAccountMessage(7))->with(new ThrottleDeferralStamp(59));

        $this->handle($envelope, $this->couldNotConnect());

        self::assertSame(1, $this->ingest->getSent()[0]->last(ConnectionWaitStamp::class)?->count);
    }

    /**
     * Queued after the cursor moved past them, and listed again by nothing:
     * a batch that cannot connect keeps the ladder, and the noise with it.
     */
    public function testAFetchBatchIsLeftToTheRetryLadder(): void
    {
        $this->expectException(HandlerFailedException::class);

        $this->handle($this->received(new SyncGmailMessageBatchMessage(7, ['a'])), $this->couldNotConnect());
    }

    public function testAnyOtherFailureIsStillAFailure(): void
    {
        $this->expectException(HandlerFailedException::class);

        $this->handle($this->received(new SyncAccountMessage(7)), new RuntimeException('the database went away'));
    }

    public function testAConnectionFailureBesideAnotherFailureIsStillAFailure(): void
    {
        $envelope = $this->received(new SyncAccountMessage(7));

        $this->expectException(HandlerFailedException::class);

        $this->through($envelope, new HandlerFailedException($envelope, [$this->couldNotConnect(), new RuntimeException('and this')]));
    }

    /**
     * Handled inline, in the process that dispatched it, a sync has no queue
     * to go back to and must fail to its caller.
     */
    public function testAMessageNoWorkerReceivedIsLeftAlone(): void
    {
        $this->expectException(HandlerFailedException::class);

        $this->handle(new Envelope(new SyncAccountMessage(7)), $this->couldNotConnect());
    }

    private function handle(Envelope $envelope, Throwable $thrownByTheHandler): Envelope
    {
        return $this->through($envelope, new HandlerFailedException($envelope, [$thrownByTheHandler]));
    }

    private function through(Envelope $envelope, HandlerFailedException $failure): Envelope
    {
        $handler = new class($failure) implements MiddlewareInterface {
            public function __construct(private readonly HandlerFailedException $failure)
            {
            }

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                throw $this->failure;
            }
        };

        $transports = new ServiceLocator(['ingest' => fn (): InMemoryTransport => $this->ingest]);

        return new WaitOutConnectionFailures($this->logger, $transports)
            ->handle($envelope, new StackMiddleware($handler));
    }

    /**
     * @return list<array{string, string}> level and message of each line
     */
    private function logged(): array
    {
        return array_map(static fn (array $line): array => [(string) $line[0], (string) $line[1]], $this->logger->cleanLogs());
    }

    /**
     * Already on its third retry by Messenger's count: what decides is this
     * class's own stamp, not the ladder it replaces.
     */
    private function received(object $message): Envelope
    {
        return new Envelope($message, [new ReceivedStamp('ingest'), new RedeliveryStamp(3)]);
    }

    /** What production logged on 2026-10-23. */
    private function couldNotConnect(): TransportException
    {
        return new TransportException('Failed to connect to gmail.googleapis.com port 443 after 12187 ms: Could not connect to server for "https://gmail.googleapis.com/gmail/v1/users/me/labels".');
    }
}
