<?php

declare(strict_types=1);

namespace App\Tests\Service\Appearance;

use App\Domain\Enum\Theme\Theme;
use App\Entity\Embeddable\Appearance;
use App\Service\Appearance\AppearanceRenderer;
use App\Service\Appearance\BackgroundResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Whatever accent a user picks, the text on it is readable.
 *
 * The accent is chosen from a colour wheel, so this cannot be checked by
 * inspecting a palette — the palette is whatever someone liked. It has to be a
 * property of the rule, which is why the sweep below exists: it walks the
 * accent space rather than a list of colours somebody thought of.
 *
 * The reported failure was white on a pink accent at 2.34:1. The cause was an
 * ink chosen by a threshold over raw, un-expanded channel values, which is not
 * luminance and crossed over in the wrong place.
 */
final class AccentInkContrastTest extends TestCase
{
    private const float AA_BODY_TEXT = 4.5;

    /**
     * Every accent in a coarse sweep of the RGB cube gets a readable ink.
     *
     * 6^3 = 216 colours at even spacing. Not exhaustive, but the failure mode
     * being guarded is a whole REGION of the space getting the wrong ink — the
     * old rule was wrong for most of the mid-tones — and a region that size
     * cannot hide between these samples.
     */
    public function testEveryAccentInTheCubeGetsReadableInk(): void
    {
        $worst      = 21.0;
        $worstHex   = '';
        $steps      = [0, 51, 102, 153, 204, 255];

        foreach ($steps as $r) {
            foreach ($steps as $g) {
                foreach ($steps as $b) {
                    $hex   = sprintf('#%02X%02X%02X', $r, $g, $b);
                    $ratio = $this->contrastForAccent($hex);

                    if ($ratio < $worst) {
                        $worst    = $ratio;
                        $worstHex = $hex;
                    }
                }
            }
        }

        self::assertGreaterThanOrEqual(
            self::AA_BODY_TEXT,
            $worst,
            sprintf('worst accent was %s at %.2f:1', $worstHex, $worst),
        );
    }

    /**
     * The colours from the report and its neighbourhood, named so a regression
     * says which one broke.
     *
     * @return iterable<string, array{string}>
     */
    public static function troublesomeAccents(): iterable
    {
        yield 'the reported pink' => ['#F472B6'];
        yield 'a stronger pink'   => ['#EC4899'];
        yield 'mid grey'          => ['#767676'];
        // Straddles the old 0.6 threshold, where the previous rule flipped.
        yield 'mid teal'          => ['#3AA6A6'];
        yield 'amber'             => ['#F59E0B'];
        yield 'lime'              => ['#84CC16'];
        yield 'deep indigo'       => ['#312E81'];
    }

    #[DataProvider('troublesomeAccents')]
    public function testNamedAccentsMeetAA(string $hex): void
    {
        self::assertGreaterThanOrEqual(
            self::AA_BODY_TEXT,
            $this->contrastForAccent($hex),
            $hex,
        );
    }

    /**
     * A light accent must get dark ink and a dark accent light ink. Contrast
     * alone would be satisfied by always picking the further-away ink, so this
     * pins the direction too — and it is the direction that was wrong.
     */
    public function testLightAccentsGetDarkInkAndDarkAccentsGetLightInk(): void
    {
        self::assertSame([24, 24, 27], $this->inkFor('#FDE68A'), 'pale amber needs dark ink');
        self::assertSame([24, 24, 27], $this->inkFor('#F472B6'), 'the reported pink needs dark ink');
        self::assertSame([255, 255, 255], $this->inkFor('#312E81'), 'deep indigo needs light ink');
        self::assertSame([255, 255, 255], $this->inkFor('#000000'), 'black needs light ink');
    }

    /**
     * Every stock theme's own accent is readable too — the themes seed the
     * user's accent setting from their swatch, so a theme ships whatever this
     * rule then has to cope with.
     */
    public function testEveryStockThemeAccentIsReadable(): void
    {
        foreach (Theme::cases() as $theme) {
            $accent = $theme->defaults()['accent'];

            self::assertGreaterThanOrEqual(
                self::AA_BODY_TEXT,
                $this->contrastForAccent($accent),
                $theme->value . ' (' . $accent . ')',
            );
        }
    }

    /** @return array{0:int,1:int,2:int} */
    private function inkFor(string $hex): array
    {
        $appearance         = new Appearance();
        $appearance->accent = $hex;

        // The background resolver is irrelevant here — the default appearance
        // uses the theme background, for which it returns null without touching
        // either collaborator — but the renderer's constructor still wants one.
        $renderer = new AppearanceRenderer(new BackgroundResolver(
            $this->createStub(Packages::class),
            $this->createStub(UrlGeneratorInterface::class),
        ));

        $vars = $renderer->cssVariables($appearance);

        preg_match('/--rgb-accent-ink:(\d+) (\d+) (\d+)/', $vars, $m);

        self::assertNotEmpty($m, 'no --rgb-accent-ink was written for ' . $hex);

        return [(int) $m[1], (int) $m[2], (int) $m[3]];
    }

    private function contrastForAccent(string $hex): float
    {
        return $this->ratio($this->luminance($this->channels($hex)), $this->luminance($this->inkFor($hex)));
    }

    /** @return array{0:int,1:int,2:int} */
    private function channels(string $hex): array
    {
        $hex = ltrim($hex, '#');

        return [
            (int) hexdec(substr($hex, 0, 2)),
            (int) hexdec(substr($hex, 2, 2)),
            (int) hexdec(substr($hex, 4, 2)),
        ];
    }

    /**
     * Written out here rather than shared with the renderer on purpose: a test
     * that reuses the implementation's own maths agrees with it by
     * construction, including when both are wrong.
     *
     * @param array{0:int,1:int,2:int} $rgb
     */
    private function luminance(array $rgb): float
    {
        $expand = static function (int $c): float {
            $s = $c / 255;

            return $s <= 0.04045 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $expand($rgb[0]) + 0.7152 * $expand($rgb[1]) + 0.0722 * $expand($rgb[2]);
    }

    private function ratio(float $a, float $b): float
    {
        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }
}
