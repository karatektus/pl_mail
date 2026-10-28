<?php

declare(strict_types=1);

namespace App\Infrastructure\ImapClient;

/**
 * A command being written, in the pieces it has to be sent in.
 *
 * Usually one piece. A synchronising literal ends a piece: what comes after
 * it may only be sent once the server has said `+`. See ImapArgument.
 */
final class ImapCommandBuffer
{
    /** @var list<string> */
    private array $finished = [];

    private string $current;

    public function __construct(string $start)
    {
        $this->current = $start;
    }

    public function append(string $text): void
    {
        $this->current .= $text;
    }

    /** End the piece here; the rest waits for the server's go-ahead. */
    public function waitForServer(): void
    {
        $this->finished[] = $this->current;
        $this->current    = '';
    }

    /**
     * The command, ended with its CRLF.
     *
     * @return list<string>
     */
    public function pieces(): array
    {
        return [...$this->finished, $this->current . "\r\n"];
    }
}
