<?php

declare(strict_types=1);

namespace App\Domain\Theme;

/**
 * A complete set of colours from one imported theme, painted over every
 * surface plMail draws, the open email's sheet included.
 *
 * A built-in theme is a block in app.css. An imported one cannot be: it
 * arrives as a file and lives on the account, so it is stored as the colours
 * below and written onto <html> as the same variables a theme block declares.
 * The built-in theme stays underneath for whether the page is light or dark
 * and for the few alphas a palette leaves alone.
 *
 * The terminal six are the theme's own hues. palette.css takes Tailwind's
 * named colours (the red of a delete button, the amber of a warning chip) from
 * them, and the calendar colour picker offers them as swatches.
 *
 * Stored as `['name' => string, 'dark' => bool, 'colours' => [key => #hex]]`.
 */
final readonly class Palette
{
    public const array SURFACES = ['page', 'surface', 'line', 'raised', 'sunken', 'field', 'field-border'];
    public const array INKS = ['ink', 'ink-soft', 'ink-muted', 'ink-faint', 'link'];
    public const array STATUS = ['danger', 'warning', 'success', 'info'];
    public const array TERMINAL = ['red', 'yellow', 'green', 'cyan', 'blue', 'magenta'];

    /** @param array<string, string> $colours every key in keys(), as `#rrggbb` */
    public function __construct(
        public string $name,
        public bool $dark,
        public array $colours,
    ) {
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return [...self::SURFACES, ...self::INKS, ...self::STATUS, ...self::TERMINAL];
    }

    /** What was stored, or an import's `palette`, if it is a whole palette; null otherwise. */
    public static function fromArray(mixed $data): ?self
    {
        if (false === is_array($data) || false === is_array($data['colours'] ?? null)) {
            return null;
        }

        $colours = [];

        foreach (self::keys() as $key) {
            $colour = $data['colours'][$key] ?? null;

            if (false === is_string($colour) || 1 !== preg_match('/^#[0-9a-fA-F]{6}$/', $colour)) {
                return null;
            }

            $colours[$key] = strtolower($colour);
        }

        $name = is_string($data['name'] ?? null) ? mb_substr(trim($data['name']), 0, 80) : '';

        return new self('' === $name ? 'Imported' : $name, true === ($data['dark'] ?? false), $colours);
    }

    /** @return array{name: string, dark: bool, colours: array<string, string>} */
    public function toArray(): array
    {
        return ['name' => $this->name, 'dark' => $this->dark, 'colours' => $this->colours];
    }

    /**
     * The variables a theme block in app.css declares, from this palette.
     *
     * Every surface is opaque: an editor theme is a set of solid colours, and
     * the white-at-6% a built-in dark theme lays over its gradient would tint
     * them toward a colour the theme never had.
     *
     * @return array<string, string>
     */
    public function cssVariables(): array
    {
        $c = $this->colours;
        $rgb = static fn (string $key): string => Oklab::channels($c[$key]);

        $variables = [
            '--rgb-surface'      => $rgb('surface'),
            '--rgb-border'       => $rgb('line'),
            '--rgb-line'         => $rgb('line'),
            '--rgb-raised'       => $rgb('raised'),
            '--rgb-sunken'       => $rgb('sunken'),
            '--rgb-ink'          => $rgb('ink'),
            '--rgb-ink-soft'     => $rgb('ink-soft'),
            '--rgb-ink-muted'    => $rgb('ink-muted'),
            '--rgb-ink-faint'    => $rgb('ink-faint'),
            '--rgb-field'        => $rgb('field'),
            '--rgb-field-border' => $rgb('field-border'),
            '--rgb-inverse'      => $rgb('ink'),
            '--rgb-inverse-ink'  => $rgb('surface'),
            '--border-alpha'       => '1',
            '--line-alpha'         => '1',
            '--raised-alpha'       => '1',
            '--hover-alpha'        => '0.6',
            '--sunken-alpha'       => '1',
            '--field-alpha'        => '1',
            '--field-border-alpha' => '1',
            '--app-bg'           => sprintf('linear-gradient(%1$s, %1$s)', $c['page']),
            '--rgb-sheet'           => $rgb('surface'),
            '--rgb-sheet-ink'       => $rgb('ink'),
            '--rgb-sheet-ink-soft'  => $rgb('ink-soft'),
            '--rgb-sheet-ink-muted' => $rgb('ink-muted'),
            '--rgb-sheet-ink-faint' => $rgb('ink-faint'),
            '--rgb-sheet-link'      => $rgb('link'),
        ];

        foreach (self::STATUS as $status) {
            $variables['--rgb-' . $status] = $rgb($status);
            $variables['--rgb-sheet-' . $status] = $rgb($status);
        }

        foreach (self::TERMINAL as $hue) {
            $variables['--rgb-term-' . $hue] = $rgb($hue);
        }

        return $variables;
    }

    /** The theme's own hues, for a colour picker. @return list<string> */
    public function swatches(): array
    {
        return array_values(array_map(fn (string $hue): string => $this->colours[$hue], self::TERMINAL));
    }
}
