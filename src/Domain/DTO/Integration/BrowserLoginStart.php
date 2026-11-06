<?php

declare(strict_types=1);

namespace App\Domain\DTO\Integration;

/**
 * A browser login that has begun: where the person goes to approve, and what
 * the flow needs to collect the result (see BrowserLoginFlowInterface).
 *
 * The state can be as good as a credential for the few minutes it lives — a
 * Nextcloud poll token is exchanged for an app password by whoever presents it
 * first — so it never leaves the server and is stored encrypted.
 */
final readonly class BrowserLoginStart
{
    /**
     * @param array<string,string> $state
     */
    public function __construct(
        public string $loginUrl,
        #[\SensitiveParameter]
        public array $state,
    ) {
    }
}
