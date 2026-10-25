<?php

declare(strict_types=1);

namespace App\Domain\DTO\Crypto;

/**
 * The usable keys one public key server had for an address
 * (App\Service\Crypto\KeyServerLookup), and which server that was — the host
 * stays with the key, so the person can be told where it came from.
 */
final readonly class KeyServerAnswer
{
    /**
     * @param list<PublicKeyInfo> $keys never empty: a server with nothing
     *        usable is not an answer, and the next one is asked
     */
    public function __construct(
        public string $host,
        public array  $keys,
    ) {}
}
