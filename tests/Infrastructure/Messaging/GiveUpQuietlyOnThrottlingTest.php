<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Messaging;

use App\Domain\Exception\GmailThrottledException;
use App\Domain\Exception\GraphThrottledException;
use App\Infrastructure\Messaging\Message\ApplyGmailLabelsMessage;
use App\Infrastructure\Messaging\Message\SyncAccountMessage;
use App\Infrastructure\Messaging\Message\SyncGmailMessageBatchMessage;
use App\Infrastructure\Messaging\Middleware\GiveUpQuietlyOnThrottling;
use App\Infrastructure\Messaging\Stamp\ThrottleDeferralStamp;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\DependencyInjection\ServiceLocator;
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
 * A provider still rate-limiting after the last retry is not a fault in this
 * application, and the two jobs it can happen to want opposite things done.
 *
 * What is pinned is each of the conditions that keeps this narrow: it is the
 * only thing standing between a real failure and a job that quietly vanishes.
 */
final class GiveUpQuietlyOnThrottlingTest extends TestCase
{
    private InMemoryTransport $ingest;
    private InMemoryTransport $export;

    protected function setUp(): void
    {
        $this->ingest = new InMemoryTransport();
        $this->export = new InMemoryTransport();
    }

    /**
     * "See what is new" is asked again every quarter of an hour and resumes
     * from a cursor that did not move. Parking it is a dead job on the failed
     * queue; the CRITICAL was an alarm about a delay.
     */
    public function testAnAccountSyncStillThrottledOnItsLastAttemptIsDropped(): void
    {
        $envelope = $this->received(new SyncAccountMessage(7), retries: 5);

        $returned = $this->handle($envelope, $this->throttled());

        self::assertSame($envelope, $returned, 'returned means acknowledged: not retried, not parked');
        self::assertSame([], $this->ingest->getSent());
    }

    /**
     * "Fetch these messages" was queued after the cursor moved past them.
     * Nothing lists them again, so this is the one that must not be lost.
     */
    public function testAFetchBatchStillThrottledIsPutBackToWaitWithAFreshSetOfRetries(): void
    {
        $message = new SyncGmailMessageBatchMessage(7, ['a', 'b']);

        $this->handle($this->received($message, retries: 5), $this->throttled());

        $sent = $this->ingest->getSent();

        self::assertCount(1, $sent);
        self::assertSame($message, $sent[0]->getMessage());
        self::assertSame(GiveUpQuietlyOnThrottling::DEFER_SECONDS * 1000, $sent[0]->last(DelayStamp::class)?->getDelay());
        self::assertSame(1, $sent[0]->last(ThrottleDeferralStamp::class)?->count);
        self::assertSame(0, RedeliveryStamp::getRetryCountFromEnvelope($sent[0]), 'waiting is for trying properly afterwards');
    }

    /**
     * A quota that has not cleared after every deferral is not a busy minute.
     * It fails the way it always did, where an administrator will see it.
     */
    public function testABatchIsNotPutBackForEver(): void
    {
        $envelope = $this->received(new SyncGmailMessageBatchMessage(7, ['a']), retries: 5)
            ->with(new ThrottleDeferralStamp(GiveUpQuietlyOnThrottling::MAX_DEFERRALS));

        $this->expectException(HandlerFailedException::class);

        try {
            $this->handle($envelope, $this->throttled());
        } finally {
            self::assertSame([], $this->ingest->getSent());
        }
    }

