<?php

declare(strict_types=1);

namespace App\Tests\Service\Appearance;

use App\Domain\Enum\Theme\ActionButtonStyle;
use App\Entity\Embeddable\Appearance;
use PHPUnit\Framework\TestCase;

/**
 * The three tiers of #459, as the values the stylesheet reads, and the default
 * that keeps an existing install looking as it did.
 */
final class ActionButtonStyleTest extends TestCase
{
    public function testAnAppearanceStartsOnIconsAlone(): void
    {
        self::assertSame(ActionButtonStyle::Icons, new Appearance()->actionButtons);
    }

    public function testEachTierShowsWhatItNames(): void
    {
        self::assertSame(['inline-block', 'none'], [ActionButtonStyle::Icons->iconDisplay(), ActionButtonStyle::Icons->labelDisplay()]);
        self::assertSame(['inline-block', 'inline'], [ActionButtonStyle::Both->iconDisplay(), ActionButtonStyle::Both->labelDisplay()]);
        self::assertSame(['none', 'inline'], [ActionButtonStyle::Text->iconDisplay(), ActionButtonStyle::Text->labelDisplay()]);
    }

    /** No padding is what leaves the icon button its old 32px circle. */
    public function testIconsAloneAddNoPadding(): void
    {
        self::assertSame('0px', ActionButtonStyle::Icons->paddingX());
        self::assertNotSame('0px', ActionButtonStyle::Text->paddingX());
    }

    public function testTheChoiceSurvivesAnExportAndImport(): void
    {
        $source = new Appearance();
        $source->actionButtons = ActionButtonStyle::Text;

        self::assertSame(ActionButtonStyle::Text, new Appearance()->applyArray($source->toArray())->actionButtons);
    }

    public function testAnUnknownValueIsIgnored(): void
    {
        $appearance = new Appearance();
        $appearance->applyArray(['actionButtons' => 'hieroglyphs']);

        self::assertSame(ActionButtonStyle::Icons, $appearance->actionButtons);
    }
}
