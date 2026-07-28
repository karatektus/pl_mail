<?php

declare(strict_types=1);

namespace App\Domain\Enum\Theme;

/**
 * The second appearance axis, orthogonal to Theme: Theme picks the palette,
 * Layout picks the look-and-feel it is painted with. Every layout composes
 * with every theme.
 *
 * A layout is a preset bundle — a CSS class for the structural rules plus
 * starting values for the numeric knobs. Picking one seeds those knobs; the
 * sliders below it in the settings pane still override them individually.
 */
enum Layout: string
{
    case Boxed = 'boxed';
    case Flat  = 'flat';

    /** Class added to <html>. The default layout needs no rules, so no class. */
    public function htmlClass(): ?string
    {
        return match ($this) {
            self::Boxed => null,
            self::Flat  => 'layout-flat',
        };
    }

    /**
     * Knob defaults seeded when this layout is selected.
     *
     * Flat drops the glass: with the topbar and sidebar sitting straight on
     * the background there is no card left to see through, and an opaque main
     * pane keeps the one remaining box legible over a photo.
     *
     * @return array<string, scalar>
     */
    public function defaults(): array
    {
        return match ($this) {
            self::Boxed => [
                'radius'    => 1.0,
                'paneBlur'  => 24,
                'paneAlpha' => 0.7,
                'density'   => Density::Comfortable->value,
            ],
            self::Flat => [
                'radius'    => 0.75,
                'paneBlur'  => 0,
                'paneAlpha' => 1.0,
                'density'   => Density::Comfortable->value,
            ],
        };
    }
}
