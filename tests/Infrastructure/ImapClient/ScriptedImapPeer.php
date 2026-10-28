<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\ImapClient;

use App\Infrastructure\ImapClient\ImapLoop;

/**
 * The server's end of one connection in a ScriptedImapServer.
 */
final class ScriptedImapPeer
{
    private const float WAIT_SECONDS = 10.0;

    private string $buffer = '';

    /**
     * @param resource $stream
     */
    public function __construct(
        private readonly ImapLoop $loop,
        private mixed $stream,
    ) {
    }

    /** Send one line; the CRLF is added. */
    public function send(string $line): void
    {
        $this->write($line . "\r\n");
    }

    public function write(string $bytes): void
    {
        while ('' !== $bytes) {
            $written = @fwrite($this->stream, $bytes);

            if (false === $written) {
                throw new \RuntimeException('The client went away.');
            }

            $bytes = substr($bytes, $written);

            if ('' !== $bytes) {
                $this->loop->awaitWritable($this->stream, microtime(true) + self::WAIT_SECONDS);
            }
        }
    }

    /** The next line the client sent, without its CRLF. */
    public function line(): string
    {
        while (false === ($end = strpos($this->buffer, "\r\n"))) {
            $this->fill();
        }

        $line         = substr($this->buffer, 0, $end);
        $this->buffer = substr($this->buffer, $end + 2);

        return $line;
    }

    /** Exactly $count bytes, as a literal brings them. */
    public function bytes(int $count): string
    {
        while (strlen($this->buffer) < $count) {
            $this->fill();
        }

        $bytes        = substr($this->buffer, 0, $count);
        $this->buffer = substr($this->buffer, $count);

        return $bytes;
    }

    /**
     * Read the next command and check it is $command, whatever its tag.
     *
     * @return string the tag, to answer with
     */
    public function expect(string $command): string
    {
        $line = $this->line();

        if (1 !== preg_match('~^(\S+) (.*)$~', $line, $match) || $match[2] !== $command) {
            throw new \RuntimeException(sprintf('Expected "%s", the client sent "%s".', $command, $line));
        }

        return $match[1];
    }

    /** The greeting and login every script but the ones about them starts with. */
    public function greetAndLogin(string $capabilities = 'IMAP4rev1 IDLE'): void
    {
        $this->send(sprintf('* OK [CAPABILITY %s] Scripted server ready', $capabilities));

        $tag = $this->expect('LOGIN "user" "secret"');
        $this->send(sprintf('%s OK [CAPABILITY %s] Logged in', $tag, $capabilities));
    }

    public function startTls(): void
    {
        $deadline = microtime(true) + self::WAIT_SECONDS;

        while (true) {
            $done = @stream_socket_enable_crypto($this->stream, true, STREAM_CRYPTO_METHOD_TLS_SERVER);

            if (true === $done) {
                return;
            }

            if (false === $done) {
                throw new \RuntimeException('The TLS handshake failed on the server side.');
            }

            if (false === $this->loop->awaitReadable($this->stream, $deadline)) {
                throw new \RuntimeException('The TLS handshake timed out on the server side.');
            }
        }
    }

    public function close(): void
    {
        if (true === is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    private function fill(): void
    {
        $chunk = @fread($this->stream, 65536);

        if (false !== $chunk && '' !== $chunk) {
            $this->buffer .= $chunk;

            return;
        }

        if (true === feof($this->stream)) {
            throw new \RuntimeException('The client closed the connection.');
        }

        if (false === $this->loop->awaitReadable($this->stream, microtime(true) + self::WAIT_SECONDS)) {
            throw new \RuntimeException('The client said nothing.');
        }
    }
}
