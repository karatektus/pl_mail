<?php

declare(strict_types=1);

namespace App\Tests\Entity\Embeddable;

use App\Entity\Embeddable\Appearance;
use PHPUnit\Framework\TestCase;

final class AppearanceCalendarDirectTest extends TestCase
{
    /** Off for everyone: the button keeps cycling until somebody changes it. */
    public function testTheCalendarButtonCyclesUnlessSwitchedToDirect(): void
    {
        self::assertFalse(new Appearance()->calendarDirect);
    }

    /** It travels with a theme export and a config backup like the others. */
    public function testItSurvivesAnExportAndImport(): void
    {
        $source                 = new Appearance();
        $source->calendarDirect = true;

        self::assertTrue($source->toArray()['calendarDirect']);
        self::assertTrue(new Appearance()->applyArray($source->toArray())->calendarDirect);
        self::assertTrue(new Appearance()->applyArray(['calendarDirect' => '1'])->calendarDirect);
        self::assertFalse(new Appearance()->applyArray(['calendarDirect' => '0'])->calendarDirect);

        // Absent from a file written before the setting existed: left as it is.
        $kept                 = new Appearance();
        $kept->calendarDirect = true;

        self::assertTrue($kept->applyArray(['accountCorner' => true])->calendarDirect);
    }
}
