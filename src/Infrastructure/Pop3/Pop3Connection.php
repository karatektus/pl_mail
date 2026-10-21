<?php

declare(strict_types=1);

namespace App\Infrastructure\Pop3;

use App\Domain\Interface\DeadlineClosable;
use App\Service\Imap\ImapDeadline;

/**
 * A POP3 conversation, written out rather than borrowed.
 *
 * ── Why not a library ────────────────────────────────────────────────────────
 * The IMAP side uses webklex/php-imap, which is a large and well-tested MIME
 * parser behind a thin protocol client. There is no equivalent for POP3:
 * the runtimes on Packagist are thin clients of their own, and the PHP
 * `imap` extension — which can do POP3 — is not in the image (the Dockerfile
 * installs neither `imap` nor `imap-ssl`, and webklex reads IMAP over a raw
 * socket precisely so it does not have to be). Adding an extension means
 * rebuilding the image on two architectures for a protocol whose command set
 * is fifteen verbs.
 *
 * But the part that actually matters is not the verbs. It is what comes back
 * after RETR: a full RFC 822 message that has to be parsed exactly as an IMAP
 * fetch is. That already exists — webklex's Message::fromString() — and
 * reusing it is the whole reason this class stops at "here are the bytes".
 * See Pop3AccountSyncer for the handover.
 *
 * ── What is guaranteed, and what is not ──────────────────────────────────────
 * The connection is TLS for every port in the form's default list, cert
 * verification is always on (matching the IMAP side, which sets
 * validate_cert => true unconditionally — and which is why Proton Bridge's
 * self-signed certificate fails there too), and every response is checked
 * against +OK/-ERR rather than assumed.
 *
 * Nothing here has retry logic. A dropped socket is the syncer's problem, and
 * its answer is to leave the mailbox's high-water mark where it was so the
 * next sync asks again.
 *
 * ── Sessions and the delete mark ─────────────────────────────────────────────
 * POP3 has a transaction state that lasts one connection: DELE marks a
 * message, and QUIT is what makes the marks real. A session that ends any
 * other way rolls them back, which is exactly the failure behaviour wanted —
 * a sync that dies half way through deleting must not have deleted anything.
 * So this class keeps the marks and sends them only from quit().
 */
final class Pop3Connection implements DeadlineClosable
{
    /** How long a single read may block before it is treated as a dropped server. */
    private const int TIMEOUT_SECONDS = 60;

    /** @var resource|null */
    private $stream = null;

    /** @var array<string, true> UIDLs marked for deletion this session */
    private array $marked = [];

    /**
     * Held only until open() has watched the session with it; see the call
     * there. Not kept afterwards: the deadline's list is weak, so a session
     * that kept it would be keeping nothing but a reference to a service.
     */
    private function __construct()
    {
    }

    /**
     * Open and authenticate.
     *
     * $encryption takes the form's own vocabulary — ssl, starttls, none —
     * because a POP3 account shares the account form with an IMAP one and
     * nothing is gained by translating at the call site. `ssl` is implicit TLS
     * (port 995), `starttls` upgrades in-band (port 110), `none` is plaintext.
     *
     * @throws Pop3Exception when the server refuses the connection, the
     *                       certificate, or the credentials
     */
    public static function open(
        string       $host,
        int          $port,
        ?string      $encryption,
        string       $username,
        string       $password,
        ImapDeadline $deadline,
    ): self {
        $connection = new self();

        $connection->stream = $connection->dial($host, $port, $encryption);

        // The deadline closes a connection that outlives its handler. POP3
        // sessions are short — a greeting, a few hundred commands, QUIT — so
        // the alarm mostly matters for a server that accepts the socket and
        // then says nothing, which is exactly the stall that has no read
        // timeout to catch it.
        $deadline->watchSession($connection);

        try {
            $connection->expect('+OK');

            if ('starttls' === $encryption) {
                $connection->command('STLS', '+OK');
                $connection->enableTls($host);
            }

            // USER/PASS rather than APOP. APOP needs the server's timestamp
            // and an MD5 digest, and RFC 5034's other options (SASL) are not
            // offered by the providers this exists for. Over TLS the password
            // is not on the wire in clear, which is the property USER/PASS
            // lacks on its own.
            $connection->command('USER ' . $username, '+OK');
            $connection->command('PASS ' . $password, '+OK');
        } catch (\Throwable $failure) {
            $connection->closeQuietly();

            throw $failure;
        }

        return $connection;
    }

