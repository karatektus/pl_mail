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
    // Default first — this order drives the settings dropdown.
    case Flat  = 'flat';
    case Boxed = 'boxed';

    /** Class added to <html>. Boxed is the plain stylesheet, so it needs none. */
    public function htmlClass(): ?string
    {
        return match ($this) {
            self::Flat  => 'layout-flat',
            self::Boxed => null,
        };
    }

    /**
     * Knob defaults seeded when this layout is selected.
     *
     * Flat drops the glass: with the topbar and sidebar sitting straight on
     * the background there is no card left to see through, and an opaque main
     * pane keeps the one remaining box legible over a photo.
     *
     * Boxed seeds a HIGHER popover opacity than pane opacity, and the gap is
     * the point rather than a rounding of it. A composer or a menu sits over a
     * pane that is already 30% background, so the two translucencies multiply:
     * at the old shared 0.7 a tenth of the wallpaper survived both layers and
     * landed in the middle of the text. At 0.9 over 0.7 it is three percent —
     * still visibly glass at the edges where the blur does the work, and no
     * longer competing with anything anybody is reading.
     *
     * @return array<string, scalar>
     */
    public function defaults(): array
    {
        return match ($this) {
            self::Flat => [
                'radius'       => 0.75,
                'paneBlur'     => 0,
                'paneAlpha'    => 1.0,
                'popoverAlpha' => 1.0,
                'density'      => Density::Comfortable->value,
            ],
            self::Boxed => [
                'radius'       => 1.0,
                'paneBlur'     => 24,
                'paneAlpha'    => 0.7,
                'popoverAlpha' => 0.9,
                'density'      => Density::Comfortable->value,
            ],
        };
    }
}
