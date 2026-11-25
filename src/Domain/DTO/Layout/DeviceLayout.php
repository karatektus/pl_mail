<?php

declare(strict_types=1);

namespace App\Domain\DTO\Layout;

use App\Domain\Enum\Layout\PaneKind;

/**
 * What this device remembers about a workspace (#755): where the dividers
 * were dragged to, and which pane, if any, is at full width.
 *
 * Per device because the user asked for exactly that: the workspaces follow
 * the account, the sizes stay with the screen they were dragged on. A laptop
 * and a 32-inch monitor want different splits of the same arrangement.
 *
 * In a cookie rather than localStorage so the server can draw the first paint
 * at the right sizes. Read from storage by a script, every load would paint
 * the account's proportions and then jump to the device's. The browser writes
 * it (layout--workspace) and the server only reads it:
 *
 *     {"w1": {"s": {"s1": [0.3, 0.7]}, "m": "calendar"}}
 *
 * Every value is checked as untrusted input and anything that does not fit
 * the workspace it claims to belong to is ignored, not repaired: a stale size
 * list for a split that has since gained a pane is simply not used.
 */
final readonly class DeviceLayout
{
    public const string COOKIE = 'plmail_layout';

    /** Well under the 4 KiB a browser keeps for one cookie. */
    private const int MAX_BYTES = 3500;

    /** @param array<string, mixed> $decoded */
    private function __construct(
        private array $decoded,
    ) {
    }

    /** The cookie's value as the request carried it; null or garbage is a device that remembers nothing. */
    public static function fromCookie(mixed $raw): self
    {
        $all = is_string($raw) && strlen($raw) <= self::MAX_BYTES ? json_decode($raw, true) : null;

        return new self(is_array($all) ? $all : []);
    }

    /**
     * The shares to draw a split at: this device's, when it has a valid set
     * for this split, else the workspace's own.
     *
     * @return list<float>
     */
    public function sizes(string $workspaceId, LayoutSplit $split): array
    {
        $stored = $this->entry($workspaceId)['s'][$split->id] ?? null;

        if (false === is_array($stored) || false === array_is_list($stored) || count($stored) !== count($split->sizes)) {
            return $split->sizes;
        }

        foreach ($stored as $share) {
            if ((false === is_int($share) && false === is_float($share)) || $share <= 0 || $share > 1000) {
                return $split->sizes;
            }
        }

        $total = array_sum($stored);

        return array_map(static fn (int|float $share): float => $share / $total, $stored);
    }

    /** The pane this device last put at full width in that workspace, if any. */
    public function maximized(string $workspaceId): ?PaneKind
    {
        $kind = $this->entry($workspaceId)['m'] ?? null;

        return is_string($kind) ? PaneKind::tryFrom($kind) : null;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function entry(string $workspaceId): array
    {
        $entry = $this->decoded[$workspaceId] ?? null;

        return is_array($entry) ? $entry : [];
    }
}
