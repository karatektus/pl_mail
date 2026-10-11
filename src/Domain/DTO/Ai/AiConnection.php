<?php

declare(strict_types=1);

namespace App\Domain\DTO\Ai;

use App\Domain\Enum\Ai\AiProvider;

/**
 * Where a model is, and what has to be shown at the door.
 *
 * WHY THE ADDRESS AND THE KEY TRAVEL TOGETHER
 * ───────────────────────────────────────────
 * They were two separate columns read at two separate places, and the second
 * place did not exist: the key an administrator typed was stored, encrypted, and
 * never sent. Every call site passed the address alone, so there was nowhere for
 * it to go. An object that carries both is one argument a caller cannot give
 * half of.
 *
 * It is also what keeps a key from following an address it was not issued for.
 * A request is built from one of these and nothing else, so a key can only ever
 * reach the address it is stored beside.
 *
 * THE DIALECT IS PART OF IT. An address says where and not how: the same host
 * can speak Ollama's own API at its root and the OpenAI one under `/v1`, and
 * which client a request is built by has to be decided by the same object that
 * says where it goes.
 *
 * A VALUE, NOT THE SETTINGS
 * ─────────────────────────
 * Built by {@see \App\Entity\Ai\AiSettings::connectionFor()} and read by the
 * clients. It holds no reference back to the row, so a client can keep one for
 * the length of a request without holding the configuration open, and a test of
 * an address nobody has saved yet is the same object as the saved one.
 */
final readonly class AiConnection
{
    public function __construct(
        public string     $baseUrl,
        public ?string    $token = null,
        public AiProvider $provider = AiProvider::Ollama,
        /** @var array<string, string> extra headers, by name. {@see \App\Domain\Ai\ConnectionHeaders} */
        public array      $headers = [],
    ) {
    }

    /** Whether there is an address to ask at all. */
    public function isSet(): bool
    {
        return '' !== trim($this->baseUrl);
    }

    /**
     * What every request to this address carries.
     *
     * The token as a bearer, then the extra headers on top. On top is the
     * order that matters for one name: an `Authorization` typed among the extra
     * headers replaces the bearer, which is how basic auth in front of an
     * Ollama is spelled. Matched without regard to case, because header names
     * have none and two Authorization headers on one request is a request
     * different proxies read differently.
     *
     * Empty when there is nothing to show, which is an Ollama on the LAN with
     * no proxy in front of it — the commonest installation there is — and which
     * lets a client skip building a second HTTP client for it.
     *
     * @return array<string, string>
     */
    public function requestHeaders(): array
    {
        $headers = [];

        if (null !== $this->token && '' !== trim($this->token)) {
            $headers['Authorization'] = 'Bearer ' . trim($this->token);
        }

        foreach ($this->headers as $name => $value) {
            foreach (array_keys($headers) as $existing) {
                if (0 === strcasecmp($existing, $name)) {
                    unset($headers[$existing]);
                }
            }

            $headers[$name] = $value;
        }

        return $headers;
    }
}
