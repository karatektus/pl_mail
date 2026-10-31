<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\ImapClient;

use App\Infrastructure\ImapClient\ImapLoop;
use App\Infrastructure\ImapClient\ImapTask;

/**
 * An IMAP server that follows a script, on a real socket, on the same loop as
 * the client under test.
 *
 * Real sockets because the claims worth testing are about what goes over the
 * wire and when — a literal waited for, a STARTTLS answered, one slow server
 * not holding up another — and a stub would only report what it was told to.
 * On the same loop because the client never blocks, so the server cannot
 * either: both are tasks, and the test drives the loop.
 *
 * The script is a function of the peer that connected. An expectation that
 * fails throws inside the server's task; finish() hands that back to the
 * test, so a script that went wrong fails the test rather than hanging it.
 */
final class ScriptedImapServer
{
    public readonly int $port;

    /** @var resource */
    private mixed $socket;

    private ?ImapTask $task = null;

    /**
     * @param (callable(ScriptedImapPeer): void) $script
     * @param array<string, mixed>|null          $tls    SSL context options; TLS from the first byte when given
     */
    public function __construct(
        private readonly ImapLoop $loop,
        callable $script,
        private readonly ?array $tls = null,
    ) {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, stream_context_create(['ssl' => $tls ?? []]));

        if (false === $socket) {
            throw new \RuntimeException(sprintf('Could not listen: %s', $errorMessage));
        }

        stream_set_blocking($socket, false);

        $this->socket = $socket;
        $this->port   = (int) substr((string) strrchr((string) stream_socket_get_name($socket, false), ':'), 1);
        $this->task   = $loop->async(fn () => $script($this->accept()));
    }

    /** Rethrow what the script threw, if it threw. */
    public function finish(): void
    {
        if (null !== $this->task && true === $this->task->isFinished) {
            $this->task->result();
        }

        fclose($this->socket);
    }

    public function isFinished(): bool
    {
        return null !== $this->task && true === $this->task->isFinished;
    }

    private function accept(): ScriptedImapPeer
    {
        if (false === $this->loop->awaitReadable($this->socket, microtime(true) + 10)) {
            throw new \RuntimeException('Nobody connected.');
        }

        $stream = stream_socket_accept($this->socket, 0);

        if (false === $stream) {
            throw new \RuntimeException('The connection could not be accepted.');
        }

        stream_set_blocking($stream, false);

        $peer = new ScriptedImapPeer($this->loop, $stream);

        if (null !== $this->tls) {
            $peer->startTls();
        }

        return $peer;
    }
}
