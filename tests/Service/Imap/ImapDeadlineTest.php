<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use App\Infrastructure\ImapClient\Exception\ImapConnectionException;
use App\Infrastructure\ImapClient\ImapLoop;
use App\Infrastructure\ImapClient\ImapSession;
use App\Infrastructure\Messaging\Middleware\AbortStalledImap;
use App\Service\Imap\ImapDeadline;
use App\Tests\Infrastructure\ImapClient\FakeImapServer;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * A stalled IMAP conversation is cut off, and only that.
 *
 * #95: two consumers held for twenty minutes by one server sending a trickle,
 * and every other account's import waiting behind them. The per-read timeout
 * never fires on a trickle, so the deadline is on the whole handled message.
 *
 * Real sockets and a real alarm, on plMail's own IMAP client. The server here
 * answers NOOP after thirty seconds, which is a read that would have gone on
 * waiting; the claim is that it stops, and the only way to know is to be
 * waiting on one.
 */
final class ImapDeadlineTest extends TestCase
{
    protected function tearDown(): void
    {
        if (true === ImapDeadline::available()) {
            pcntl_alarm(0);
        }
    }

    public function testExpiringClosesTheConnectionUnderItsReader(): void
    {
        $deadline = new ImapDeadline(new NullLogger());
        $session  = $this->stalledSession();

        $deadline->watchSession($session->connection);

        self::assertSame(1, $deadline->expire());

        $started = microtime(true);

        try {
            $session->connection->command('NOOP');
            self::fail('a command on a conversation that was cut off cannot succeed');
        } catch (ImapConnectionException) {
            // A connection that dropped: the failure every caller already
            // knows how to have.
        }

        self::assertLessThan(1.0, microtime(true) - $started, 'at once, not after the server\'s thirty seconds');
        self::assertTrue($session->connection->isClosed);
    }

    public function testAConnectionNobodyHoldsAnyMoreIsNotKeptAliveToBeClosed(): void
    {
        $deadline = new ImapDeadline(new NullLogger());
        $session  = $this->stalledSession();

        $deadline->watchSession($session->connection);
        unset($session);
        gc_collect_cycles();

        self::assertSame(0, $deadline->expire());
    }

    /** The whole thing, on the clock: a command the server holds for thirty seconds is over in about one. */
    public function testAHandlerStillReadingWhenTheClockRunsOutIsStopped(): void
    {
        if (false === ImapDeadline::available()) {
            self::markTestSkipped('needs pcntl');
        }

        $deadline   = new ImapDeadline(new NullLogger(), seconds: 1);
        $middleware = new AbortStalledImap($deadline);
        $session    = $this->stalledSession();
        $started    = microtime(true);
        $failure    = null;

        $handler = new class ($deadline, $session) implements MiddlewareInterface {
            public function __construct(
                private readonly ImapDeadline $deadline,
                private readonly ImapSession  $session,
            ) {
            }

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                // As ImapConnector does for a connection a handler opens.
                $this->deadline->watchSession($this->session->connection);

                $this->session->connection->command('NOOP');

                return $envelope;
            }
        };

        try {
            $middleware->handle(
                new Envelope(new \stdClass(), [new ReceivedStamp('ingest')]),
                $this->stackOf($handler),
            );
        } catch (\Throwable $e) {
            $failure = $e;
        }

        self::assertInstanceOf(ImapConnectionException::class, $failure, 'the read was cut off rather than left to finish');
        self::assertLessThan(4.0, microtime(true) - $started, 'at the deadline, not after the server\'s thirty seconds');
        self::assertSame(0, pcntl_alarm(0), 'and the clock was stopped on the way out');
    }

    /** Passing through on its way to a queue is not being handled, and is not timed. */
    public function testAMessageThatWasNotReceivedByAWorkerIsNotTimed(): void
    {
        if (false === ImapDeadline::available()) {
            self::markTestSkipped('needs pcntl');
        }

        $handler = new class () implements MiddlewareInterface {
            public ?int $secondsLeft = null;

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                // pcntl_alarm() answers with the seconds left on the old alarm.
                $this->secondsLeft = pcntl_alarm(0);

                return $envelope;
            }
        };

        new AbortStalledImap(new ImapDeadline(new NullLogger()))->handle(new Envelope(new \stdClass()), $this->stackOf($handler));

        self::assertSame(0, $handler->secondsLeft);

        new AbortStalledImap(new ImapDeadline(new NullLogger()))->handle(
            new Envelope(new \stdClass(), [new ReceivedStamp('ingest')]),
            $this->stackOf($handler),
        );

        self::assertGreaterThan(0, $handler->secondsLeft, 'while a received one has the clock running');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** A logged-in session on a server that takes thirty seconds over every NOOP. */
    private function stalledSession(): ImapSession
    {
        $loop   = new ImapLoop();
        $server = new FakeImapServer($loop);

        $server->on('NOOP', static function () use ($loop): array {
            $loop->sleep(30.0);

            return ['OK NOOP completed'];
        });

        return $server->session();
    }

    private function stackOf(MiddlewareInterface $next): StackInterface
    {
        return new class ($next) implements StackInterface {
            public function __construct(private readonly MiddlewareInterface $next)
            {
            }

            public function next(): MiddlewareInterface
            {
                return $this->next;
            }
        };
    }
}
