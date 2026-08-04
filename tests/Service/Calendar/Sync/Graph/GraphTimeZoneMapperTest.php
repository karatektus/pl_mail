<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar\Sync\Graph;

use App\Service\Calendar\Sync\Graph\GraphTimeZoneMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * "W. Europe Standard Time" is not a time zone anything but Windows has heard
 * of.
 *
 * That is the whole subject. Graph answers Windows zone ids on start, end,
 * originalStartTimeZone and recurrence ranges; DateTimeZone refuses every one
 * of them, JSCalendar's `timeZone` is defined as IANA, and sabre expands a
 * recurrence in an IANA zone. Passing the name through unconverted throws
 * inside RecurrenceMaterialiser, one layer too far from Graph for anybody to
 * recognise what happened — and "fixing" it by falling back to UTC moves a
 * Berlin standup by an hour for half the year without any error at all.
 *
 * A plain TestCase: this is a pure function of a string, and the thing worth
 * pinning is the table, not any collaboration.
 */
final class GraphTimeZoneMapperTest extends TestCase
{
    /**
     * @return list<array{string, string}>
     */
    public static function windowsZones(): array
    {
        return [
            ['W. Europe Standard Time', 'Europe/Berlin'],
            ['GMT Standard Time', 'Europe/London'],
            ['Pacific Standard Time', 'America/Los_Angeles'],
            // Not Europe/Moscow's neighbour and not a guess from the offset:
            // the pairing is CLDR's, and it is the one Outlook itself uses.
            ['India Standard Time', 'Asia/Calcutta'],
        ];
    }

    #[DataProvider('windowsZones')]
    public function testAWindowsZoneNameBecomesTheIanaOneItStandsFor(string $windows, string $iana): void
    {
        self::assertSame($iana, $this->mapper()->toIana($windows));
    }

    public function testTheZoneAWindowsNameStandsForIsOneDateTimeZoneWillAccept(): void
    {
        // The failure this guards is a mapper that answers a plausible-looking
        // string DateTimeZone then rejects, which surfaces as a thrown
        // exception inside the recurrence expander rather than here.
        $zone = $this->mapper()->zoneFor('W. Europe Standard Time');

        self::assertSame('Europe/Berlin', $zone->getName());
    }

    public function testGraphsUtcIsSpeltTheWayEverythingElseInPlMailSpellsIt(): void
    {
        // ICU answers Etc/UTC, which is legal IANA and is not what
        // Calendar::$timeZone defaults to. Two spellings of the same zone means
        // two calendars in the same zone that do not look like it.
        self::assertSame('UTC', $this->mapper()->toIana('UTC'));
    }

    public function testAnIanaNameGraphHappensToSendIsKeptRatherThanRejected(): void
    {
        // Graph accepts and echoes IANA names, so a calendar created by a client
        // that sent one gets its zone back unchanged.
        self::assertSame('Europe/Berlin', $this->mapper()->toIana('Europe/Berlin'));
    }

    public function testAMailboxWithHandEditedDstRulesGetsNoZoneRatherThanTheNearestOne(): void
    {
        // "Customized Time Zone" and tzone://Microsoft/Custom mean the mailbox
        // defined its own offsets. There is no IANA zone that means that, and
        // the nearest one is wrong twice a year for whoever set it.
        self::assertNull($this->mapper()->toIana('Customized Time Zone'));
        self::assertNull($this->mapper()->toIana('tzone://Microsoft/Custom'));
    }

    public function testAnEmptyOrMissingZoneIsNoZoneAtAll(): void
    {
        self::assertNull($this->mapper()->toIana(null));
        self::assertNull($this->mapper()->toIana('   '));
    }

    public function testAnUnresolvableZoneStillYieldsUtcToReadAGraphTimeIn(): void
    {
        // Instants must never depend on the table resolving — Graph answers its
        // times in UTC, so this is the zone they are actually in.
        self::assertSame('UTC', $this->mapper()->zoneFor('Customized Time Zone')->getName());
    }

    public function testAZoneIsSentBackToGraphInTheNameOutlookWillShow(): void
    {
        // Graph stores what it is given. An event written with "Europe/Berlin"
        // is one Outlook has to translate on every read, and which comes back
        // through originalStartTimeZone as something this mapper then has to
        // recognise anyway.
        self::assertSame('W. Europe Standard Time', $this->mapper()->toGraph('Europe/Berlin'));
        self::assertSame('UTC', $this->mapper()->toGraph('UTC'));
    }

    public function testAZoneWindowsHasNoNameForIsSentVerbatimRatherThanApproximated(): void
    {
        // Graph does accept IANA. A nearby Windows zone would put the meeting at
        // the wrong hour, which is worse than a name Outlook renders plainly.
        // Antarctica/Troll is one of the handful CLDR pairs with nothing: it
        // jumps two hours at once and no Windows zone does that.
        self::assertSame('Antarctica/Troll', $this->mapper()->toGraph('Antarctica/Troll'));
    }

    public function testAnEventWithNoZoneIsSentAsUtcRatherThanAsAnEmptyName(): void
    {
        // Graph rejects a dateTimeTimeZone with an empty timeZone outright, so
        // an all-day or floating event needs a name here, not a blank.
        self::assertSame('UTC', $this->mapper()->toGraph(null));
    }

    private function mapper(): GraphTimeZoneMapper
    {
        return new GraphTimeZoneMapper();
    }
}
