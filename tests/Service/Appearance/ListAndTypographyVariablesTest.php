<?php

declare(strict_types=1);

namespace App\Tests\Service\Appearance;

use App\Domain\Enum\Theme\Density;
use App\Domain\Enum\Theme\FontFamily;
use App\Entity\Embeddable\Appearance;
use App\Service\Appearance\AppearanceRenderer;
use App\Service\Appearance\BackgroundResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The typography and per-surface settings, from the stored value to the CSS
 * custom property. The mail-list settings that were here are MessageList's
 * since #608; MessageListRendererTest holds their half of this promise.
 *
 * The claim worth testing is not that a setter stores what it was given — it is
 * that a FRESH APPEARANCE RENDERS THE APP EXACTLY AS IT WAS before any of these
 * settings existed. Nine columns arrived on every user row in the last
 * migration, and the promise made with them was that nobody's inbox looks
 * different the morning after a deploy. That promise is a set of literal
 * strings, so it is asserted as literal strings: 0.625rem is the thread row's
 * old `py-2.5`, 1rem is the message block's old `py-4`, and the preview shows
 * one nowrap line.
 */
final class ListAndTypographyVariablesTest extends TestCase
{
    /**
     * The rendered variables, as a map.
     *
     * A real resolver over stubbed collaborators, the way AccentInkContrastTest
     * builds one: nothing here asks a background question, and a default
     * appearance uses the theme's background anyway, so the stubs are never
     * consulted.
     */
    private function render(Appearance $appearance): array
    {
        $css = new AppearanceRenderer(new BackgroundResolver(
            $this->createStub(Packages::class),
            $this->createStub(UrlGeneratorInterface::class),
        ))->cssVariables($appearance);

        $variables = [];

        foreach (explode(';', $css) as $part) {
            [$name, $value] = explode(':', $part, 2);
            $variables[$name] = $value;
        }

        return $variables;
    }

    /** A brand-new appearance paints the app exactly as it was before it existed. */
    public function testTheDefaultsAreTodaysAppearance(): void
    {
        $variables = $this->render(new Appearance());

        self::assertSame('1', $variables['--app-font-scale']);

        // The two numbers the templates gave up when density reached them.
        self::assertSame('0.625rem', $variables['--surface-list-row-y'], 'the thread row\'s old py-2.5');
        self::assertSame('1rem', $variables['--surface-reading-row-y'], 'the message block\'s old py-4');
    }

    public function testTheFontScaleIsClampedToTheRangeItPublishes(): void
    {
        $appearance = new Appearance();

        $appearance->fontScale = 5.0;
        self::assertSame(Appearance::RANGE_FONT_SCALE['max'], $appearance->fontScale);

        $appearance->fontScale = 0.1;
        self::assertSame(Appearance::RANGE_FONT_SCALE['min'], $appearance->fontScale);
    }

    public function testAFontFamilyBecomesAWholeStack(): void
    {
        $appearance = new Appearance();
        $appearance->fontFamily = FontFamily::Serif;

        self::assertStringContainsString('Georgia', $this->render($appearance)['--app-font-family']);
    }

    /**
     * A surface with no opinion of its own follows the global density — which
     * is what makes the columns additive, and what the "Follow" option means.
     */
    public function testASurfaceWithNoOverrideFollowsTheGlobalDensity(): void
    {
        $appearance = new Appearance();
        $appearance->density = Density::Compact;

        $variables = $this->render($appearance);

        self::assertSame(Density::Compact->listRowPadding(), $variables['--surface-list-row-y']);
        self::assertSame(Density::Compact->rowPadding(), $variables['--surface-sidebar-row-y']);
        self::assertSame(Density::Compact->readingBlockPadding(), $variables['--surface-reading-row-y']);

        // The sidebar's four, not one: it is made of rows at two tiers, the gap
        // between its rows is not the shell gutter, and the band above a
        // section heading is the part a reader actually perceives as "how tight
        // is this". A surface that moved only one of them would move
        // one-quarter of the sidebar.
        self::assertSame(Density::Compact->treeRowPadding(), $variables['--surface-sidebar-tree-y']);
        self::assertSame(Density::Compact->rowGap(), $variables['--surface-sidebar-row-gap']);
        self::assertSame(Density::Compact->sectionPadding(), $variables['--surface-sidebar-section-y']);
    }

    /**
     * Comfortable is the geometry the sidebar shipped with, stated in the units
     * the markup used to hardcode.
     *
     * This is the guard on the reconciliation: `--density-row-y` read 0.875rem
     * while every row it was meant for was `py-2`, so pointing the rows at the
     * token would have made every install's sidebar taller on deploy. The
     * numbers below are `py-2`, `py-1.5`, `space-y-0.5` and `pt-4` — if one of
     * them changes, somebody's sidebar moves and it should be on purpose.
     */
    public function testComfortableIsTheGeometryTheSidebarAlreadyHad(): void
    {
        $variables = $this->render(new Appearance());

        self::assertSame('0.5rem', $variables['--surface-sidebar-row-y'], 'py-2');
        self::assertSame('0.375rem', $variables['--surface-sidebar-tree-y'], 'py-1.5');
        self::assertSame('0.125rem', $variables['--surface-sidebar-row-gap'], 'space-y-0.5');
        self::assertSame('1rem', $variables['--surface-sidebar-section-y'], 'pt-4');
    }

