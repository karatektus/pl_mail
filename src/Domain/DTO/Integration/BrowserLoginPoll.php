<?php

declare(strict_types=1);

namespace App\Domain\DTO\Integration;

use App\Entity\Integration\Integration;

/**
 * One answer to "has the person approved yet?", as BrowserLoginService gives
 * it to the controller.
 *
 * Three outcomes, and the connected one carries the saved connection plus the
 * probe's verdict on it: a credential obtained cleanly can still fail its first
 * request (a proxy, a missing app), and that is reported the way a manual save
 * reports it — saved, with the reason on the row.
 */
final readonly class BrowserLoginPoll
{
    private function __construct(
        public bool $isPending,
        public ?string $failure,
        public ?Integration $integration,
        public ?string $probeError,
    ) {
    }

    public static function pending(): self
    {
        return new self(true, null, null, null);
    }

    public static function failed(string $message): self
    {
        return new self(false, $message, null, null);
    }

    public static function connected(Integration $integration, ?string $probeError): self
    {
        return new self(false, null, $integration, $probeError);
    }

    public function isConnected(): bool
    {
        return null !== $this->integration;
    }
}
