<?php

declare(strict_types=1);

namespace App\Domain\DTO\Integration;

/**
 * What a browser login hands back: the same username and secret pair a person
 * would otherwise paste, so it is stored on Integration exactly as they are.
 */
final readonly class ObtainedCredential
{
    public function __construct(
        public string $username,
        #[\SensitiveParameter]
        public string $secret,
    ) {
    }
}