    /**
     * #90: a label change pushed to Gmail while an import had the quota was
     * refused, and Messenger retried it — correctly, and with a WARNING and
     * two stack traces each time. A quota refusal is not a failure: the job
     * goes back where it came from, on its first attempt, and says so once.
     */
    public function testAJobGmailRefusedOnQuotaGoesBackOntoItsOwnQueueAtOnce(): void
    {
        $message  = new ApplyGmailLabelsMessage(7, ['m1'], ['STARRED'], []);
        $envelope = new Envelope($message, [new ReceivedStamp('export')]);

        $returned = $this->handle($envelope, $this->refusedByGmail());

        self::assertSame($envelope, $returned, 'returned means acknowledged: no retry, no warning');
        self::assertSame([], $this->ingest->getSent(), 'back where it came from, not onto the queue mail arrives on');

        $sent = $this->export->getSent();

        self::assertCount(1, $sent);
        self::assertSame($message, $sent[0]->getMessage());
        self::assertSame(0, RedeliveryStamp::getRetryCountFromEnvelope($sent[0]), 'its own retries are untouched');
        self::assertSame(1, $sent[0]->last(ThrottleDeferralStamp::class)?->count);

        $delay = $sent[0]->last(DelayStamp::class)?->getDelay();

        self::assertGreaterThan(45_000, $delay);
        self::assertLessThanOrEqual(55_000, $delay);
    }

    public function testAQuotaThatNeverClearsIsAFailureInTheEnd(): void
    {
        $envelope = (new Envelope(new ApplyGmailLabelsMessage(7, ['m1'], ['STARRED'], []), [new ReceivedStamp('export')]))
            ->with(new ThrottleDeferralStamp(GiveUpQuietlyOnThrottling::QUOTA_WAITS));

        $this->expectException(HandlerFailedException::class);

        $this->handle($envelope, $this->refusedByGmail());
    }

    public function testAGmailRefusalBesideAnotherFailureIsStillAFailure(): void
    {
        $envelope = new Envelope(new ApplyGmailLabelsMessage(7, ['m1'], ['STARRED'], []), [new ReceivedStamp('export')]);

        $this->expectException(HandlerFailedException::class);

        $this->through($envelope, new HandlerFailedException($envelope, [$this->refusedByGmail(), new RuntimeException('and this')]));
    }

    public function testAnEarlierAttemptIsLeftToTheRetryLadder(): void
    {
        $this->expectException(HandlerFailedException::class);

        $this->handle($this->received(new SyncAccountMessage(7), retries: 4), $this->throttled());
    }

    public function testAnyOtherFailureIsStillAFailure(): void
    {
        $this->expectException(HandlerFailedException::class);

        $this->handle($this->received(new SyncAccountMessage(7), retries: 5), new RuntimeException('the database went away'));
    }

    /**
     * Throttled AND broken some other way is a second problem, and must not be
     * waved through on the strength of the first.
     */
    public function testThrottlingBesideAnotherFailureIsStillAFailure(): void
    {
        $envelope = $this->received(new SyncAccountMessage(7), retries: 5);

        $this->expectException(HandlerFailedException::class);

        $this->through($envelope, new HandlerFailedException($envelope, [$this->throttled(), new RuntimeException('and this')]));
    }

    /**
     * Handled inline, in the process that dispatched it, a message has no
     * retry ladder to be at the end of and must fail to its caller.
     */
    public function testAMessageNoWorkerReceivedIsLeftAlone(): void
    {
        $envelope = new Envelope(new SyncAccountMessage(7), [new RedeliveryStamp(5)]);

        $this->expectException(HandlerFailedException::class);

        $this->handle($envelope, $this->throttled());
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

        $transports = new ServiceLocator([
            'ingest' => fn (): InMemoryTransport => $this->ingest,
            'export' => fn (): InMemoryTransport => $this->export,
        ]);

        return new GiveUpQuietlyOnThrottling($this->ingest, new NullLogger(), $transports)
            ->handle($envelope, new StackMiddleware($handler));
    }

    private function received(object $message, int $retries): Envelope
    {
        return new Envelope($message, [new ReceivedStamp('ingest'), new RedeliveryStamp($retries)]);
    }

    /**
     * Microsoft's, in the tests of the last attempt: a Gmail refusal never
     * gets that far any more.
     */
    private function throttled(): GraphThrottledException
    {
        return new GraphThrottledException('Graph answered 429 (TooManyRequests)');
    }

    private function refusedByGmail(): GmailThrottledException
    {
        return new GmailThrottledException('Gmail messages.batchModify failed with 403 (rateLimitExceeded)', 403, 'rateLimitExceeded', 45);
    }
}