    /**
     * The mailbox as the server describes it: one line per message, in the
     * order it is numbered.
     *
     * @return list<Pop3Listing> in server order
     *
     * @throws Pop3Exception
     */
    public function list(): array
    {
        // The count and the octet total ride on the +OK status line, which
        // command() has already consumed. What is left is the listing itself.
        $lines = $this->command('LIST', '+OK');

        $listing = [];

        foreach ($lines as $line) {
            // `1 215054` — the number, then the size on the server, which is
            // the size on the wire and not necessarily the size of the message
            // after decoding. Only the number is needed here; the size is kept
            // because a caller rebuilding a message can use it to sanity-check
            // what RETR returned.
            $parts = explode(' ', trim($line));

            if (2 !== count($parts) || false === ctype_digit($parts[0])) {
                continue;
            }

            $listing[] = new Pop3Listing((int) $parts[0], (int) $parts[1]);
        }

        return $listing;
    }

    /**
     * The server's stable per-message identifier, keyed by message number.
     *
     * This is what makes keeping mail on the server workable. Message numbers
     * restart at 1 after a deletion, so the number a message had on the last
     * sync means nothing on this one; UIDL stays with the message for as long
     * as it exists (RFC 1939 §7), and is the only thing a re-fetch may be
     * recognised by.
     *
     * @return array<int, string> message number => UIDL
     *
     * @throws Pop3Exception
     */
    public function uidl(): array
    {
        // Same shape as LIST: the +OK line is already gone. The count is not
        // needed here — the listing's own length is the answer.
        $lines = $this->command('UIDL', '+OK');

        $ids = [];

        foreach ($lines as $line) {
            // `1 AXgQYtzw` — a UIDL is up to 70 visible ASCII characters and
            // must not contain a space, so one split on the first space is
            // exact.
            $parts = explode(' ', trim($line), 2);

            if (2 !== count($parts) || false === ctype_digit($parts[0]) || '' === $parts[1]) {
                continue;
            }

            $ids[(int) $parts[0]] = $parts[1];
        }

        return $ids;
    }

    /**
     * The original bytes of one message, dot-unstuffed.
     *
     * The server's answer is `+OK <size> octets` followed by the message and a
     * line consisting of a single period. Two transformations RFC 1939 §3
     * requires the client to undo are done here: a line starting with a period
     * had one added before transmission, and the terminating period is not
     * part of the message.
     *
     * The bytes come back exactly as the sender wrote them — CRLF line
     * endings, transfer encodings intact — which is what the IMAP path hands
     * to Message::fromString() and therefore the only shape that parses the
     * same way.
     *
     * @throws Pop3Exception
     */
    public function retrieve(int $number): string
    {
        $this->write('RETR ' . $number);

        $reply = $this->readLine();

        if (false === str_starts_with($reply, '+OK')) {
            throw new Pop3Exception(sprintf('The server refused to send message %d: %s', $number, $reply));
        }

        $lines = [];

        while (true) {
            $line = $this->readLine();

            if ('.' === $line) {
                break;
            }

            // Dot-stuffing: every line the client is responsible for starting
            // with a period was prefixed with one on the wire.
            $lines[] = str_starts_with($line, '.') ? substr($line, 1) : $line;
        }

        // Each line was read WITHOUT its CRLF — readLine() trims it — so the
        // message is rebuilt with one after every line including the last.
        // That is right for a header block and harmless for a body; what would
        // not be right is joining them without, which turns a multipart body
        // into one enormous line and makes every boundary invisible.
        return implode("\r\n", $lines) . "\r\n";
    }

    /**
     * Mark one message for deletion, without deleting it yet.
     *
     * The mark is real to the server as soon as DELE returns OK, but it is
     * rolled back unless this session ends with QUIT. Keeping it here as well
     * is what lets a caller ask whether a message is already marked (a retry
     * within one session) and what makes the class's own contract — nothing is
     * deleted that quit() was not asked for — sayable.
     *
     * @throws Pop3Exception
     */
    public function markDeleted(int $number, string $uidl): void
    {
        $this->command('DELE ' . $number, '+OK');

        $this->marked[$uidl] = true;
    }

    public function isMarkedDeleted(string $uidl): bool
    {
        return true === isset($this->marked[$uidl]);
    }

    /**
     * End the session, which is what makes the marks real, and close.
     *
     * A session that has nothing marked still says QUIT: it is what tells the
     * server the client finished cleanly rather than vanished, and some servers
     * hold the mailbox locked until they hear it.
     */
    public function quit(): void
    {
        if (null === $this->stream) {
            return;
        }

        try {
            $this->command('QUIT', '+OK');
        } catch (Pop3Exception) {
            // The server hung up instead of answering QUIT. RFC 1939 allows it
            // and some do; the marks were sent with the command either way.
        } finally {
            $this->closeQuietly();
        }
    }

    /**
     * Hang up without QUIT, which ROLLS BACK every deletion marked this
     * session.
     *
     * For the path that failed: a sync dies, and the messages it had only got
     * as far as marking stay on the server.
     */
    public function abort(): void
    {
        $this->closeQuietly();
    }

