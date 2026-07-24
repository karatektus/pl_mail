<?php

declare(strict_types=1);

namespace App\Domain\DTO;

use App\Domain\Enum\PushHealth;
use DateTimeImmutable;

/**
 * Everything a template needs to render the push control for one account.
 *
 * Exists so the partial does not need four separate variables threaded through
 * every include. Two call sites render it — the accounts settings pane and
 * AccountPushController's Turbo response — and both would otherwise have to
 * assemble the same set by hand and stay in sync forever.
 */
final readonly class PushStatus
{
    public function __construct(
        public bool                $supported,
        public bool                $enabled,
        public bool                $configured,
        public PushHealth          $health,
        public ?DateTimeImmutable  $expiresAt = null,
        public bool                $failed = false,
    ) {}

    /**
     * Provider has no push mechanism at all (IMAP). The partial renders
     * nothing in this case rather than the caller wrapping it in a conditional.
     */
    public static function unsupported(): self
    {
        return new self(
            supported:  false,
            enabled:    false,
            configured: false,
            health:     PushHealth::Inactive,
        );
    }

    /**
     * The toggle is only interactive when push is either already on (so it can
     * be turned off) or the deployment could actually deliver it.
     */
    public function isToggleable(): bool
    {
        if (true === $this->enabled) {
            return true;
        }

        return $this->configured;
    }

    public function withFailure(): self
    {
        return new self(
            supported:  $this->supported,
            enabled:    $this->enabled,
            configured: $this->configured,
            health:     $this->health,
            expiresAt:  $this->expiresAt,
            failed:     true,
        );
    }
}
