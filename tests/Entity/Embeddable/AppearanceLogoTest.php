<?php

declare(strict_types=1);

namespace App\Tests\Entity\Embeddable;

use App\Domain\Enum\Theme\LogoMotif;
use App\Domain\Enum\Theme\LogoStyle;
use App\Domain\Enum\Theme\Theme;
use App\Entity\Embeddable\Appearance;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What the chosen icon is painted in — Appearance::effectiveLogoPaint().
 *
 * Four stored fields decide it (the motif, whether a motif wears its own
 * design, whether the colour follows the theme, the colourway chosen by hand),
 * and every reader — the topbar, the favicon, JMAP's `logoPaint`, and through
 * that the phone's launcher icon — goes through this one method. So the rules
 * are pinned here, each for the pl mark and for a motif, because the two part
 * ways on purpose in exactly two places: the mark has no original of its own,
 * and a motif on a classic theme falls back to its original rather than to the
 * mark's Berry.
 */
final class AppearanceLogoTest extends TestCase
{
    /**
     * @return iterable<string, array{LogoMotif, bool, bool, Theme, ?LogoStyle}>
     */
    public static function rules(): iterable
    {
        // motif, logoOriginal, logoLinked, theme → the paint (null = original)
        yield 'a motif in its own design' => [LogoMotif::BlueHorn, true, true, Theme::Ocean, null];
        yield 'the mark has no original: the flag is ignored' => [LogoMotif::Pl, true, true, Theme::Ocean, LogoStyle::Ocean];

        yield 'a linked motif wears the theme\'s namesake' => [LogoMotif::BlueHorn, false, true, Theme::Ocean, LogoStyle::Ocean];
        yield 'a linked mark wears the theme\'s namesake' => [LogoMotif::Pl, false, true, Theme::Ocean, LogoStyle::Ocean];

        yield 'a linked motif on a classic theme falls back to its original, not Berry' => [LogoMotif::BlueHorn, false, true, Theme::Paper, null];
        yield 'a linked mark on a classic theme falls back to Berry' => [LogoMotif::Pl, false, true, Theme::Paper, LogoStyle::DEFAULT];

        yield 'an independent motif wears the chosen colourway' => [LogoMotif::BlueHorn, false, false, Theme::Ocean, LogoStyle::Postal];
        yield 'an independent mark wears the chosen colourway' => [LogoMotif::Pl, false, false, Theme::Ocean, LogoStyle::Postal];
    }

    #[DataProvider('rules')]
    public function testTheIconWearsThePaintItsFieldsAddUpTo(
        LogoMotif $motif,
        bool $original,
        bool $linked,
        Theme $theme,
        ?LogoStyle $expected,
    ): void {
        $appearance = new Appearance();
        $appearance->logoMotif = $motif;
        $appearance->logoOriginal = $original;
        $appearance->logoLinked = $linked;
        $appearance->theme = $theme;
        $appearance->logoStyle = LogoStyle::Postal;

        self::assertSame($expected, $appearance->effectiveLogoPaint());
        self::assertSame($motif, $appearance->effectiveLogoMotif());

        // For the mark the paint IS the mark's colourway, so the reader that
        // predates the icons (JMAP's `logoStyle`) keeps telling the truth.
        if (LogoMotif::Pl === $motif) {
            self::assertSame($appearance->effectiveLogoStyle(), $appearance->effectiveLogoPaint());
        }
    }
}
