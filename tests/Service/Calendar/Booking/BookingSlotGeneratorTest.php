<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar\Booking;

use App\Entity\Calendar\BookingPage;
use App\Service\Calendar\Booking\BookingSlotGenerator;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

/**
 * A slot is a wall-clock fact in the owner's zone and an instant everywhere
 * else, and the two agree across a daylight-saving boundary.
 *
 * This is the half of the booking feature that is silently wrong twice a year
 * if it is written the obvious way, which is to take the previous slot and add
 * the slot length:
 *
 *   **Spring forward.** 02:00 to 03:00 does not exist on the changeover day.
 *   PHP resolves a non-existent local time forward, so two adjacent local slots
 *   can land on the SAME instant — and the page offers one appointment twice,
 *   which two people then book and only one of whom gets it. The generator
 *   drops the second spelling; the test below is what proves it.
 *
 *   **Autumn back.** 02:00 to 03:00 happens twice. Local arithmetic walks the
 *   wall clock while the instants jump an extra hour, so a day silently loses
 *   an hour of slots or gains a pair sitting on top of each other. What must
 *   hold is that every offered instant is distinct and that the hours the owner
 *   named are the hours offered — 09:00 is nine in the morning in March and in
 *   November, not 08:00 in one of them.
 *
 * A plain TestCase, and the entity is built by hand. The generator takes no
 * collaborators, makes no query and reads no clock — it is a function of a page
 * and a window — so a container and a database would contribute nothing but
 * time. Everything it depends on is on the BookingPage in front of it, which is
 * also why the fixtures here are three lines each.
 *
 * Europe/Berlin for the boundaries because its transitions are at 02:00 local,
 * inside a working day that starts at 00:00 in the DST cases — a page whose
 * hours were 09:00 to 17:00 would never touch either transition and the test
 * would pass against the bug.
 */
final class BookingSlotGeneratorTest extends TestCase
{
    private BookingSlotGenerator $generator;

    protected function setUp(): void
    {
        $this->generator = new BookingSlotGenerator();
    }

