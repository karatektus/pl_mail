<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Pop3;

use App\Infrastructure\Pop3\Pop3Connection;
use App\Infrastructure\Pop3\Pop3Exception;
use App\Infrastructure\Pop3\Pop3RefusedException;
use App\Service\Imap\ImapDeadline;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The POP3 conversation, against a real socket.
 *
 * Plaintext and a loopback listener, because everything worth testing here is
 * the protocol — the greeting, the multi-line framing, dot-unstuffing, the
 * transaction that only commits on QUIT — and none of it needs TLS to be
 * exercised. The TLS path shares the same read/write code and is covered by
 * the fact that a real server is what the feature is for; a handshake test
 * would be testing PHP's ssl layer.
 *
 * The server is scripted rather than implemented: this class asserts on the
 * bytes the client sends and the bytes it makes of the answer, which is the
 * contract every one of these tests is about.
 */
final class Pop3ConnectionTest extends TestCase
{
    /** @var resource|null */
    private $listener = null;

    /** @var list<resource> */
    private array $peers = [];

    protected function tearDown(): void
    {
        $verdict = $this->collectChild();

        foreach ($this->peers as $peer) {
            if (true === is_resource($peer)) {
                fclose($peer);
            }
        }

        if (null !== $this->listener && true === is_resource($this->listener)) {
            fclose($this->listener);
        }

        foreach (['', '.ready', '.status'] as $suffix) {
            if ('' !== $this->transcript && true === is_file($this->transcript . $suffix)) {
                unlink($this->transcript . $suffix);
            }
        }

        $this->listener   = null;
        $this->peers      = [];
        $this->transcript = '';

        // The server's own verdict on what the client sent. Without it a
        // client that sent the wrong command only failed when the hang-up
        // that followed happened to surface as an exception.
        if (null !== $verdict && 'OK' !== $verdict) {
            self::fail('The scripted server saw the wrong conversation: ' . $verdict);
        }
    }

    public function testItLogsInAndListsWhatTheServerHolds(): void
    {
        $this->serve(static function (array $server): void {
            $server['expect']('USER someone@example.com');
            $server['send']("+OK\r\n");

            $server['expect']('PASS secret');
            $server['send']("+OK 2 messages\r\n");

            $server['expect']('LIST');
            $server['send']("+OK 2 messages (320 octets)\r\n1 120\r\n2 200\r\n.\r\n");

            $server['expect']('UIDL');
            $server['send']("+OK\r\n1 aaa111\r\n2 bbb222\r\n.\r\n");

            $server['expect']('QUIT');
            $server['send']("+OK bye\r\n");
        });

        $connection = $this->connect();

        $listing = $connection->list();
        $uidls   = $connection->uidl();

        $connection->quit();

        self::assertCount(2, $listing);
        self::assertSame(1, $listing[0]->number);
        self::assertSame(120, $listing[0]->octets);
        self::assertSame(2, $listing[1]->number);

        self::assertSame([1 => 'aaa111', 2 => 'bbb222'], $uidls);
        self::assertContains('USER someone@example.com', $this->received());
        self::assertContains('PASS secret', $this->received());
    }

    /**
     * A message that begins with a line containing only a period, and lines
     * that begin with a period, come back exactly as the sender wrote them.
     *
     * This is the one transformation RFC 1939 §3 asks the client to undo, and
     * getting it wrong corrupts the message in a way that only shows up when
     * the parser reaches the line — which for a multipart body means a
     * boundary that is no longer a boundary.
     */
    public function testRetrieveUndoesTheDotStuffing(): void
    {
        $body = "Subject: Hello\r\n"
            . "Content-Type: text/plain\r\n"
            . "\r\n"
            . "...the end is near.\r\n"
            . "A normal line.\r\n"
            . "..another dotted line\r\n";

        $this->serve(static function (array $server) use ($body): void {
            $server['expect']('USER someone@example.com');
            $server['send']("+OK\r\n");
            $server['expect']('PASS secret');
            $server['send']("+OK\r\n");

            $server['expect']('RETR 1');

            // What the server puts on the wire: a period is added to a line
            // that already starts with one (and only that), and a lone period
            // terminates. Adding one to EVERY line, which an earlier version
            // of this fixture did, tests a server no POP3 describes.
            $wire = '';
            foreach (explode("\r\n", rtrim($body, "\r\n")) as $line) {
                $wire .= (str_starts_with($line, '.') ? '.' . $line : $line) . "\r\n";
            }
            $wire .= ".\r\n";

            $server['send']("+OK 120 octets\r\n" . $wire);
        });

        $connection = $this->connect();
        $raw        = $connection->retrieve(1);

        $connection->quit();

        self::assertStringContainsString("...the end is near.\r\n", $raw);
        self::assertStringContainsString("..another dotted line\r\n", $raw);
        self::assertStringNotContainsString("....the end", $raw);
    }

