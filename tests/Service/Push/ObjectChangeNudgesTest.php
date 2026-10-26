<?php

declare(strict_types=1);

namespace App\Tests\Service\Push;

use App\Service\Push\ObjectChangeNudges;
use PHPUnit\Framework\TestCase;

final class ObjectChangeNudgesTest extends TestCase
{
    public function testOneEntryPerUserAndTypeAndDrainEmpties(): void
    {
        $nudges = new ObjectChangeNudges();
        $nudges->record(1, ObjectChangeNudges::CALENDAR);
        $nudges->record(1, ObjectChangeNudges::CALENDAR);
        $nudges->record(1, ObjectChangeNudges::NOTES);
        $nudges->record(2, ObjectChangeNudges::NOTES);

        self::assertSame([1 => ['CalendarEvent', 'Note'], 2 => ['Note']], $nudges->drain());
        self::assertSame([], $nudges->drain());
    }
}
