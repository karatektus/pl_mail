<?php

declare(strict_types=1);

namespace App\Tests\Domain\Enum\Theme;

use App\Domain\Enum\Theme\Theme;
use PHPUnit\Framework\TestCase;

/**
 * The colours the installed app's title bar is painted in (#589), rendered as
 * theme-color tags by AppearanceExtension::appThemeColours.
 *
 * Every theme answers its panel surface, which is the swatch's, and only
 * System has a second one, because only System leaves light or dark to the OS.
 */
final class ThemeSurfacesTest extends TestCase
{
    public function testEveryThemeAnswersItsSwatchSurface(): void
    {
        foreach (Theme::cases() as $theme) {
            $surfaces = $theme->surfaces();

            self::assertSame($theme->swatch()[0], $surfaces['light'], $theme->value);
            self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $surfaces['light'], $theme->value);
        }
    }

    public function testOnlySystemHasADarkSurface(): void
    {
        foreach (Theme::cases() as $theme) {
            $dark = $theme->surfaces()['dark'];

            if (Theme::System === $theme) {
                // The `.dark` base block's --rgb-surface: 17 24 39.
                self::assertSame('#111827', $dark);
            } else {
                self::assertNull($dark, $theme->value);
            }
        }
    }
}
