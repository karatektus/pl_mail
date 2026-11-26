<?php

declare(strict_types=1);

namespace App\Domain\Theme;

use App\Domain\Enum\Theme\BackgroundKind;
use App\Domain\Enum\Theme\Theme;

/**
 * A VS Code colour theme read as a plMail palette.
 *
 * A VS Code theme names some hundreds of editor parts, and themes fill in
 * different subsets of them, so each plMail colour is read from a short chain
 * of keys that mean the same thing, the first one present winning:
 *
 *  - surface      editor.background
 *  - page         sideBar.background, then activityBar.background
 *  - sunken       editorWidget.background, then sideBar.background
 *  - line         editorGroup.border, panel.border, sideBar.border
 *  - raised       list.inactiveSelectionBackground, list.hoverBackground
 *  - ink          editor.foreground
 *  - muted, faint foreground, then descriptionForeground
 *  - accent       the first of focusBorder, button.background, the badge, the
 *                 progress bar, textLink.foreground, terminal blue that has a
 *                 colour at all
 *  - status       the editor's error, warning and info colours, then the
 *                 terminal's red, yellow, green and blue
 *
 * An editor is read in a monospace face at arm's length and a mailbox is read
 * in a small proportional one, so the colours are then held to what the rest
 * of plMail promises: body text at 10:1, every other ink and every status
 * colour at 4.5:1 on every surface it sits on, a field's outline at 3:1. A
 * colour that falls short is moved toward the ink (or, for a status colour,
 * only in lightness) until it does not, so it stays recognisably the theme's.
 *
 * Translucent colours are laid over the surface they would sit on. The file
 * may be JSONC, as VS Code writes them: comments and trailing commas allowed.
 */