    /**
     * The message is rebuilt with CRLF after every line, including the last.
     *
     * The parser splits headers on CRLFCRLF; a message built by joining the
     * lines without a separator is one enormous line in which no header is
     * findable and every body is empty. This is the failure that would look
     * like "POP3 stores messages with no body", and it is worth a test of its
     * own rather than being implied by the one above.
     */
    public function testTheOriginalMessageIsRebuiltWithItsLineEndings(): void
    {
        $this->serve(static function (array $server): void {
            $server['expect']('USER someone@example.com');
            $server['send']("+OK\r\n");
            $server['expect']('PASS secret');
            $server['send']("+OK\r\n");

            $server['expect']('RETR 1');
            $server['send']("+OK\r\nSubject: Hi\r\nFrom: a@b.c\r\n\r\nbody line\r\n.\r\n");
        });

        $connection = $this->connect();
        $raw        = $connection->retrieve(1);

        $connection->quit();

        self::assertSame("Subject: Hi\r\nFrom: a@b.c\r\n\r\nbody line\r\n", $raw);
    }

    /**
     * TOP n 0 brings the header block alone, framed like RETR, and a server
     * that does not offer it says so with -ERR — which the syncer reads as
     * "use RETR instead", so it has to be the refusal and not a hang-up.
     */
    public function testTopReturnsTheHeadersAndARefusalIsARefusal(): void
    {
        $this->serve(static function (array $server): void {
            $server['expect']('USER someone@example.com');
            $server['send']("+OK\r\n");
            $server['expect']('PASS secret');
            $server['send']("+OK\r\n");

            $server['expect']('TOP 1 0');
            $server['send']("+OK\r\nMessage-ID: <a@b>\r\n..dotted: header\r\n\r\n.\r\n");

            $server['expect']('TOP 2 0');
            $server['send']("-ERR TOP not supported\r\n");

            $server['expect']('QUIT');
            $server['send']("+OK bye\r\n");
        });

        $connection = $this->connect();

        self::assertSame("Message-ID: <a@b>\r\n.dotted: header\r\n\r\n", $connection->top(1));

        try {
            $connection->top(2);
            self::fail('a refused TOP must throw');
        } catch (Pop3RefusedException) {
        }

        $connection->quit();
    }

    /**
     * A DELE is not a deletion until QUIT — and abort() is the way to make sure
     * it never becomes one.
     *
     * The whole safety story of the delete mode is this. A sync that stored ten
     * messages and then died must not have told the server to drop them, and
     * the guarantee is the protocol's, not the syncer's: a session that ends
     * without QUIT rolls the transaction back.
     */
    public function testADeletionIsOnlyRealWhenTheSessionIsQuit(): void
    {
        $this->serve(static function (array $server): void {
            $server['expect']('USER someone@example.com');
            $server['send']("+OK\r\n");
            $server['expect']('PASS secret');
            $server['send']("+OK\r\n");

            $server['expect']('DELE 1');
            $server['send']("+OK marked\r\n");

            // abort() closes without QUIT; the client under test must not send
            // one. Nothing to script after DELE.
        });

        $connection = $this->connect();
        $connection->markDeleted(1, 'aaa111');

        self::assertTrue($connection->isMarkedDeleted('aaa111'));

        $connection->abort();

        self::assertNotContains('QUIT', $this->received(), 'abort must not commit the transaction');
    }

    public function testAGreetingThatIsNotOkIsAnError(): void
    {
        $this->serve(static function (): void {
        }, greeting: "-ERR service unavailable\r\n");

        $this->expectException(Pop3Exception::class);
        $this->expectExceptionMessageMatches('/did not greet us/');

        $this->connect();
    }

    public function testARefusedLoginIsAnError(): void
    {
        $this->serve(static function (array $server): void {
            $server['expect']('USER someone@example.com');
            $server['send']("+OK\r\n");

            $server['expect']('PASS whatever');
            $server['send']("-ERR [AUTH] invalid credentials\r\n");
        });

        $this->expectException(Pop3Exception::class);
        $this->expectExceptionMessageMatches('/invalid credentials/');

        // The password in the test is the account's $password, which the
        // fixture expects; the point is the -ERR, not the value.
        Pop3Connection::open('127.0.0.1', $this->port(), 'none', 'someone@example.com', 'whatever', $this->deadline());
    }