    /**
     * @return list<string> the lines after the status line, CRLF trimmed
     *
     * @throws Pop3Exception
     */
    private function command(string $command, string $expect): array
    {
        $this->write($command);

        $reply = $this->readLine();

        if (false === str_starts_with($reply, $expect)) {
            throw new Pop3Exception(sprintf('The server refused %s: %s', strtok($command, ' '), $reply));
        }

        $lines = [];

        // A multi-line answer is announced by the status line alone; a
        // single-line one has nothing behind it and the next read would block
        // until the timeout. The distinction is made by the caller knowing
        // which of its commands are multi-line — LIST, UIDL and RETR are, and
        // nothing else in this class is.
        if (true === in_array(strtok($command, ' '), ['LIST', 'UIDL'], true)) {
            while (true) {
                $line = $this->readLine();

                if ('.' === $line) {
                    break;
                }

                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * @return resource
     *
     * @throws Pop3Exception
     */
    private function dial(string $host, int $port, ?string $encryption)
    {
        $address = sprintf('tcp://%s:%d', $host, $port);

        // The connection context is where certificate verification is asked
        // for. verify_peer and verify_peer_name are the two that matter and
        // both are on, with the system CA store — the same posture the IMAP
        // client takes.
        $context = stream_context_create([
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
                'peer_name'        => $host,
                'SNI_enabled'      => true,
            ],
        ]);

        $stream = @stream_socket_client(
            $address,
            $errno,
            $error,
            self::TIMEOUT_SECONDS,
            STREAM_CLIENT_CONNECT,
            $context,
        );

        if (false === $stream) {
            throw new Pop3Exception(sprintf(
                'Could not reach %s: %s',
                $address,
                '' !== $error ? $error : ('socket error ' . $errno),
            ));
        }

        stream_set_timeout($stream, self::TIMEOUT_SECONDS);

        // Implicit TLS is a socket option rather than a handshake after the
        // fact: `ssl://` in the address would work too and does the same
        // thing, but taking it here keeps the plaintext and STARTTLS paths
        // sharing one dial.
        if ('ssl' === $encryption) {
            $this->stream = $stream;
            $this->enableTls($host);

            return $this->stream;
        }

        return $stream;
    }

    /**
     * @throws Pop3Exception
     */
    private function enableTls(string $host): void
    {
        $stream = $this->stream;

        if (null === $stream) {
            throw new Pop3Exception('Cannot start TLS on a connection that is not open.');
        }

        $method = STREAM_CRYPTO_METHOD_TLS_CLIENT;

        if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
            $method |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        }

        if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
            $method |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
        }

        // The peer name is re-stated because crypto_enable() reuses the
        // context the socket was opened with, and the verification options
        // travel with the context rather than the stream.
        if (false === @stream_socket_enable_crypto($stream, true, $method)) {
            throw new Pop3Exception(sprintf('The TLS handshake with %s failed.', $host));
        }
    }

    /**
     * @throws Pop3Exception
     */
    private function write(string $command): void
    {
        $stream = $this->stream;

        if (null === $stream) {
            throw new Pop3Exception('The POP3 connection is not open.');
        }

        if (false === @fwrite($stream, $command . "\r\n")) {
            throw new Pop3Exception('The connection dropped while sending a command.');
        }
    }

    /**
     * @throws Pop3Exception
     */
    private function readLine(): string
    {
        $stream = $this->stream;

        if (null === $stream) {
            throw new Pop3Exception('The POP3 connection is not open.');
        }

        $line = fgets($stream);

        if (false === $line) {
            throw new Pop3Exception('The connection dropped, or the server stopped answering.');
        }

        return rtrim($line, "\r\n");
    }

    /**
     * @throws Pop3Exception
     */
    private function expect(string $prefix): void
    {
        $reply = $this->readLine();

        if (false === str_starts_with($reply, $prefix)) {
            throw new Pop3Exception(sprintf('The server did not greet us with %s: %s', $prefix, $reply));
        }
    }

    /**
     * Shut the socket down so a read in progress fails at once.
     *
     * STREAM_SHUT_RDWR rather than fclose(), for the reason ImapDeadline
     * gives on the IMAP side: the session's own `finally` will still close it,
     * and with the resource closed outright that close has to survive a
     * stream it can no longer inspect.
     */
    public function closeForDeadline(): void
    {
        $stream = $this->stream;

        if (null !== $stream && true === is_resource($stream)) {
            if (false === @stream_socket_shutdown($stream, STREAM_SHUT_RDWR)) {
                @fclose($stream);
            }
        }
    }

    public function isOpen(): bool
    {
        return null !== $this->stream && true === is_resource($this->stream);
    }

    private function closeQuietly(): void
    {
        $stream = $this->stream;
        $this->stream = null;

        if (null !== $stream && true === is_resource($stream)) {
            @fclose($stream);
        }
    }

    public function __destruct()
    {
        // A session that was never quit() still gets its socket closed. The
        // server rolls the transaction back on its own, which is the safe
        // outcome for every path that reaches here.
        $this->closeQuietly();
    }
}
