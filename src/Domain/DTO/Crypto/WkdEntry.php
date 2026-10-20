<?php

declare(strict_types=1);

namespace App\Domain\DTO\Crypto;

use App\Domain\Enum\Crypto\WkdState;

/**
 * One of a person's addresses as plMail's Web Key Directory sees it: whether
 * the key is published, the two URLs a client asks, and the key that would be
 * served — what Settings → Encryption shows per address, and what the check
 * there compares against.
 */
final readonly class WkdEntry
{
    /**
     * @param array{advanced: string, direct: string} $urls
     */
    public function __construct(
        public string   $email,
        public WkdState $state,
        public array    $urls,
        public ?string  $fingerprint,
    ) {
    }
}