    public function testAMessageTheServerRefusesIsAnError(): void
    {
        $this->serve(static function (array $server): void {
            $server['expect']('USER someone@example.com');
            $server['send']("+OK\r\n");
            $server['expect']('PASS secret');
            $server['send']("+OK\r\n");

            $server['expect']('RETR 7');
            $server['send']("-ERR no such message\r\n");
        });

        $connection = $this->connect();

        try {
            $this->expectException(Pop3Exception::class);
            $connection->retrieve(7);
        } finally {
            $connection->abort();
        }
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    private function connect(): Pop3Connection
    {
        return Pop3Connection::open('127.0.0.1', $this->port(), 'none', 'someone@example.com', 'secret', $this->deadline());
    }

    private function deadline(): ImapDeadline
    {
        return new ImapDeadline(new NullLogger());
    }

    /**
     * Bind a loopback listener and run the scripted server in a child process.
     *
     * A process rather than a thread because PHP has no threads worth using
     * here, and a forked server is exactly the shape the client sees: a peer
     * on the other end of a socket that answers when it is asked.
     *
     * The child never returns into PHPUnit. It writes its verdict to a file
     * and kills itself: exit() would run the shutdown handlers and destructors
     * it inherited from the parent — PHPUnit's own among them, and anything
     * holding a database connection the parent is still using.
     *
     * @param callable(array{expect: callable(string): void, send: callable(string): void}): void $script
     */
    private function serve(callable $script, string $greeting = "+OK pop3.test ready\r\n"): void
    {
        if (false === function_exists('pcntl_fork') || false === function_exists('posix_kill')) {
            self::markTestSkipped('needs pcntl and posix to run a scripted server');
        }

        $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);

        if (false === $listener) {
            self::fail(sprintf('Could not bind a test listener: %s', $error));
        }

        $this->listener = $listener;

        // A per-test transcript file, outside the repository's var/ so a
        // parallel run does not collide with anything the app writes.
        $this->transcript = tempnam(sys_get_temp_dir(), 'pop3-transcript-') ?: '';

        if ('' === $this->transcript) {
            self::fail('Could not create a transcript file for the scripted server.');
        }

        $name = stream_socket_get_name($listener, false);
        self::assertIsString($name);

        // The port is taken from the listener's own address, so parallel runs
        // and a CI box with something on 110 are both fine.
        $this->port = (int) substr($name, strrpos($name, ':') + 1);

        $transcript = $this->transcript;
        $pid        = pcntl_fork();

        if (-1 === $pid) {
            self::markTestSkipped('could not fork a scripted server');
        }

        if (0 === $pid) {
            $verdict = 'OK';

            try {
                // Ready means "about to accept", which is before the blocking
                // call: telling the parent from inside accept() would be a
                // deadlock.
                touch($transcript . '.ready');

                $peer = @stream_socket_accept($listener, 10);

                if (false === $peer) {
                    throw new \RuntimeException('the client never connected');
                }

                // The parent cannot see this process's memory, so what the
                // client sent is reported through a file, a line at a time and
                // before the answer, so even an abandoned session is complete.
                $log = fopen($transcript, 'ab');

                $server = [
                    'expect' => static function (string $line) use ($peer, $log): void {
                        $answer = fgets($peer);

                        if (false === $answer) {
                            throw new \RuntimeException(sprintf('expected "%s", the client hung up', $line));
                        }

                        $got = rtrim($answer, "\r\n");

                        fwrite($log, $got . "\n");
                        fflush($log);

                        if ($got !== $line) {
                            throw new \RuntimeException(sprintf('expected "%s", got "%s"', $line, $got));
                        }
                    },
                    'send' => static function (string $data) use ($peer): void {
                        fwrite($peer, $data);
                    },
                ];

                fwrite($peer, $greeting);

                $script($server);

                fclose($peer);
                fclose($log);
            } catch (\Throwable $failure) {
                $verdict = $failure->getMessage();
            }

            file_put_contents($transcript . '.status', $verdict);

            posix_kill(posix_getpid(), SIGKILL);
        }

        $this->child = $pid;

        // Wait until the child is inside accept(); before that the client's
        // connect would land in the backlog of a listener nobody reads.
        $waited = 0.0;

        while (false === is_file($transcript . '.ready') && $waited < 5.0) {
            usleep(20_000);
            $waited += 0.02;
        }
    }

    /**
     * Reap the child and read its verdict: 'OK', what went wrong, or null
     * when no server ran.
     */
    private function collectChild(): ?string
    {
        if (null === $this->child) {
            return null;
        }

        $status = 0;
        pcntl_waitpid($this->child, $status);
        $this->child = null;

        $file = $this->transcript . '.status';

        return true === is_file($file) ? (string) file_get_contents($file) : 'the server died without a verdict';
    }

    private ?int $child = null;

    /** What the client sent, as the child recorded it. */
    private string $transcript = '';

    /** @return list<string> every command the client sent, in order */
    private function received(): array
    {
        if ('' === $this->transcript || false === is_file($this->transcript)) {
            return [];
        }

        return array_values(array_filter(
            explode("\n", (string) file_get_contents($this->transcript)),
            static fn (string $line): bool => '' !== $line,
        ));
    }

    private int $port = 0;

    private function port(): int
    {
        return $this->port;
    }
}
