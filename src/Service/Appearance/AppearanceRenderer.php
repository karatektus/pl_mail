<?php

declare(strict_types=1);

namespace App\Service\Appearance;

use App\Domain\Enum\Theme\BackgroundKind;
use App\Entity\Embeddable\Appearance;

final readonly class AppearanceRenderer
{
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

    private static function contrastChannels(string $hex): string
    {
        [$r, $g, $b] = self::rgb($hex);

        $luminance = (0.2126 * $r + 0.7152 * $g + 0.0722 * $b) / 255;

        return $luminance > 0.6 ? '24 24 27' : '255 255 255';
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
