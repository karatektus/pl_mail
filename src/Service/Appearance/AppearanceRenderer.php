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
        if (true === $appearance->theme->followsSystem()) {
            return '';
        }

        return true === $appearance->theme->isDark() ? 'dark' : '';
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

        if (BackgroundKind::Theme !== $appearance->backgroundKind) {
            // Photos need an opacity floor or panel text becomes unreadable.
            $variables['--pane-alpha'] = (string) max(0.45, $appearance->paneAlpha);
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
