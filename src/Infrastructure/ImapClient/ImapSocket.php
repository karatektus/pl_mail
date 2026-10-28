<?php

declare(strict_types=1);

namespace App\Infrastructure\ImapClient;

use App\Infrastructure\ImapClient\Exception\ImapConnectionException;
use App\Infrastructure\ImapClient\Exception\ImapTimeoutException;

/**
 * A non-blocking TCP connection, optionally under TLS, that waits through an
 * ImapLoop instead of in the kernel.
 *
 * Every call takes an absolute deadline rather than a timeout, so a sequence
 * of steps can share one budget without each step restarting the clock.
 *
 * THE TLS BUFFER
 * ──────────────
 * select() reports the socket, and TLS sits above it: one record off the wire
 * can hold more than one read asks for, and what is left of it waits inside
 * OpenSSL where select() cannot see it. A reader that took one chunk per
 * wake-up would then sleep on data it already has, until the server happened
 * to send something else. read() therefore takes everything there is, until
 * a read comes back empty, every time it is woken. ImapLoop covers the other
 * half: bytes PHP's stream layer has buffered count as readable there.
 */
final class ImapSocket
{
    /** The most one read() call asks for; it loops until the stream is dry. */
    private const int CHUNK_BYTES = 65536;

    /** @var resource */
    private mixed $stream;

    public private(set) bool $isEncrypted = false;

    /**
     * @param resource $stream
     */
    private function __construct(
        private readonly ImapLoop $loop,
        mixed $stream,
        public readonly string $peer,
    ) {
        $this->stream = $stream;
    }

    /**
     * Open a TCP connection without blocking on the server.
     *
     * The connect itself is asynchronous; only resolving the name blocks, see
     * ImapLoop. A refused connection and an unreachable one both surface as
     * the socket becoming writable with no peer behind it.
     */
    public static function connect(ImapLoop $loop, string $host, int $port, float $deadline): self
    {
        $peer    = sprintf('%s:%d', $host, $port);
        $address = false !== filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
            ? sprintf('tcp://[%s]:%d', $host, $port)
            : sprintf('tcp://%s', $peer);

        [$stream, $warning] = self::capture(static fn () => stream_socket_client(
            $address,
            $errorCode,
            $errorMessage,
            0,
            STREAM_CLIENT_CONNECT | STREAM_CLIENT_ASYNC_CONNECT,
            stream_context_create(),
        ));

        if (false === $stream) {
            throw new ImapConnectionException(sprintf('Could not connect to %s: %s', $peer, $warning ?? 'unknown error'));
        }

        stream_set_blocking($stream, false);

        $socket = new self($loop, $stream, $peer);

        if (false === $loop->awaitWritable($stream, $deadline)) {
            $socket->close();

            throw new ImapTimeoutException(sprintf('Connecting to %s timed out.', $peer));
        }

        if (false === stream_socket_get_name($stream, true)) {
            $socket->close();

            throw new ImapConnectionException(sprintf('Could not connect to %s: connection refused or unreachable.', $peer));
        }

        return $socket;
    }

    /**
     * Turn the connection into a TLS one: at once for implicit TLS, or after
     * a STARTTLS the server has agreed to.
     *
     * The certificate is checked against $peerName — the host as the account
     * names it, which is also what goes out as SNI. ssl:// would do the same
     * in one blocking call; this does it a round trip at a time.
     */
    public function enableTls(string $peerName, bool $verifyCertificate, float $deadline): void
    {
        stream_context_set_options($this->stream, ['ssl' => [
            'peer_name'         => $peerName,
            'verify_peer'       => $verifyCertificate,
            'verify_peer_name'  => $verifyCertificate,
            'allow_self_signed' => false === $verifyCertificate,
            'SNI_enabled'       => true,
        ]]);

        while (true) {
            [$result, $warning] = self::capture(fn () => stream_socket_enable_crypto(
                $this->stream,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT,
            ));

            if (true === $result) {
                $this->isEncrypted = true;

                return;
            }

            if (false === $result) {
                throw new ImapConnectionException(sprintf('TLS with %s failed: %s', $this->peer, $warning ?? 'unknown error'));
            }

            // 0: the handshake wants another round trip.
            if (false === $this->loop->awaitReadable($this->stream, $deadline)) {
                throw new ImapTimeoutException(sprintf('TLS with %s timed out.', $this->peer));
            }
        }
    }

    /** Send all of it, waiting for room as often as the socket needs. */
    public function write(string $bytes, float $deadline): void
    {
        while ('' !== $bytes) {
            [$written, $warning] = self::capture(fn () => fwrite($this->stream, $bytes));

            if (false === $written) {
                throw new ImapConnectionException(sprintf('Writing to %s failed: %s', $this->peer, $warning ?? 'connection closed'));
            }

            $bytes = substr($bytes, $written);

            if ('' === $bytes) {
                return;
            }

            if (false === $this->loop->awaitWritable($this->stream, $deadline)) {
                throw new ImapTimeoutException(sprintf('Writing to %s timed out.', $this->peer));
            }
        }
    }

    /**
     * Wait for bytes and take every one that has arrived.
     *
     * @return string empty only when the deadline passed with nothing to read
     *
     * @throws ImapConnectionException when the server has closed the connection
     */
    public function read(float $deadline): string
    {
        while (true) {
            $bytes = $this->drain();

            if ('' !== $bytes) {
                return $bytes;
            }

            if (true === feof($this->stream)) {
                throw new ImapConnectionException(sprintf('%s closed the connection.', $this->peer));
            }

            // Readable and still empty happens: a TLS record that carried no
            // application data, such as a TLS 1.3 session ticket. Wait again.
            if (false === $this->loop->awaitReadable($this->stream, $deadline)) {
                return '';
            }
        }
    }

    public function close(): void
    {
        if (true === is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    public function isOpen(): bool
    {
        return true === is_resource($this->stream) && false === feof($this->stream);
    }

    /**
     * End the conversation under whoever is reading it, from a signal handler.
     *
     * Shut down rather than closed: the reader is somewhere inside this class
     * holding the resource, and closing it there would make its next call a
     * TypeError on a closed resource instead of the end-of-file every reader
     * here already handles. See ImapDeadline.
     */
    public function shutDown(): void
    {
        if (true === is_resource($this->stream)) {
            @stream_socket_shutdown($this->stream, STREAM_SHUT_RDWR);
        }
    }

    private function drain(): string
    {
        $bytes = '';

        while (true) {
            if (false === is_resource($this->stream)) {
                throw new ImapConnectionException(sprintf('The connection to %s is closed.', $this->peer));
            }

            [$chunk] = self::capture(fn () => fread($this->stream, self::CHUNK_BYTES));

            if (false === $chunk || '' === $chunk) {
                return $bytes;
            }

            $bytes .= $chunk;
        }
    }

    /**
     * Run a stream call and keep its warning as text rather than letting it
     * reach the error handler. The stream functions report the reason for a
     * failure — a refused connection, a certificate that does not match — only
     * as a warning, and that reason is what an exception here should carry.
     *
     * @return array{0: mixed, 1: string|null}
     */
    private static function capture(callable $call): array
    {
        $warning = null;

        set_error_handler(static function (int $level, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            return [$call(), $warning];
        } finally {
            restore_error_handler();
        }
    }
}