    /**
     * 29 March 2026 is the spring transition in Europe/Berlin: 02:00 becomes
     * 03:00 and the hour between does not exist.
     */
    public function testASpringForwardDayOffersEachInstantOnceRatherThanTwice(): void
    {
        $page = $this->allDayPage('Europe/Berlin', slotMinutes: 30);

        $slots = $this->generator->generate(
            $page,
            new DateTimeImmutable('2026-03-29 00:00:00', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-03-30 00:00:00', new DateTimeZone('UTC')),
        );

        $keys = array_map(static fn ($slot): string => $slot->key(), $slots);

        self::assertSame(
            $keys,
            array_values(array_unique($keys)),
            'the same instant was offered twice — a non-existent local time resolved onto a real one',
        );
    }

    /**
     * 25 October 2026 is the autumn transition: 03:00 becomes 02:00 and the
     * hour between happens twice.
     */
    public function testAnAutumnFallBackDayKeepsEveryInstantDistinct(): void
    {
        $page = $this->allDayPage('Europe/Berlin', slotMinutes: 30);

        $slots = $this->generator->generate(
            $page,
            new DateTimeImmutable('2026-10-25 00:00:00', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-10-26 00:00:00', new DateTimeZone('UTC')),
        );

        $keys = array_map(static fn ($slot): string => $slot->key(), $slots);

        self::assertSame($keys, array_values(array_unique($keys)), 'a repeated wall clock produced a repeated instant');
        self::assertSame($keys, $this->sorted($keys), 'the slots came back out of order across the transition');
    }

    /**
     * The wall clock is what the owner configured, whichever side of a
     * transition the day is on. A generator that anchored on an instant instead
     * would move the working day by an hour for half the year — which is how a
     * page starts offering 08:00 in the winter.
     */
    public function testTheFirstSlotIsTheOwnersWallClockOnBothSidesOfATransition(): void
    {
        $page = $this->workingDayPage('Europe/Berlin');
        $zone = new DateTimeZone('Europe/Berlin');

        $march = $this->generator->generate(
            $page,
            new DateTimeImmutable('2026-03-30 00:00:00', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-03-31 00:00:00', new DateTimeZone('UTC')),
        );

        $november = $this->generator->generate(
            $page,
            new DateTimeImmutable('2026-11-02 00:00:00', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-11-03 00:00:00', new DateTimeZone('UTC')),
        );

        self::assertNotSame([], $march);
        self::assertNotSame([], $november);

        self::assertSame('09:00', $march[0]->startsAt->setTimezone($zone)->format('H:i'));
        self::assertSame('09:00', $november[0]->startsAt->setTimezone($zone)->format('H:i'));

        // And the two are genuinely different instants, which is what makes the
        // assertion above about the wall clock rather than about UTC.
        // Berlin is +02:00 in March (CEST) and +01:00 in November (CET), so
        // the two nine-o'clocks are an hour apart in UTC. If they were equal,
        // the generator would be anchoring on an instant rather than on a wall
        // clock — which is the bug this whole file is about.
        self::assertSame('07:00', $march[0]->startsAt->format('H:i'));
        self::assertSame('08:00', $november[0]->startsAt->format('H:i'));
    }

    /**
     * A slot that ran past the end of the bookable day would book over whatever
     * the owner does at five, which is the hour the working day was defined to
     * protect.
     */
    public function testNoSlotEndsAfterTheBookableDayDoes(): void
    {
        // 09:00–17:00 is 480 minutes; 45 minute slots fit ten times with 30
        // minutes to spare, so the tenth starts at 15:45 and the eleventh would
        // end at 17:15.
        $page              = $this->workingDayPage('Europe/Berlin');
        $page->slotMinutes = 45;

        $slots = $this->generator->generate(
            $page,
            new DateTimeImmutable('2026-06-01 00:00:00', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-06-02 00:00:00', new DateTimeZone('UTC')),
        );

        self::assertCount(10, $slots);

        $zone = new DateTimeZone('Europe/Berlin');
        $last = $slots[count($slots) - 1];

        self::assertSame('15:45', $last->startsAt->setTimezone($zone)->format('H:i'));
        self::assertSame('16:30', $last->endsAt->setTimezone($zone)->format('H:i'));
    }

    /** A day the owner did not tick offers nothing at all. */
    public function testAClosedWeekdayOffersNothing(): void
    {
        $page = $this->workingDayPage('Europe/Berlin');

        // 2026-06-06 is a Saturday; the page is open Monday to Friday.
        $slots = $this->generator->generate(
            $page,
            new DateTimeImmutable('2026-06-06 00:00:00', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-06-07 00:00:00', new DateTimeZone('UTC')),
        );

        self::assertSame([], $slots);
    }

    /**
     * A page whose hours are backwards, or whose slot is longer than the day it
     * is open, offers nothing rather than throwing. It is a configuration a form
     * can produce, and a stranger's GET must not meet a 500 over it.
     */
    public function testAPageWithNoBookableTimeAnswersNothingRatherThanThrowing(): void
    {
        $page              = $this->workingDayPage('Europe/Berlin');
        $page->slotMinutes = 600;

        self::assertSame([], $this->generator->generate(
            $page,
            new DateTimeImmutable('2026-06-01 00:00:00', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-06-02 00:00:00', new DateTimeZone('UTC')),
        ));
    }

    /**
     * The window bounds the walk whatever the row says. A horizon read straight
     * off a hand-edited column would turn one public GET into a decade of date
     * arithmetic; the generator clamps its own loop, and the window is the other
     * half.
     */
    public function testTheWalkIsBoundedByTheWindowRatherThanByTheRow(): void
    {
        $page              = $this->workingDayPage('Europe/Berlin');
        $page->horizonDays = 100000;

        $slots = $this->generator->generate(
            $page,
            new DateTimeImmutable('2026-06-01 00:00:00', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-06-03 00:00:00', new DateTimeZone('UTC')),
        );

        // Two working days at 09:00–17:00 in half hours.
        self::assertCount(32, $slots);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    /** Open every day, all round the clock — so a DST transition is inside the offered hours. */
    private function allDayPage(string $zone, int $slotMinutes): BookingPage
    {
        $page              = new BookingPage();
        $page->timeZone    = $zone;
        $page->weekdays    = [1, 2, 3, 4, 5, 6, 7];
        $page->startMinute = 0;
        $page->endMinute   = BookingPage::MINUTES_IN_DAY;
        $page->slotMinutes = $slotMinutes;

        return $page;
    }

    /** Monday to Friday, 09:00 to 17:00, half hours. */
    private function workingDayPage(string $zone): BookingPage
    {
        $page              = new BookingPage();
        $page->timeZone    = $zone;
        $page->weekdays    = [1, 2, 3, 4, 5];
        $page->startMinute = 540;
        $page->endMinute   = 1020;
        $page->slotMinutes = 30;

        return $page;
    }

    /**
     * @param list<string> $keys
     *
     * @return list<string>
     */
    private function sorted(array $keys): array
    {
        sort($keys);

        return $keys;
    }
}