    /** Every step is tighter than the one before it, on every sidebar measure. */
    public function testTheSidebarScaleOnlyEverTightens(): void
    {
        $measures = [
            static fn (Density $d): string => $d->rowPadding(),
            static fn (Density $d): string => $d->treeRowPadding(),
            static fn (Density $d): string => $d->rowGap(),
            static fn (Density $d): string => $d->sectionPadding(),
        ];

        foreach ($measures as $index => $measure) {
            $comfortable = (float) $measure(Density::Comfortable);
            $cosy        = (float) $measure(Density::Cosy);
            $compact     = (float) $measure(Density::Compact);

            self::assertLessThanOrEqual($comfortable, $cosy, "measure $index: cosy is not tighter");
            self::assertLessThan($cosy, $compact, "measure $index: compact is not tighter than cosy");
        }
    }

    /**
     * The two tiers stay two tiers.
     *
     * A system row and a label row have always been different heights, and a
     * scale that let them meet would be a redesign of the sidebar rather than a
     * density setting.
     */
    public function testATreeRowIsAlwaysTighterThanASystemRow(): void
    {
        foreach (Density::cases() as $density) {
            self::assertLessThan(
                (float) $density->rowPadding(),
                (float) $density->treeRowPadding(),
                $density->value . ' flattened the sidebar to one tier',
            );
        }
    }

    /** The case the whole group exists for: a dense list, a comfortable read. */
    public function testASurfaceOverrideBeatsTheGlobalDensityForThatSurfaceAlone(): void
    {
        $appearance = new Appearance();
        $appearance->density = Density::Comfortable;
        $appearance->listDensity = Density::Compact;

        $variables = $this->render($appearance);

        self::assertSame(Density::Compact->listRowPadding(), $variables['--surface-list-row-y']);
        self::assertSame(Density::Comfortable->readingBlockPadding(), $variables['--surface-reading-row-y']);
        self::assertSame(Density::Comfortable->rowPadding(), $variables['--surface-sidebar-row-y']);
    }

    /**
     * Null is a value the payload can carry, not an omission.
     *
     * `isset()` cannot tell "follow the global" from "not mentioned", so the
     * surface densities go through array_key_exists — and this is the case that
     * proves it: a user who has set an override and then picks Follow must end
     * up back at null rather than keeping the override forever.
     */
    public function testAnEmptySurfaceDensityGoesBackToFollowing(): void
    {
        $appearance = new Appearance();
        $appearance->listDensity = Density::Compact;

        $appearance->applyArray(['listDensity' => '']);

        self::assertNull($appearance->listDensity);
    }

    /** Export → import is lossless for everything added here. */
    public function testTheNewSettingsSurviveAnExportAndImport(): void
    {
        $source = new Appearance();
        $source->fontFamily = FontFamily::Monospace;
        $source->fontScale = 1.125;
        $source->listDensity = Density::Compact;
        $source->readingDensity = Density::Comfortable;

        $restored = new Appearance()->applyArray($source->toArray());

        self::assertSame($source->toArray(), $restored->toArray());
    }

    /**
     * Reset goes back to today's appearance, including the surfaces.
     *
     * AppearanceController::reset() applies `new Appearance()->toArray()`, so
     * any field toArray() forgets is a field reset cannot clear — and the three
     * surface densities are exactly the shape that gets forgotten, because
     * their reset value is null.
     */
    public function testResetClearsASurfaceOverride(): void
    {
        $appearance = new Appearance();
        $appearance->listDensity = Density::Compact;
        $appearance->fontScale = 1.25;

        $appearance->applyArray(new Appearance()->toArray());

        self::assertNull($appearance->listDensity);
        self::assertSame(1.0, $appearance->fontScale);
    }

    /**
     * A theme no longer carries the list (#608). Importing somebody's colours
     * used to change how many preview lines your list had; an old theme file
     * that still has the keys imports without complaint and moves nothing.
     */
    public function testAThemeExportCarriesNoListSettings(): void
    {
        $exported = new Appearance()->toArray();

        foreach (['previewLines', 'accountCorner', 'listAvatars', 'tabUnreadCount', 'unreadEmphasis'] as $key) {
            self::assertArrayNotHasKey($key, $exported);
        }

        $imported = new Appearance()->applyArray(['previewLines' => 0, 'accountCorner' => false] + $exported);

        self::assertSame($exported, $imported->toArray());
    }
}
