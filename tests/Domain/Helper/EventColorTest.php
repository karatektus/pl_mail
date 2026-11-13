<?php

declare(strict_types=1);

namespace App\Tests\Domain\Helper;

use App\Domain\Helper\EventColor;
use PHPUnit\Framework\TestCase;

/**
 * What an event's stored colour is drawn as (#616).
 *
 * The claims: every value comes out as six hex digits or not at all, because
 * every template appends two more for the alpha; and the picker only offers
 * names RFC 7986 can carry.
 */
final class EventColorTest extends TestCase
{
    public function testANameIsDrawnAsItsColourWhateverItsCase(): void
    {
        self::assertSame('#40e0d0', EventColor::hexOf('turquoise'));
        self::assertSame('#40e0d0', EventColor::hexOf(' Turquoise '));
    }

    public function testAHexIsAlwaysSixDigitsSoAnAlphaCanBeAppended(): void
    {
        self::assertSame('#ff8800', EventColor::hexOf('#FF8800'));
        self::assertSame('#ff8800', EventColor::hexOf('#f80'));
    }

    public function testAnythingElseFallsBackToTheCalendar(): void
    {
        foreach (['', 'sort-of-blue', '#ff88', 'rgb(1,2,3)', null, 42] as $value) {
            self::assertNull(EventColor::hexOf($value), var_export($value, true));
        }
    }

    public function testThePickerOffersOnlyNamesTheWireCanCarry(): void
    {
        self::assertCount(147, EventColor::NAMES, 'CSS Color Level 3 has 147 names');
        self::assertCount(count(EventColor::PICKER), array_unique(EventColor::PICKER));

        foreach (EventColor::PICKER as $name) {
            self::assertArrayHasKey($name, EventColor::NAMES, $name);
        }
    }

    public function testATypedHexIsOfferedTheNearestNameThePickerHas(): void
    {
        self::assertSame('darkorange', EventColor::nearestPickerName('#ff8800'));
        self::assertNull(EventColor::nearestPickerName('nope'));
    }

    public function testTheTickIsDarkOnALightColourAndWhiteOnADarkOne(): void
    {
        self::assertSame('#232220', EventColor::onColor(EventColor::NAMES['gold']));
        self::assertSame('#ffffff', EventColor::onColor(EventColor::NAMES['midnightblue']));
    }
}
