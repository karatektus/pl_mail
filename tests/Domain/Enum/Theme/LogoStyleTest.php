<?php

declare(strict_types=1);

namespace App\Tests\Domain\Enum\Theme;

use App\Domain\Enum\Theme\LogoStyle;
use PHPUnit\Framework\TestCase;

/**
 * The catalogue of the mark's colourways, held to its two structural promises.
 *
 * The geometry contract: _logo_mark.html.twig draws exactly seven strokes and
 * indexes its paints, so every style must answer seven well-formed colours for
 * both chromes — an eighth would be ignored silently and a sixth would throw
 * in a template, which is the worst place to learn it.
 *
 * And the reason the enum exists at all: the original mark wore Google's
 * literal brand hexes, and the catalogue was built to retire them. No style
 * may quietly bring one back.
 */
final class LogoStyleTest extends TestCase
{
    private const array GOOGLE_BRAND_HEXES = ['#4285f4', '#ea4335', '#fbbc04', '#34a853'];

    public function testEveryStylePaintsSevenStrokesForBothChromes(): void
    {
        foreach (LogoStyle::cases() as $style) {
            foreach ([false, true] as $dark) {
                $strokes = $style->strokes($dark);

                self::assertCount(7, $strokes, sprintf('%s (%s chrome)', $style->value, $dark ? 'dark' : 'light'));

                foreach ($strokes as $i => $hex) {
                    self::assertMatchesRegularExpression(
                        '/^#[0-9a-f]{6}$/',
                        $hex,
                        sprintf('%s stroke %d (%s chrome)', $style->value, $i, $dark ? 'dark' : 'light'),
                    );
                }
            }

            self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $style->tile(), $style->value . ' tile');
        }
    }

    public function testNoStyleWearsAGoogleBrandColour(): void
    {
        foreach (LogoStyle::cases() as $style) {
            $paints = [...$style->strokes(), ...$style->strokes(true), $style->tile()];

            foreach (self::GOOGLE_BRAND_HEXES as $forbidden) {
                self::assertNotContains(
                    $forbidden,
                    array_map(strtolower(...), $paints),
                    sprintf('%s wears %s — the colourway the catalogue exists to retire', $style->value, $forbidden),
                );
            }
        }
    }

    /**
     * The count is the design decision, not an accident of editing: the board
     * had thirty-four colourways and two were rejected by name — the Google
     * original and its four-distinct-hues restatement. A case appearing or
     * vanishing should be a conversation, so the number is pinned.
     */
    public function testTheCatalogueHoldsExactlyTheThirtyTwoAcceptedStyles(): void
    {
        self::assertCount(32, LogoStyle::cases());
    }

    public function testTheDefaultIsBerry(): void
    {
        self::assertSame(LogoStyle::Berry, LogoStyle::DEFAULT);

        // The PNG icon set under public/icons/ is exported in the default's
        // sweep with this tile behind the maskable variants — if this moves,
        // those files are stale (see the enum's own note on DEFAULT).
        self::assertSame('#a21caf', LogoStyle::DEFAULT->tile());
    }

    /**
     * The dark list exists so near-ink marks survive dark chrome; a style
     * whose light strokes are ink-dark everywhere must therefore answer
     * something lighter when asked for dark. Spot-checked on the styles built
     * from the shared ink constant rather than asserted as a luminance rule —
     * the duotones legitimately keep dark members in dark chrome.
     */
    public function testTheInkBuiltStylesSwapTheirInkAwayInDarkChrome(): void
    {
        foreach ([LogoStyle::Ink, LogoStyle::RedFlick, LogoStyle::SkyFlick, LogoStyle::BronzeFlick, LogoStyle::VioletFlick] as $style) {
            self::assertNotContains(
                '#2b2620',
                $style->strokes(true),
                sprintf('%s keeps near-ink strokes on dark chrome', $style->value),
            );
        }
    }
}
