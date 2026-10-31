<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\ImapClient;

use App\Infrastructure\ImapClient\Exception\ImapAuthenticationException;
use App\Infrastructure\ImapClient\Exception\ImapConnectionException;
use App\Infrastructure\ImapClient\Exception\ImapProtocolException;
use App\Infrastructure\ImapClient\Exception\ImapTimeoutException;
use App\Infrastructure\ImapClient\ImapAuthMechanism;
use App\Infrastructure\ImapClient\ImapConnection;
use App\Infrastructure\ImapClient\ImapCredentials;
use App\Infrastructure\ImapClient\ImapEndpoint;
use App\Infrastructure\ImapClient\ImapLoop;
use App\Infrastructure\ImapClient\ImapSecurity;
use App\Infrastructure\ImapClient\ImapTask;
use PHPUnit\Framework\TestCase;

/**
 * What the client does when a server misbehaves, or behaves in a way the
 * test stack's Dovecot never would.
 *
 * Scripted rather than real, because these are the cases no well-run server
 * produces on demand: one that accepts a connection and says nothing, one
 * that hangs up in the middle of IDLE, one that talks past a STARTTLS. They
 * are also the cases the client exists for — the first of them is the whole
 * reason IDLE could move into one process (#421).
 */
final class ImapConnectionFailureTest extends TestCase
{
    private ImapLoop $loop;

    /** @var list<ScriptedImapServer> */
    private array $servers = [];

