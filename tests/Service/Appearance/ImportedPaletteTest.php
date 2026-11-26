<?php

declare(strict_types=1);

namespace App\Tests\Service\Appearance;

use App\Domain\Theme\Palette;
use App\Entity\Embeddable\Appearance;
use App\Service\Appearance\AppearanceRenderer;
use App\Service\Appearance\BackgroundResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * An imported palette stays through every other change, goes when another
 * theme is picked, and paints over the theme without overruling the user.
 */
final class ImportedPaletteTest extends TestCase
{
    public function testItIsKeptWhenTheSettingsPaneSavesAnythingElse(): void
    {
        $appearance = self::imported();

        $appearance->applyArray(['theme' => 'dark', 'palette' => 'kept', 'radius' => '0.5']);

        self::assertNotNull($appearance->palette());
        self::assertSame(0.5, $appearance->radius);
    }

    public function testPickingTheSameThemeAgainDropsIt(): void
    {
        $appearance = self::imported();

        $appearance->applyArray(['theme' => 'dark', 'palette' => '']);

        self::assertNull($appearance->palette);
    }

    public function testAnotherThemeDropsItWithoutBeingTold(): void
    {
        $appearance = self::imported();

        $appearance->applyArray(['theme' => 'nord']);

        self::assertNull($appearance->palette);
    }

    public function testAnIncompletePaletteIsNotStored(): void
    {
        $appearance = new Appearance();
        $palette = self::palette();
        unset($palette['colours']['magenta']);

        $appearance->applyArray(['palette' => $palette]);

        self::assertNull($appearance->palette);
    }

    public function testItTravelsInTheExportAndResetClearsIt(): void
    {
        $appearance = self::imported();

        $copy = new Appearance()->applyArray($appearance->toArray());
        self::assertSame($appearance->palette, $copy->palette);

        $copy->applyArray(new Appearance()->toArray());
        self::assertNull($copy->palette);
    }

    public function testItPaintsTheThemeAndTheSheet(): void
    {
        $appearance = self::imported();
        $renderer = $this->renderer();

        self::assertContains('palette', explode(' ', $renderer->htmlClass($appearance)));

        $css = $renderer->cssVariables($appearance);
        self::assertStringContainsString('--rgb-surface:26 27 38', $css);
        self::assertStringContainsString('--rgb-sheet:26 27 38', $css);
        self::assertStringContainsString('--rgb-term-red:247 118 142', $css);
        self::assertStringContainsString('--app-bg:linear-gradient(#16161e, #16161e)', $css);
    }

    public function testTheUsersOwnInkStillWins(): void
    {
        $appearance = self::imported();
        $appearance->inkColor = '#ffffff';

        $css = $this->renderer()->cssVariables($appearance);

        self::assertStringNotContainsString('--rgb-ink:191 197 225', $css);
        self::assertStringContainsString('--rgb-ink:255 255 255', $css);
    }

    public function testNothingChangesWithoutOne(): void
    {
        $appearance = new Appearance();
        $renderer = $this->renderer();

        self::assertNotContains('palette', explode(' ', $renderer->htmlClass($appearance)));
        self::assertStringNotContainsString('--rgb-surface', $renderer->cssVariables($appearance));
    }

    private static function imported(): Appearance
    {
        return new Appearance()->applyArray(['theme' => 'dark', 'palette' => self::palette()]);
    }

    /** @return array{name: string, dark: bool, colours: array<string, string>} */
    private static function palette(): array
    {
        $colours = array_fill_keys(Palette::keys(), '#7aa2f7');

        return ['name' => 'Tokyo Night', 'dark' => true, 'colours' => [
            ...$colours,
            'page'    => '#16161e',
            'surface' => '#1a1b26',
            'ink'     => '#bfc5e1',
            'red'     => '#f7768e',
        ]];
    }

    private function renderer(): AppearanceRenderer
    {
        return new AppearanceRenderer(new BackgroundResolver(
            $this->createStub(Packages::class),
            $this->createStub(UrlGeneratorInterface::class),
        ));
    }
}
