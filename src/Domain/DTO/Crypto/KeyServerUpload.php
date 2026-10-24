<?php

declare(strict_types=1);

namespace App\Domain\DTO\Crypto;

use App\Domain\Enum\Crypto\KeyPublicationState;

/**
 * What a key server answered to an upload or a confirmation request
 * (App\Domain\Interface\KeyPublisherInterface::read()): the state of each of
 * the key's addresses there, and the token a confirmation request needs.
 */
final readonly class KeyServerUpload
{
    /**
     * @param array<string, KeyPublicationState> $states by lower-case address
     * @param ?string                            $token  short-lived; never stored
     */
    public function __construct(
        public string  $fingerprint,
        public array   $states,
        public ?string $token,
    ) {}
}