    protected function setUp(): void
    {
        $this->loop = new ImapLoop();
    }

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            $server->finish();
        }
    }

    /**
     * The claim that lets every folder share a process: a server that takes
     * the connection and never greets holds up its own conversation and no
     * other. Before, a blocking client would have sat in the greeting read
     * for its whole timeout before the second server was even dialled.
     */
    public function testASilentServerHoldsUpOnlyItsOwnConnection(): void
    {
        $silent = $this->server(function (ScriptedImapPeer $peer): void {
            $this->loop->sleep(3.0);
            $peer->close();
        });

        $prompt = $this->server(static function (ScriptedImapPeer $peer): void {
            $peer->greetAndLogin();
        });

        $startedAt = microtime(true);
        $stalled   = $this->loop->async(fn () => ImapConnection::open($this->loop, $this->endpoint($silent), 2.0));
        $working   = $this->loop->async(function () use ($prompt): ImapConnection {
            $connection = ImapConnection::open($this->loop, $this->endpoint($prompt));
            $connection->authenticate(new ImapCredentials('user', 'secret'));

            return $connection;
        });

        $this->runUntilFinished($working);

        self::assertTrue($working->result()->isAuthenticated);
        self::assertLessThan(1.0, microtime(true) - $startedAt, 'the working server was not kept waiting');
        self::assertFalse($stalled->isFinished, 'while the silent one is still being waited for');

        $this->runUntilFinished($stalled);

        $this->expectException(ImapTimeoutException::class);

        $stalled->result();
    }

    /**
     * Lark and QQ end IDLE sessions after a few minutes as a matter of
     * course. The old loop read the closed socket as a quiet one and spun a
     * core until its 29-minute re-issue (#94); here it is a closed connection,
     * at once.
     */
    public function testAConnectionClosedDuringIdleIsAConnectionFailure(): void
    {
        $server = $this->server(static function (ScriptedImapPeer $peer): void {
            $peer->greetAndLogin();
            $peer->send(sprintf('%s OK Selected', $peer->expect('SELECT "INBOX"')));
            $peer->expect('IDLE');
            $peer->send('+ idling');
            $peer->close();
        });

        $connection = $this->open($server);
        $connection->select('INBOX');
        $idle = $connection->idle();

        $startedAt = microtime(true);

        try {
            $idle->wait(5.0);
            self::fail('The closed connection was taken for a quiet one.');
        } catch (ImapConnectionException) {
            self::assertLessThan(1.0, microtime(true) - $startedAt, 'noticed at once, not at the end of the wait');
            self::assertTrue($connection->isClosed);
        }
    }

    /**
     * IMAP requires a server to accept a literal before its bytes are sent,
     * unless it offers LITERAL+. Sent early, the bytes land on a server that
     * may have refused the command, and are read as the next command.
     */
    public function testASynchronisingLiteralWaitsForTheServersGoAhead(): void
    {
        $server = $this->server(function (ScriptedImapPeer $peer): void {
            $peer->send('* OK [CAPABILITY IMAP4rev1] ready');

            $line = $peer->line();

            // Six bytes: the ä is two.
            if (1 !== preg_match('~^(\S+) LOGIN "user" \{6\}$~', $line, $match)) {
                throw new \RuntimeException(sprintf('Unexpected: %s', $line));
            }

            // Give an eager client the chance to send the bytes anyway.
            $this->loop->sleep(0.2);
            $peer->send('+ go ahead');

            if ('pässw' !== $peer->bytes(6) || '' !== $peer->line()) {
                throw new \RuntimeException('The literal arrived wrong.');
            }

            $peer->send(sprintf('%s OK [CAPABILITY IMAP4rev1 IDLE] Logged in', $match[1]));
        });

        $connection = ImapConnection::open($this->loop, $this->endpoint($server));
        $connection->authenticate(new ImapCredentials('user', 'pässw'));

        self::assertTrue($connection->isAuthenticated);
    }

    /**
     * A refused token comes back as a challenge — base64 JSON saying why —
     * which has to be answered before the server says NO. A client that did
     * not answer it would wait out its timeout and report a dead server.
     */
    public function testARefusedOAuthTokenIsARefusedLogin(): void
    {
        $token  = base64_encode("user=user\x01auth=Bearer expired\x01\x01");
        $server = $this->server(static function (ScriptedImapPeer $peer) use ($token): void {
            $peer->send('* OK [CAPABILITY IMAP4rev1 SASL-IR AUTH=XOAUTH2] ready');

            $tag = $peer->expect('AUTHENTICATE XOAUTH2 ' . $token);
            $peer->send('+ ' . base64_encode('{"status":"401","schemes":"Bearer"}'));

            if ('' !== $peer->line()) {
                throw new \RuntimeException('The challenge was not answered with an empty line.');
            }

            $peer->send(sprintf('%s NO [AUTHENTICATIONFAILED] Invalid credentials', $tag));
        });

        $connection = ImapConnection::open($this->loop, $this->endpoint($server));

        $this->expectException(ImapAuthenticationException::class);

        $connection->authenticate(new ImapCredentials('user', 'expired', ImapAuthMechanism::XOAuth2));
    }

    /**
     * STARTTLS injection: text that arrives with the server's OK was sent
     * before TLS, by whoever was in the middle, to be read as if the
     * encrypted server had said it.
     */
    public function testDataSentWithTheStartTlsAgreementIsRefused(): void
    {
        $server = $this->server(static function (ScriptedImapPeer $peer): void {
            $peer->send('* OK [CAPABILITY IMAP4rev1 STARTTLS] ready');
            $peer->write(sprintf("%s OK Begin TLS\r\n* CAPABILITY IMAP4rev1 AUTH=PLAIN\r\n", $peer->expect('STARTTLS')));
        });

        $this->expectException(ImapProtocolException::class);

        ImapConnection::open($this->loop, new ImapEndpoint('127.0.0.1', $server->port, ImapSecurity::StartTls, false));
    }

    /** A connection meant for STARTTLS is never carried on in the clear. */
    public function testAServerWithoutStartTlsIsNotSpokenToInTheClear(): void
    {
        $server = $this->server(static function (ScriptedImapPeer $peer): void {
            $peer->send('* OK [CAPABILITY IMAP4rev1 IDLE] ready');
        });

        $this->expectException(ImapConnectionException::class);
        $this->expectExceptionMessage('does not offer STARTTLS');

        ImapConnection::open($this->loop, new ImapEndpoint('127.0.0.1', $server->port, ImapSecurity::StartTls, false));
    }

    public function testARefusedPortIsAConnectionFailure(): void
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        $port   = (int) substr((string) strrchr((string) stream_socket_get_name($server, false), ':'), 1);
        fclose($server);

        $this->expectException(ImapConnectionException::class);

        ImapConnection::open($this->loop, new ImapEndpoint('127.0.0.1', $port, ImapSecurity::None), 2.0);
    }

    /**
     * @param callable(ScriptedImapPeer): void $script
     */
    private function server(callable $script): ScriptedImapServer
    {
        return $this->servers[] = new ScriptedImapServer($this->loop, $script);
    }

    private function runUntilFinished(ImapTask $task): void
    {
        while (false === $task->isFinished) {
            $this->loop->tick(0.1);
        }
    }

    private function endpoint(ScriptedImapServer $server): ImapEndpoint
    {
        return new ImapEndpoint('127.0.0.1', $server->port, ImapSecurity::None);
    }

    private function open(ScriptedImapServer $server): ImapConnection
    {
        $connection = ImapConnection::open($this->loop, $this->endpoint($server));
        $connection->authenticate(new ImapCredentials('user', 'secret'));

        return $connection;
    }
}