final readonly class VsCodeTheme
{
    private const float INK_CONTRAST = 10.0;
    private const float TEXT_CONTRAST = 4.5;
    private const float FIELD_BORDER_CONTRAST = 3.15;

    private function __construct(
        public Palette $palette,
        public string $accent,
    ) {
    }

    /**
     * The theme in $json, or null if it is not a VS Code colour theme.
     * $fallbackName names one whose file does not, as many don't.
     */
    public static function fromJson(string $json, string $fallbackName = ''): ?self
    {
        $theme = json_decode(self::stripJsonc($json), true);

        if (false === is_array($theme) || false === is_array($theme['colors'] ?? null) || true === isset($theme['version'])) {
            return null;
        }

        $colours = array_filter($theme['colors'], Oklab::isColour(...));

        if (false === isset($colours['editor.background'])) {
            return null;
        }

        $name = is_string($theme['name'] ?? null) ? $theme['name'] : $fallbackName;
        $type = is_string($theme['type'] ?? null) ? $theme['type'] : null;

        return self::convert($colours, $name, $type);
    }

    /**
     * What importing it changes: the palette, its accent, and plain Dark or
     * Light underneath. The user's own ink, tint and wallpaper go, since each
     * would paint over a colour the theme chose; layout, type and the rest stay.
     *
     * @return array<string, mixed> for Appearance::applyArray()
     */
    public function settings(): array
    {
        return [
            'theme'            => (true === $this->palette->dark ? Theme::Dark : Theme::Light)->value,
            'palette'          => $this->palette->toArray(),
            'accent'           => $this->accent,
            'inkColor'         => null,
            'inkMuted'         => null,
            'inkFaint'         => null,
            'mainTint'         => null,
            'backgroundKind'   => BackgroundKind::Theme->value,
            'backgroundPreset' => null,
            'backgroundSolid'  => null,
        ];
    }

    /** @param array<string, string> $c */
    private static function convert(array $c, string $name, ?string $type): self
    {
        $pick = static function (string ...$keys) use ($c): ?string {
            foreach ($keys as $key) {
                if (true === isset($c[$key])) {
                    return $c[$key];
                }
            }

            return null;
        };

        $surface = Oklab::flatten($c['editor.background'], '#000000');
        $dark = null === $type ? Oklab::luminance($surface) < 0.2 : in_array($type, ['dark', 'hc-black'], true);
        $flat = static fn (?string $colour, ?string $ground = null): ?string => null === $colour
            ? null
            : Oklab::flatten($colour, $ground ?? $surface);
        $pole = true === $dark ? '#ffffff' : '#000000';

        $page = $flat($pick('sideBar.background', 'activityBar.background'))
            ?? Oklab::mix($surface, true === $dark ? '#000000' : '#ffffff', 0.25);

        if ($page === $surface) {
            $page = Oklab::mix($surface, true === $dark ? '#000000' : '#808080', 0.12);
        }

        $ink = $flat($pick('editor.foreground', 'foreground')) ?? (true === $dark ? '#d4d4d4' : '#333333');
        $ink = Oklab::lift($ink, $pole, [$surface, $page], self::INK_CONTRAST);

        $sunken = $flat($pick('editorWidget.background', 'sideBar.background')) ?? $surface;

        if ($sunken === $surface) {
            $sunken = Oklab::mix($surface, $ink, 0.05);
        }

        $grounds = [$surface, $page, $sunken];
        $muted = Oklab::lift($flat($pick('foreground', 'sideBar.foreground', 'descriptionForeground')) ?? Oklab::mix($ink, $surface, 0.3), $ink, $grounds, self::TEXT_CONTRAST);
        $faint = Oklab::lift($flat($pick('descriptionForeground', 'editorLineNumber.foreground')) ?? Oklab::mix($ink, $surface, 0.45), $ink, $grounds, self::TEXT_CONTRAST);

        if (Oklab::contrast($faint, $surface) > Oklab::contrast($muted, $surface)) {
            [$muted, $faint] = [$faint, $muted];
        }

        // Each tier no louder than its share of the ink's contrast, so the
        // three still read as three steps when a theme's greys are all bright.
        $top = Oklab::contrast($ink, $surface);
        $tier = static function (string $colour, float $share) use ($surface, $ink, $grounds, $top): string {
            $ceiling = max(self::TEXT_CONTRAST, $top * $share);

            for ($step = 0; $step <= 100; ++$step) {
                $candidate = Oklab::mix($colour, $surface, $step / 100);
                $next = Oklab::mix($colour, $surface, ($step + 1) / 100);

                if (Oklab::contrast($candidate, $surface) <= $ceiling || Oklab::contrast($next, $surface) < self::TEXT_CONTRAST) {
                    return Oklab::lift($candidate, $ink, $grounds, self::TEXT_CONTRAST);
                }
            }

            return $colour;
        };

        $muted = $tier($muted, 0.66);
        $faint = $tier($faint, 0.5);

        for ($guard = 0; $guard < 20 && Oklab::contrast($muted, $surface) - Oklab::contrast($faint, $surface) < 1.0; ++$guard) {
            $muted = Oklab::mix($muted, $ink, 0.08);
        }

        $soft = $tier(Oklab::mix($ink, $muted, 0.45), 0.82);

        $line = $flat($pick('editorGroup.border', 'panel.border', 'sideBar.border', 'editorWidget.border'));

        if (null === $line || Oklab::contrast($line, $surface) < 1.15) {
            $line = Oklab::mix($surface, $ink, 0.14);
        }

        $raised = $flat($pick('list.inactiveSelectionBackground', 'list.hoverBackground', 'editor.lineHighlightBackground'));

        if (null === $raised || Oklab::contrast($raised, $surface) < 1.05) {
            $raised = Oklab::mix($surface, $ink, 0.08);
        }

        $field = $flat($pick('input.background')) ?? $sunken;
        $fieldBorder = $flat($pick('input.border', 'dropdown.border'), $field) ?? $line;
        $fieldBorder = Oklab::lift($fieldBorder, $ink, [$surface, $sunken, $field, $page], self::FIELD_BORDER_CONTRAST);

        $terminal = [];

        foreach (Palette::TERMINAL as $hue) {
            $key = ucfirst($hue);
            $terminal[$hue] = $flat($pick('terminal.ansi' . $key, 'terminal.ansiBright' . $key));
        }

        $accent = self::accent($c, $terminal, $flat);

        $status = [
            'danger'  => $flat($pick('editorError.foreground', 'errorForeground')) ?? $terminal['red'] ?? '#e5484d',
            'warning' => $flat($pick('editorWarning.foreground')) ?? $terminal['yellow'] ?? '#d9a400',
            'success' => $terminal['green'] ?? $flat($pick('gitDecoration.addedResourceForeground')) ?? '#30a46c',
            'info'    => $flat($pick('editorInfo.foreground')) ?? $terminal['blue'] ?? $accent,
        ];

        // Status colours are text, and also text on a 12% wash of themselves.
        foreach ($status as $key => $colour) {
            $status[$key] = Oklab::liftLightness($colour, $dark, [$surface, $page, Oklab::mix($surface, $colour, 0.12)], self::TEXT_CONTRAST);
        }

        // A theme that leaves a terminal colour out gets the status colour of
        // the same meaning, then a neighbour, so Tailwind's families still
        // have a hue to take.
        $terminal['red'] ??= $status['danger'];
        $terminal['yellow'] ??= $status['warning'];
        $terminal['green'] ??= $status['success'];
        $terminal['blue'] ??= $status['info'];
        $terminal['cyan'] ??= Oklab::mix($terminal['green'], $terminal['blue'], 0.5);
        $terminal['magenta'] ??= Oklab::mix($terminal['red'], $terminal['blue'], 0.5);

        $palette = new Palette(
            '' === trim($name) ? 'Imported' : trim($name),
            $dark,
            [
                'page'         => $page,
                'surface'      => $surface,
                'line'         => $line,
                'raised'       => $raised,
                'sunken'       => $sunken,
                'field'        => $field,
                'field-border' => $fieldBorder,
                'ink'          => $ink,
                'ink-soft'     => $soft,
                'ink-muted'    => $muted,
                'ink-faint'    => $faint,
                'link'         => Oklab::liftLightness($accent, $dark, [$surface], self::TEXT_CONTRAST),
                ...$status,
                ...$terminal,
            ],
        );

        return new self($palette, $accent);
    }

    /**
     * The theme's own accent: the first colour among the keys VS Code paints
     * focus and buttons with that is a colour rather than a grey. A
     * translucent one (a focus ring at 60%, say) becomes the terminal colour
     * of the same hue, which is the theme's opaque version of it.
     *
     * @param array<string, string>      $c
     * @param array<string, string|null> $terminal
     */
    private static function accent(array $c, array $terminal, \Closure $flat): string
    {
        foreach (['focusBorder', 'button.background', 'activityBarBadge.background', 'progressBar.background', 'textLink.foreground', 'terminal.ansiBlue'] as $key) {
            $raw = $c[$key] ?? null;

            if (null === $raw || Oklab::lch(Oklab::flatten($raw, '#000000'))[1] <= 0.06) {
                continue;
            }

            if (Oklab::alpha($raw) >= 1.0) {
                return $flat($raw);
            }

            $opaque = Oklab::flatten($raw, '#000000');
            $nearest = null;

            foreach (array_filter($terminal) as $candidate) {
                if (null === $nearest || Oklab::hueGap($candidate, $opaque) < Oklab::hueGap($nearest, $opaque)) {
                    $nearest = $candidate;
                }
            }

            return null !== $nearest && Oklab::hueGap($nearest, $opaque) < 30 ? $nearest : $flat($raw);
        }

        return $terminal['blue'] ?? '#3b82f6';
    }

    /**
     * JSONC to JSON: `//` and block comments and trailing commas removed,
     * leaving anything inside a string alone, since a URL in a theme's
     * metadata has `//` in it too.
     */
    private static function stripJsonc(string $text): string
    {
        $out = '';
        $length = strlen($text);

        for ($i = 0; $i < $length; ++$i) {
            $char = $text[$i];

            if ('"' === $char) {
                $end = $i + 1;

                while ($end < $length && '"' !== $text[$end]) {
                    $end += '\\' === $text[$end] ? 2 : 1;
                }

                $out .= substr($text, $i, $end - $i + 1);
                $i = $end;

                continue;
            }

            if ('/' === $char && '/' === ($text[$i + 1] ?? '')) {
                $i = (false === ($newline = strpos($text, "\n", $i)) ? $length : $newline) - 1;

                continue;
            }

            if ('/' === $char && '*' === ($text[$i + 1] ?? '')) {
                $i = (false === ($close = strpos($text, '*/', $i + 2)) ? $length : $close + 2) - 1;

                continue;
            }

            $out .= $char;
        }

        return (string) preg_replace('/,(\s*[}\]])/', '$1', $out);
    }
}
