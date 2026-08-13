<?php

declare(strict_types=1);

namespace App\Domain\Enum\Health;

/**
 * How badly a health issue is hurting the user right now.
 *
 * Three levels, and the boundaries are drawn by consequence rather than by how
 * alarming the underlying error looks:
 *
 *  - Critical: mail or calendar data is NOT arriving, and will not until the
 *    user does something. A dead OAuth grant is the archetype.
 *  - Warning: something is broken but the data still flows, or the loss is
 *    bounded and already over — abandoned background work, an integration that
 *    only matters when you reach for it.
 *  - Notice: working, more slowly or less directly than it should. Push having
 *    fallen back to polling is the whole reason this level exists.
 *
 * The distinction is load-bearing, not decorative. A health page that paints
 * "your mail is slightly delayed" the same red as "your mail has stopped" is a
 * page people learn to close, and then the red that mattered is unread too.
 * Notice therefore never lights the topbar indicator — see AccountHealthGlobal.
 */
enum HealthSeverity: string
{
    case Critical = 'critical';
    case Warning  = 'warning';
    case Notice   = 'notice';

    /**
     * Sort weight, worst first. Used to order the health list and to pick the
     * report's overall tone.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Critical => 0,
            self::Warning  => 1,
            self::Notice   => 2,
        };
    }

    /**
     * Whether this level is worth interrupting the user for.
     *
     * The topbar indicator is the only interruption this feature has, and it
     * is spent on the levels that mean something is not working. See the class
     * docblock on why Notice deliberately does not qualify.
     */
    public function warrantsIndicator(): bool
    {
        return self::Notice !== $this;
    }

    /** The tone token the topbar and the cards share, so they cannot disagree. */
    public function tone(): string
    {
        return match ($this) {
            self::Critical => 'danger',
            self::Warning  => 'warn',
            self::Notice   => 'info',
        };
    }

    /** Card accent — a left border and a tinted icon, in both themes. */
    public function cardClasses(): string
    {
        return match ($this) {
            self::Critical => 'border-l-4 border-l-red-500',
            self::Warning  => 'border-l-4 border-l-amber-500',
            self::Notice   => 'border-l-4 border-l-sky-500',
        };
    }

    public function iconClasses(): string
    {
        return match ($this) {
            self::Critical => 'text-red-500',
            self::Warning  => 'text-amber-500',
            self::Notice   => 'text-sky-500',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Critical => 'fa-circle-exclamation',
            self::Warning  => 'fa-triangle-exclamation',
            self::Notice   => 'fa-circle-info',
        };
    }
}
