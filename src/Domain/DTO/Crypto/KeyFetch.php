<?php

declare(strict_types=1);

namespace App\Domain\DTO\Crypto;

/**
 * What one request that left the server for a key brought back
 * (App\Service\Crypto\KeyFetcher): whether the host answered at all, with
 * which status, and the body.
 *
 * "Not reached" and "reached, but nothing there" are different answers to a
 * person checking their own Web Key Directory — the first is DNS, TLS or a
 * firewall, the second a missing file — so the two are kept apart here
 * rather than both becoming null, as they do for a lookup that only wants a
 * key.
 */
final readonly class KeyFetch
{
    /**
     * @param bool    $reached whether an HTTP answer came back at all: false
     *                         for a name that does not resolve, a private
     *                         address, a refused connection, TLS, a timeout,
     *                         or an answer over the size limit (a redirect
     *                         is an answer: it is reached, with its status)
     * @param int     $status  the HTTP status, 0 when not reached
     * @param ?string $body    the answer, null when not reached or empty
     */
    public function __construct(
        public bool    $reached,
        public int     $status = 0,
        public ?string $body = null,
    ) {}

    public static function unreached(): self
    {
        return new self(false);
    }

    /** A 200 with a body: the only answer a lookup reads a key from. */
    public function found(): ?string
    {
        return 200 === $this->status ? $this->body : null;
    }
}
