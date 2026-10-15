<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use App\Infrastructure\Messaging\Middleware\AbortStalledImap;
use App\Service\Imap\ImapDeadline;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Connection\Protocols\ImapProtocol;
use Webklex\PHPIMAP\Connection\Protocols\ProtocolInterface;
use Webklex\PHPIMAP\Connection\Protocols\Response;
use Webklex\PHPIMAP\Exceptions\RuntimeException as ImapRuntimeException;

/**
 * A stalled IMAP conversation is cut off, and only that.
 *
 * #95: two consumers held for twenty minutes by one server sending a trickle,
 * and every other account's import waiting behind them. The per-read timeout
 * never fires on a trickle, so the deadline is on the whole handled message.
 *
 * Real sockets and a real alarm. The claim is that a read which would have
 * gone on waiting stops, and the only way to know is to be waiting on one.
 */
final class ImapDeadlineTest extends TestCase
{
    /** @var list<resource> */
    private array $peers = [];

    protected function tearDown(): void
    {
        if (true === ImapDeadline::available()) {
            pcntl_alarm(0);
        }

        foreach ($this->peers as $peer) {
            if (true === is_resource($peer)) {
                fclose($peer);
            }
        }
    }

    public function testExpiringClosesTheConnectionUnderItsReader(): void
    {
        $deadline = new ImapDeadline(new NullLogger());
        $client   = $this->client();

        $deadline->watch($client);

        self::assertSame(1, $deadline->expire());

        // Still a stream the library can ask questions of — its logout and its
        // destructor both do — but one with nothing more to say.
        self::assertTrue(is_resource($this->streamOf($client)));

        $started = microtime(true);

        try {
            $this->protocolOf($client)->nextLine(new Response(0, false));
            self::fail('a read on a conversation that was cut off cannot succeed');
        } catch (ImapRuntimeException $e) {
            // What the library says for a connection that dropped, which is
            // the failure every caller already knows how to have.
            self::assertSame('empty response', $e->getMessage());
        }

        self::assertLessThan(1.0, microtime(true) - $started, 'at once, not after another read timeout');
        self::assertTrue(feof($this->streamOf($client)));
    }

    public function testAConnectionNobodyHoldsAnyMoreIsNotKeptAliveToBeClosed(): void
    {
        $deadline = new ImapDeadline(new NullLogger());
        $client   = $this->client();

        $deadline->watch($client);
        unset($client);
        gc_collect_cycles();

        self::assertSame(0, $deadline->expire());
    }

    /** The whole thing, on the clock: a read that had ten seconds to wait is over in about one. */
    public function testAHandlerStillReadingWhenTheClockRunsOutIsStopped(): void
    {
        if (false === ImapDeadline::available()) {
            self::markTestSkipped('needs pcntl');
        }

        $deadline   = new ImapDeadline(new NullLogger(), seconds: 1);
        $middleware = new AbortStalledImap($deadline);
        $client     = $this->client();
        $started    = microtime(true);
        $failure    = null;

        $handler = new class ($deadline, $client, $this) implements MiddlewareInterface {
            public function __construct(
                private readonly ImapDeadline $deadline,
                private readonly Client $client,
                private readonly ImapDeadlineTest $test,
            ) {
            }

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                // As ImapConnectionFactory does for a connection a handler opens.
                $this->deadline->watch($this->client);

                $stream = $this->test->streamOf($this->client);
                stream_set_timeout($stream, 0, 200_000);

                // A server that never sends the line. Each read gives up after
                // its own short timeout, which is what the per-read timeout
                // looks like from inside: on its own it ends nothing, and this
                // loop would run its full ten seconds.
                $protocol = $this->test->protocolOf($this->client);

                for ($i = 0; $i < 50; ++$i) {
                    if (true === feof($stream)) {
                        // The library's own words for a connection that is gone.
                        $protocol->nextLine(new Response(0, false));
                    }

                    fread($stream, 1);
                }

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

        self::assertNotNull($failure, 'the read was cut off rather than left to finish');
        self::assertLessThan(4.0, microtime(true) - $started, 'at the deadline, not at the end of the ten seconds');
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

    /** @return resource */
    public function streamOf(Client $client): mixed
    {
        return $this->protocolOf($client)->getStream();
    }

    public function protocolOf(Client $client): ImapProtocol
    {
        $protocol = $client->getConnection();

        self::assertInstanceOf(ImapProtocol::class, $protocol);

        return $protocol;
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

    /** A client whose connection is one end of a socket pair nothing is ever written to. */
    private function client(): Client
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);

        self::assertIsArray($pair);

        $this->peers[] = $pair[1];

        $protocol = new class (Config::make(), $pair[0]) extends ImapProtocol {
            /** @param resource $socket */
            public function __construct(Config $config, mixed $socket)
            {
                parent::__construct($config);

                $this->stream = $socket;
            }

            /**
             * The real one logs out, and a logout waits for the server's
             * goodbye — thirty seconds of it, from a socket pair with nobody
             * on the other end, once per test.
             */
            public function __destruct()
            {
            }
        };

        return new class (Config::make(), $protocol) extends Client {
            public function __construct(Config $config, private readonly ProtocolInterface $fake)
            {
                parent::__construct($config);
            }

            public function getConnection(): ProtocolInterface
            {
                return $this->fake;
            }
        };
    }
}
