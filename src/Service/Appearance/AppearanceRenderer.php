<?php

declare(strict_types=1);

namespace App\Service\Appearance;

use App\Domain\Enum\Theme\BackgroundKind;
use App\Entity\Embeddable\Appearance;

final readonly class AppearanceRenderer
{
    /** WCAG 2.x AA for body text. What accent-ink is held to. */
    private const float MIN_CONTRAST = 4.5;

    public function __construct(private BackgroundResolver $backgroundResolver)
    {
    }

    public function htmlClass(Appearance $appearance): string
    {
        $classes = [];

        // A system theme is resolved client-side by the no-flash script.
        if (false === $appearance->theme->followsSystem() && true === $appearance->theme->isDark()) {
            $classes[] = 'dark';
        }

        if (null !== $layoutClass = $appearance->layout->htmlClass()) {
            $classes[] = $layoutClass;
        }

        return implode(' ', $classes);
    }

    public function cssVariables(Appearance $appearance, ?int $userId = null): string
    {
        $variables = [
            '--pane-alpha'    => rtrim(rtrim(number_format($appearance->paneAlpha, 3, '.', ''), '0'), '.'),
            '--pane-blur'     => sprintf('%dpx', $appearance->paneBlur),
            '--app-radius'    => sprintf('%srem', rtrim(rtrim(number_format($appearance->radius, 3, '.', ''), '0'), '.')),
            '--density-row-y' => $appearance->density->rowPadding(),
            '--density-gap'   => $appearance->density->gap(),
            '--rgb-accent'    => self::channels($appearance->accent),
            '--rgb-accent-ink' => self::contrastChannels($appearance->accent),
            '--scrim-alpha'   => rtrim(rtrim(number_format($appearance->scrimAlpha, 3, '.', ''), '0'), '.') ?: '0',

            // ── Typography ────────────────────────────────────────────────
            '--app-font-family' => $appearance->fontFamily->stack(),
            '--app-font-scale'  => self::number($appearance->fontScale),

            // ── Mail list display ─────────────────────────────────────────
            '--list-corner-display' => $appearance->accountCorner ? 'block' : 'none',
            '--list-avatar-hide'    => $appearance->listAvatars ? '0' : '1',
            '--unread-emphasis'     => self::number($appearance->unreadEmphasis->tintScale()),
            '--unread-bar-w'        => $appearance->unreadEmphasis->barWidth(),

            // The preview is four variables rather than a line count, and each
            // of them is a value CSS can use directly. Nothing in CSS derives
            // "hidden" from a count (`-webkit-line-clamp: 0` shows the text),
            // nothing derives the box display a clamp needs, and `truncate`'s
            // `white-space: nowrap` has to be released for a second line to
            // exist at all. Deriving those in PHP, where the count is known,
            // beats three layers of var() guesswork in the stylesheet.
            //
            // The `-wide` one is the same switch minus the clamp: a wide row
            // puts the subject and the preview on ONE line by design, so two
            // lines is a stacked-row answer only. See app.css.
            '--list-preview-display' => match ($appearance->previewLines) {
                0 => 'none',
                1 => 'block',
                default => '-webkit-box',
            },
            '--list-preview-display-wide' => 0 === $appearance->previewLines ? 'none' : 'block',
            '--list-preview-lines' => (string) max(1, $appearance->previewLines),
            '--list-preview-wrap' => $appearance->previewLines > 1 ? 'normal' : 'nowrap',

            // ── Per-surface density ───────────────────────────────────────
            // Resolved here rather than left to a CSS fallback chain: a
            // `var(--x, var(--x))` on the same property is a cycle, and every
            // other spelling needs the global value written literally in two
            // places. Each surface gets one concrete pair.
            // The sidebar takes five rather than two: it is the one surface
            // made of rows at two tiers (system rows and the label tree), and
            // the gap between its rows is not the shell gutter that
            // `gap()` means everywhere else. Every one of them resolves to
            // exactly what the markup hardcoded before density reached it —
            // see Density::rowPadding().
            '--surface-sidebar-row-y'     => $appearance->densityFor('sidebar')->rowPadding(),
            '--surface-sidebar-tree-y'    => $appearance->densityFor('sidebar')->treeRowPadding(),
            '--surface-sidebar-row-gap'   => $appearance->densityFor('sidebar')->rowGap(),
            '--surface-sidebar-section-y' => $appearance->densityFor('sidebar')->sectionPadding(),
            '--surface-sidebar-gap'       => $appearance->densityFor('sidebar')->gap(),
            '--surface-list-row-y'    => $appearance->densityFor('list')->listRowPadding(),
            '--surface-list-gap'      => $appearance->densityFor('list')->gap(),
            '--surface-reading-row-y' => $appearance->densityFor('reading')->readingBlockPadding(),
            '--surface-reading-gap'   => $appearance->densityFor('reading')->gap(),
        ];

        $background = $this->backgroundResolver->cssValue($appearance, $userId);

        if (null !== $background) {
            $variables['--app-bg'] = $background;
        }

        if (null !== $appearance->inkColor) {
            $variables['--rgb-ink']       = self::channels($appearance->inkColor);
            $variables['--rgb-ink-soft']  = self::blendToGrey($appearance->inkColor, 0.22);
            $variables['--rgb-ink-muted'] = null !== $appearance->inkMuted
                ? self::channels($appearance->inkMuted)
                : self::blendToGrey($appearance->inkColor, 0.40);
            $variables['--rgb-ink-faint'] = null !== $appearance->inkFaint
                ? self::channels($appearance->inkFaint)
                : self::blendToGrey($appearance->inkColor, 0.62);
        }

        if (null !== $appearance->mainTint) {
            $variables['--rgb-main'] = self::channels($appearance->mainTint);
        }

        if (null !== $appearance->mainAlpha) {
            $variables['--main-alpha'] = rtrim(rtrim(number_format($appearance->mainAlpha, 3, '.', ''), '0'), '.');
        }

        if (BackgroundKind::Theme !== $appearance->backgroundKind) {
            // Photos need an opacity floor or panel text becomes unreadable.
            $variables['--pane-alpha'] = (string) max(0.45, $appearance->paneAlpha);

            if (true === isset($variables['--main-alpha'])) {
                $variables['--main-alpha'] = (string) max(0.45, $appearance->mainAlpha);
            }
        }
        $parts = [];

        foreach ($variables as $name => $value) {
            $parts[] = sprintf('%s:%s', $name, $value);
        }

        return implode(';', $parts);
    }

    /** A float as CSS wants it: no trailing zeroes, and never the empty string. */
    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.') ?: '0';
    }

    private static function channels(string $hex): string
    {
        [$r, $g, $b] = self::rgb($hex);

        return sprintf('%d %d %d', $r, $g, $b);
    }

    private static function blendToGrey(string $hex, float $factor): string
    {
        [$r, $g, $b] = self::rgb($hex);

        return sprintf(
            '%d %d %d',
            (int) round($r + ($factor * (128 - $r))),
            (int) round($g + ($factor * (128 - $g))),
            (int) round($b + ($factor * (128 - $b))),
        );
    }

    /**
     * The ink to write ON the accent, chosen by measuring rather than assuming.
     *
     * The accent is picked by the user from a colour wheel, so no fixed ink can
     * be right for all of it — and white was effectively the fixed answer: the
     * old test weighted the raw 0–255 channels and only crossed its 0.6
     * threshold for very light colours, so a mid-tone pink got white text at
     * 2.34:1, well under the 4.5:1 WCAG demands of body text.
     *
     * Two corrections. First, luminance is computed the way WCAG defines it —
     * on gamma-EXPANDED channels. sRGB is not linear in intensity, and skipping
     * the expansion overstates how dark a saturated mid-tone is, which is
     * exactly the region where the wrong ink gets chosen. Second, there is no
     * threshold to tune: both candidate inks are scored against the accent and
     * the better one wins, so the rule states its own intent and cannot drift.
     *
     * The dark candidate is normally #18181B, which is what dark text is
     * everywhere else in the app; a different black for accent chips alone
     * would read as a mistake. But two inks are not quite enough to promise
     * 4.5:1: the accents where white and #18181B score equally sit at a
     * relative luminance near 0.198, and both score 4.24:1 there. That band is
     * narrow and it is real, so when the house ink cannot clear 4.5:1 the dark
     * candidate becomes pure black, which lifts the worst case over the line
     * (4.58:1) for every accent a user can pick. In practice this only engages
     * for mid-tone accents; everything else still gets the house ink.
     */
    private static function contrastChannels(string $hex): string
    {
        $accent = self::relativeLuminance(self::rgb($hex));

        $houseInk = [24, 24, 27];
        $lightInk = [255, 255, 255];
        $blackInk = [0, 0, 0];

        $onHouse = self::contrastRatio($accent, self::relativeLuminance($houseInk));
        $onLight = self::contrastRatio($accent, self::relativeLuminance($lightInk));

        if ($onLight > $onHouse && $onLight > self::contrastRatio($accent, self::relativeLuminance($blackInk))) {
            return sprintf('%d %d %d', ...$lightInk);
        }

        $ink = $onHouse >= self::MIN_CONTRAST ? $houseInk : $blackInk;

        return sprintf('%d %d %d', ...$ink);
    }

    /**
     * WCAG 2.x relative luminance.
     *
     * @param array{0:int,1:int,2:int} $rgb
     */
    private static function relativeLuminance(array $rgb): float
    {
        $expand = static function (int $channel): float {
            $c = $channel / 255;

            return $c <= 0.04045 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $expand($rgb[0])
            + 0.7152 * $expand($rgb[1])
            + 0.0722 * $expand($rgb[2]);
    }

    /** WCAG contrast ratio, 1.0 (identical) to 21.0 (black on white). */
    private static function contrastRatio(float $a, float $b): float
    {
        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    /** @return array{0:int,1:int,2:int} */
    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }
}
