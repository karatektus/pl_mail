<?php

declare(strict_types=1);

namespace App\Domain\Enum\Theme;

/**
 * How much the interface moves.
 *
 * Three steps rather than a switch, because "animations on/off" is the wrong
 * question. The complaint animation answers is that things appear from nowhere
 * — a row, a window, a dropdown — and the eye has no idea whether it arrived,
 * changed, or was always there. The complaint animation CAUSES is waiting for
 * the interface to finish talking before you may use it. Those are different
 * dials, and one boolean cannot turn them independently.
 *
 *   Full     movement and fade. Things arrive from somewhere and settle.
 *   Minimal  fade only, faster. Nothing travels, nothing is displaced; the eye
 *            still gets told where to look, and a slow machine or a cautious
 *            user pays almost nothing for it.
 *   None     exactly what plMail did before any of this existed.
 *
 * The numbers below are the entire vocabulary. Every animated surface reads
 * these custom properties and none of them invent their own durations, so
 * "make the whole app calmer" stays one enum rather than a search for
 * hardcoded milliseconds — and so a reviewer can see the whole motion budget
 * of the application in one screenful.
 *
 * **Nothing here exceeds 260ms.** Past roughly a quarter second a transition
 * stops reading as "this moved" and starts reading as "I am waiting for this",
 * which is the failure mode that makes people turn animation off. Enter
 * animations are also never allowed to gate interaction: a row is clickable
 * while it is still fading in, because the alternative is a beautiful
 * interface that feels slower than the one it replaced.
 */
enum MotionLevel: string
{
    case Full    = 'full';
    case Minimal = 'minimal';
    case None    = 'none';

    /**
     * The workhorse: an element arriving, a panel opening, a row appearing.
     *
     * Minimal is deliberately FASTER rather than merely smaller. Without
     * travel there is less for the eye to follow, so the same duration reads
     * as a lag rather than as a movement.
     */
    public function base(): string
    {
        return match ($this) {
            self::Full    => '180ms',
            self::Minimal => '120ms',
            self::None    => '0s',
        };
    }

    /** Hover, focus, a colour or a shadow changing — must feel instant. */
    public function fast(): string
    {
        return match ($this) {
            self::Full    => '120ms',
            self::Minimal => '90ms',
            self::None    => '0s',
        };
    }

    /**
     * The few things big enough to earn it: the compose window, a modal, a
     * pane sliding in. Reserved deliberately — a slow token used widely is
     * just a slow interface.
     */
    public function slow(): string
    {
        return match ($this) {
            self::Full    => '260ms',
            self::Minimal => '150ms',
            self::None    => '0s',
        };
    }

    /**
     * How far a thing travels on its way in.
     *
     * Zero at Minimal is what makes Minimal minimal: everything still fades,
     * nothing is displaced, and no layout appears to shift under a pointer
     * already on its way to a target.
     */
    public function lift(): string
    {
        return match ($this) {
            self::Full    => '6px',
            self::Minimal, self::None => '0px',
        };
    }

    /**
     * How much a thing that is arriving is scaled down to begin with.
     *
     * Under one, so it grows into place. Kept very close to 1: a panel that
     * starts at 0.9 reads as a zoom effect, and one that starts at 0.98 reads
     * as the panel having been there all along and simply catching up.
     */
    public function scaleFrom(): string
    {
        return match ($this) {
            self::Full                => '0.985',
            self::Minimal, self::None => '1',
        };
    }

    /**
     * The gap between one item in a list and the next.
     *
     * A stagger is what turns "twelve rows appeared" into "a list arrived",
     * and it is also the easiest way to make an interface feel slow — twelve
     * rows at 40ms is half a second before the last one is legible. Small, and
     * capped in CSS at a handful of rows; past that the delay is flat.
     */
    public function stagger(): string
    {
        return match ($this) {
            self::Full    => '22ms',
            self::Minimal => '0ms',
            self::None    => '0ms',
        };
    }

    /**
     * The easing everything shares.
     *
     * A decelerating curve with no overshoot: things enter quickly and settle,
     * which is what "natural" means for an interface — objects in the world
     * lose speed as they arrive rather than bouncing past and coming back. A
     * spring reads as playful the first time and as slow the fortieth, and mail
     * is a fortieth-time interface.
     */
    public function ease(): string
    {
        return 'cubic-bezier(0.22, 0.68, 0.32, 1)';
    }

    /** Whether anything animates at all — the escape hatch JS reads. */
    public function animates(): bool
    {
        return self::None !== $this;
    }
}
