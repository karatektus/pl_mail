<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\ImapClient;

use App\Infrastructure\ImapClient\Exception\ImapAuthenticationException;
use App\Infrastructure\ImapClient\ImapArgument;
use App\Infrastructure\ImapClient\ImapConnection;
use App\Infrastructure\ImapClient\ImapCredentials;
use App\Infrastructure\ImapClient\ImapEndpoint;
use App\Infrastructure\ImapClient\ImapIdle;
use App\Infrastructure\ImapClient\ImapLoop;
use App\Infrastructure\ImapClient\ImapResponse;
use App\Infrastructure\ImapClient\ImapSecurity;
use App\Service\Imap\ImapIdleListener;
use PHPUnit\Framework\TestCase;

/**
 * The IMAP client against a real server.
 *
 * Dovecot, the test stack's own (compose.test.yaml), because it is the IMAP
 * server most others are measured against and the one most self-hosted mail
 * runs on. What a scripted server cannot show is shown here: that the
 * client's reading of the RFCs is the one a server built by somebody else
 * has — STARTTLS, a login, a literal, and IDLE telling one session what
 * another did.
 *
 * Every test logs in as a user of its own. The server takes any name with
 * the stack's password and gives each its own mailbox, so the tests cannot
 * see each other's mail.
 *
 * Skipped where IMAP_TEST_HOST names no server, which is anywhere outside
 * the test stack.
 */
final class DovecotImapTest extends TestCase
{
    private const int IMAP_PORT  = 31143;
    private const int IMAPS_PORT = 31993;

    private string $host;
    private string $password;
    private ImapLoop $loop;

    protected function setUp(): void
    {
        $host     = getenv('IMAP_TEST_HOST');
        $password = getenv('IMAP_TEST_PASSWORD');

        if (false === $host || '' === $host || false === $password) {
            self::markTestSkipped('Set IMAP_TEST_HOST and IMAP_TEST_PASSWORD to run against an IMAP server; the test stack does.');
        }

        $this->host     = $host;
        $this->password = $password;
        $this->loop     = new ImapLoop();
    }

    /**
     * The certificate is the image's own and nothing trusts it, so it is not
     * checked; that it is TLS at all is what the login proves, since the
     * server refuses one in the clear on this port.
     */
    public function testStartTlsThenLogin(): void
    {
        $connection = $this->connect(ImapSecurity::StartTls, self::IMAP_PORT);

        self::assertTrue($connection->isAuthenticated);
        self::assertTrue($connection->supports('IDLE'), 'capabilities are read again after the login');
        self::assertSame(0, $connection->select('INBOX')->number('EXISTS'), 'a new user starts empty');

        $connection->logout();
        self::assertTrue($connection->isClosed);
    }

    public function testTlsFromTheFirstByte(): void
    {
        $connection = $this->connect(ImapSecurity::Tls, self::IMAPS_PORT);

        self::assertTrue($connection->isAuthenticated);

        $connection->logout();
    }

    public function testAWrongPasswordIsARefusedLoginRatherThanABrokenConnection(): void
    {
        $connection = ImapConnection::open($this->loop, new ImapEndpoint($this->host, self::IMAPS_PORT, ImapSecurity::Tls, false));

        try {
            $connection->authenticate(new ImapCredentials(self::user(), 'not-the-password'));
            self::fail('The login went through.');
        } catch (ImapAuthenticationException $e) {
            self::assertSame('NO', $e->response->status);
            self::assertFalse($connection->isClosed, 'a refused command leaves the connection usable');
        } finally {
            $connection->close();
        }
    }

    /**
     * The point of IDLE: one session hears, as it happens, about mail another
     * session put there. The message goes up as a literal — it has line
     * breaks — so this is also the literal encoding against a real server.
     */
    public function testIdleHearsAboutMailAnotherSessionAppends(): void
    {
        $user     = self::user();
        $watching = $this->connect(ImapSecurity::Tls, self::IMAPS_PORT, $user);
        $watching->select('INBOX');

        $idle = $watching->idle();

        $this->append($user, "Subject: Hello\r\n\r\nNew mail.\r\n");

        $responses = self::collect($idle, 'EXISTS');

        self::assertSame(1, self::find($responses, 'EXISTS')?->number);

        $idle->done();
        self::assertSame('OK', $watching->command('NOOP')->completion->status, 'the connection takes commands again after DONE');

        $watching->logout();
    }

    /** Mail read on a phone shows up as a FETCH carrying the new flags. */
    public function testIdleHearsAFlagChangedInAnotherSession(): void
    {
        $user = self::user();
        $this->append($user, "Subject: Read me\r\n\r\nBody.\r\n");

        $watching = $this->connect(ImapSecurity::Tls, self::IMAPS_PORT, $user);
        $watching->select('INBOX');
        $idle = $watching->idle();

        $other = $this->connect(ImapSecurity::Tls, self::IMAPS_PORT, $user);
        $other->select('INBOX');
        $other->command('STORE', ImapArgument::atom('1'), ImapArgument::atom('+FLAGS'), ImapArgument::list([ImapArgument::atom('\\Seen')]));
        $other->logout();

        $fetch = self::find(self::collect($idle, 'FETCH'), 'FETCH');

        self::assertNotNull($fetch, 'the change arrived');

        $notice = ImapIdleListener::flagChange($fetch);

        self::assertNotNull($notice, 'and is read as a flag change');
        self::assertTrue($notice->hasFlag('\\Seen'));

        $idle->done();
        $watching->logout();
    }

    private function connect(ImapSecurity $security, int $port, ?string $user = null): ImapConnection
    {
        $connection = ImapConnection::open($this->loop, new ImapEndpoint($this->host, $port, $security, false));
        $connection->authenticate(new ImapCredentials($user ?? self::user(), $this->password));

        return $connection;
    }

    private function append(string $user, string $message): void
    {
        $connection = $this->connect(ImapSecurity::Tls, self::IMAPS_PORT, $user);
        $connection->command('APPEND', ImapArgument::string('INBOX'), ImapArgument::string($message));
        $connection->logout();
    }

    /**
     * Wait, up to a few seconds, for the server to say $keyword.
     *
     * @return list<ImapResponse>
     */
    private static function collect(ImapIdle $idle, string $keyword): array
    {
        $seen  = [];
        $until = microtime(true) + 5;

        while (null === self::find($seen, $keyword) && microtime(true) < $until) {
            $seen = [...$seen, ...$idle->wait(1.0)];
        }

        return $seen;
    }

    /** @param list<ImapResponse> $responses */
    private static function find(array $responses, string $keyword): ?ImapResponse
    {
        foreach ($responses as $response) {
            if ($keyword === $response->keyword) {
                return $response;
            }
        }

        return null;
    }

    private static function user(): string
    {
        return 'imap-test-' . bin2hex(random_bytes(6));
    }
}
