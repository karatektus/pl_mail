<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar;

use App\Domain\Enum\Calendar\CalendarRole;
use App\Domain\DTO\Calendar\OccurrenceCluster;
use App\Domain\Enum\Calendar\CalendarView;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\User\User;
use App\Service\Calendar\CalendarEventWriter;
use App\Service\Calendar\CalendarRangeReader;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * An all-day event is FLOATING, and every reader has to treat it that way.
 *
 * "Floating" means the columns hold a wall-clock date at midnight and no zone
 * at all — the 4th of August is the 4th of August in Auckland and in Honolulu.
 * RecurrenceMaterialiser has always expanded them in UTC for that reason, and
 * the entity stores what it wrote. The readers did not agree: they took the
 * stored instant and converted it into the user's zone, which does not
 * translate a floating date, it moves it.
 *
 * Two symptoms, one cause. East of UTC the event acquired hours — an all-day
 * invitation opened in the editor reading **02:00 – 02:00** for a Berlin user,
 * which is what put this on the todo list — and because it then ran from 02:00
 * on its day to 02:00 on the next, the day walk filed it under *both* days and
 * the calendar drew it twice. West of UTC the same arithmetic moves it onto the
 * day before entirely, which is the worse half and the one nobody would have
 * reported as a timezone bug.
 *
 * Pinned against Pacific/Auckland (+12/+13) rather than Berlin: at +2 an
 * all-day event that gains two hours still lands on the right *date*, so a
 * Berlin fixture would go green on the day-placement half of this while the
 * bug was still there for anybody further east. Twelve hours cannot hide.
 */
final class AllDayEventTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private CalendarEventWriter $writer;
    private CalendarRangeReader $reader;
    private User $user;
    private Calendar $calendar;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->writer     = $container->get(CalendarEventWriter::class);
        $this->reader     = $container->get(CalendarRangeReader::class);

        $this->connection->beginTransaction();
        $this->seed();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /**
     * The headline: one day, not two. A calendar that draws the same all-day
     * event on the 4th and again on the 5th is the shape a user sees; the hours
     * it silently gained are the cause.
     */
    public function testAnAllDayEventOccupiesExactlyTheDayItIsOn(): void
    {
        $this->allDayEvent('Public holiday', '2026-08-04', '2026-08-05');

        self::assertSame(
            ['2026-08-04'],
            $this->daysHolding('Public holiday'),
            'an all-day event belongs to its own day and to no other',
        );
    }

    /** Two whole days is two days, and still not three. */
    public function testATwoDayEventOccupiesBothOfThem(): void
    {
        $this->allDayEvent('Offsite', '2026-08-04', '2026-08-06');

        self::assertSame(['2026-08-04', '2026-08-05'], $this->daysHolding('Offsite'));
    }

    /**
     * The control. A timed event is an instant and MUST be converted — in
     * Auckland, 23:00 UTC on the 4th is the morning of the 5th, and a reader
     * that stopped converting everything would file it a day early.
     */
    public function testATimedEventIsStillReadOnTheUsersClock(): void
    {
        $this->timedEvent('Late call', '2026-08-04 23:00');

        self::assertSame(
            ['2026-08-05'],
            $this->daysHolding('Late call'),
            'a timed event is an instant and belongs to the local day it falls on',
        );
    }

    /**
     * What the materialiser wrote, asserted directly, because everything above
     * depends on it: the stored columns are the wall date at midnight. If this
     * ever stops being true the readers become wrong again in the other
     * direction, and silently.
     */
    public function testTheStoredColumnsAreTheWallDateAtMidnight(): void
    {
        $event = $this->allDayEvent('Public holiday', '2026-08-04', '2026-08-05');

        self::assertSame('2026-08-04 00:00', $event->startsAt->format('Y-m-d H:i'));
        self::assertSame('2026-08-05 00:00', $event->endsAt->format('Y-m-d H:i'));
        self::assertNull($event->timeZone, 'a floating event carries no zone, by definition');
        self::assertTrue($event->jscalendar['showWithoutTime'] ?? false);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /**
     * The Y-m-d keys of the days a titled event's cluster was filed under,
     * read through the same path a calendar page uses.
     *
     * @return list<string>
     */
    private function daysHolding(string $title): array
    {
        $view = $this->reader->read(
            $this->user,
            CalendarView::Week,
            new DateTimeImmutable('2026-08-04 12:00', new DateTimeZone('Pacific/Auckland')),
        );

        $keys = [];

        foreach ($view['days'] as $dayKey => $clusters) {
            foreach ($clusters as $cluster) {
                if ($cluster instanceof OccurrenceCluster && $title === $cluster->primary->event?->title) {
                    $keys[] = $dayKey;

                    break;
                }
            }
        }

        return $keys;
    }

    /** `$endsOn` is exclusive, as iCalendar and every mapper here write it. */
    private function allDayEvent(string $title, string $startsOn, string $endsOn): CalendarEvent
    {
        $utc = new DateTimeZone('UTC');

        $event      = new CalendarEvent();
        $event->uid = uniqid('allday-', true) . '@plmail.test';

        $written = $this->writer->write(
            event:    $event,
            calendar: $this->calendar,
            user:     $this->user,
            title:    $title,
            startsAt: new DateTimeImmutable($startsOn . ' 00:00', $utc),
            endsAt:   new DateTimeImmutable($endsOn . ' 00:00', $utc),
            timeZone: null,
            isAllDay: true,
        );

        $this->em->flush();

        return $written;
    }

    private function timedEvent(string $title, string $startsAtUtc): CalendarEvent
    {
        $utc      = new DateTimeZone('UTC');
        $startsAt = new DateTimeImmutable($startsAtUtc, $utc);

        $event      = new CalendarEvent();
        $event->uid = uniqid('timed-', true) . '@plmail.test';

        $written = $this->writer->write(
            event:    $event,
            calendar: $this->calendar,
            user:     $this->user,
            title:    $title,
            startsAt: $startsAt,
            endsAt:   $startsAt->modify('+1 hour'),
            timeZone: 'UTC',
        );

        $this->em->flush();

        return $written;
    }

    private function seed(): void
    {
        $user            = new User();
        $user->email     = 'allday-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'All';
        $user->nameLast  = 'Day';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        // The display zone comes off the DEFAULT calendar — see
        // CalendarRangeReader::zoneOf() — so it is the one that has to be far
        // from UTC for this fixture to mean anything.
        $calendar            = new Calendar();
        $calendar->usr       = $user;
        $calendar->name      = 'Auckland';
        $calendar->role      = CalendarRole::Default;
        $calendar->isDefault = true;
        $calendar->timeZone  = 'Pacific/Auckland';
        $this->em->persist($calendar);

        $this->em->flush();

        $this->user     = $user;
        $this->calendar = $calendar;
    }
}
