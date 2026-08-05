<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Method\Calendar;

use App\Entity\Calendar\Calendar;
use App\Jmap\Method\Calendar\CalendarEventGetMethod;
use App\Jmap\Method\Calendar\CalendarEventQueryMethod;
use App\Jmap\Method\Calendar\CalendarGetMethod;
use App\Jmap\Protocol\Exception\MethodException;
use DateTimeImmutable;

/**
 * Which id space the calendar methods speak, and whether a client can hand any
 * id back.
 *
 * CalendarEvent/query reads `calendar_event_occurrence` — a tsrange overlap
 * against the GiST index — and publishes `calendar_event` ids. Those are two
 * autoincrement sequences in two tables, so an untranslated occurrence id is
 * not a decode error: it is a *valid-looking* id for some unrelated event, and
 * the client fetches it and draws a plausible wrong answer. Email.mailboxIds
 * shipped exactly that bug with label ids where binding ids were meant, and
 * EmailMapperTest's docblock records how long it went unnoticed.
 *
 * So the assertions below are round trips rather than shape checks: every id
 * this server emits is fed back into the filter or the getter that consumes it
 * and must select **what it came from**. Deliberately not "the two id sequences
 * differ" — that is an assertion about how old the database is, which is the
 * other lesson from the same file.
 *
 * A recurring series is in the fixture on purpose. It is the case where the
 * two id spaces have visibly different cardinality — one event, many
 * occurrences — and where returning occurrence ids would give a client several
 * boxes for one meeting it cannot collapse.
 */
final class CalendarEventQueryMethodTest extends CalendarMethodTestCase
{
    private CalendarEventQueryMethod $query;
    private CalendarEventGetMethod $get;
    private CalendarGetMethod $calendarGet;

    private Calendar $work;
    private Calendar $personal;

    private DateTimeImmutable $day;

    protected function setUp(): void
    {
        parent::setUp();

        $container = self::getContainer();
        $this->query = $container->get(CalendarEventQueryMethod::class);
        $this->get = $container->get(CalendarEventGetMethod::class);
        $this->calendarGet = $container->get(CalendarGetMethod::class);

        $this->day = $this->baseDay();
        $this->work = $this->seedCalendar('Work');
        $this->personal = $this->seedCalendar('Personal');

        // Two one-off events on Work inside the window, one on Personal, and a
        // weekly series whose occurrences outnumber every event in the fixture
        // several times over.
        $this->seedEvent($this->work, 'Standup', $this->day->setTime(9, 0), '+15 minutes', ['@type' => 'RecurrenceRule', 'frequency' => 'weekly']);
        $this->seedEvent($this->work, 'Design review', $this->day->setTime(11, 0));
        $this->seedEvent($this->personal, 'Dentist', $this->day->setTime(15, 0));
        // Outside every window this test asks about.
        $this->seedEvent($this->work, 'Next month', $this->day->modify('+40 days')->setTime(9, 0));
    }

    public function testTheWindowSelectsOnlyTheEventsInsideIt(): void
    {
        $titles = $this->titlesOf($this->idsInWindow($this->day, $this->day->modify('+1 day')));

        sort($titles);

        self::assertSame(['Dentist', 'Design review', 'Standup'], $titles);
    }

    /**
     * The assertion that would have caught the original defect, in its calendar
     * form: an id read out of a response has to be one the server accepts back.
     *
     * Occurrence ids and event ids both decode, both exist, and both name a row
     * — so a wrong id space does not fail here by throwing. It fails by
     * resolving to something the id did not come from, which is what these two
     * assertions are about.
     */
    public function testEveryQueriedIdResolvesToTheEventItCameFrom(): void
    {
        $ids = $this->idsInWindow($this->day, $this->day->modify('+1 day'));

        self::assertNotSame([], $ids, 'the fixture must produce ids for this to assert anything');

        $result = $this->get->handle([
            'accountId' => $this->accountId(),
            'ids' => $ids,
        ], $this->context());

        self::assertSame([], $result['notFound'], 'a queried id that CalendarEvent/get cannot resolve is an id from another table');
        self::assertCount(count($ids), $result['list']);

        foreach ($result['list'] as $event) {
            self::assertContains($event['id'], $ids, 'CalendarEvent/get answered with an id nobody asked for');
        }
    }

    /**
     * The same round trip through the filter rather than the getter: an event id
     * belongs to the calendar it was queried under, and re-querying that
     * calendar alone finds it again.
     */
    public function testAQueriedIdIsFoundAgainByTheCalendarItCameFrom(): void
    {
        foreach ([$this->work, $this->personal] as $calendar) {
            $ids = $this->idsInWindow($this->day, $this->day->modify('+1 day'), (string) $calendar->id);

            foreach ($ids as $id) {
                $result = $this->get->handle([
                    'accountId' => $this->accountId(),
                    'ids' => [$id],
                ], $this->context());

                self::assertSame(
                    (string) $calendar->id,
                    $result['list'][0]['calendarId'] ?? null,
                    sprintf('event "%s" was queried on calendar %d but says it lives elsewhere', $id, (int) $calendar->id),
                );
            }
        }
    }

    /**
     * The other id space this publishes: a Calendar id has to be usable as
     * "inCalendar", and has to select that calendar's events rather than
     * another's.
     */
    public function testEveryCalendarIdIsAUsableInCalendarFilter(): void
    {
        $calendars = $this->calendarGet->handle(['accountId' => $this->accountId()], $this->context());

        $seen = [];

        foreach ($calendars['list'] as $calendar) {
            $titles = $this->titlesOf($this->idsInWindow($this->day, $this->day->modify('+1 day'), $calendar['id']));

            sort($titles);
            $seen[$calendar['name']] = $titles;
        }

        ksort($seen);

        self::assertSame(
            [
                'Personal' => ['Dentist'],
                'Work' => ['Design review', 'Standup'],
            ],
            $seen,
        );
    }

    /**
     * A weekly meeting overlapping a month-long window is one event, not one per
     * week. Returning an id per occurrence would draw four meetings a client
     * has no way to collapse — and it is the shape a naive translation produces,
     * because the range read genuinely answers four rows.
     */
    public function testARecurringSeriesIsOneIdHoweverOftenItOccurs(): void
    {
        $from = $this->day;
        $to = $this->day->modify('+35 days');

        $ids = $this->idsInWindow($from, $to, (string) $this->work->id);

        self::assertSame(['Design review', 'Standup'], $this->sortedTitles($ids));
        self::assertSame(count($ids), count(array_unique($ids)), 'ids must not repeat');

        // The assertion above only means something while the window genuinely
        // holds more occurrences than events; without this the test would keep
        // passing if the fixture stopped recurring.
        self::assertGreaterThan(
            count($ids),
            $this->occurrencesInWindow($this->work, $from, $to),
            'the window must overlap more occurrence rows than it does events',
        );
    }

    /**
     * Somebody else's calendar selects nothing, rather than being reported as
     * missing. "There is no calendar 41 for you" and "calendar 41 belongs to
     * somebody else" must be the same answer, or the error message is a
     * membership oracle.
     */
    public function testAnotherUsersCalendarSelectsNothingRatherThanFailing(): void
    {
        $theirs = $this->seedCalendar('Theirs', false, $this->otherUser());
        $this->seedEvent($theirs, 'Their meeting', $this->day->setTime(10, 0));

        self::assertSame([], $this->idsInWindow($this->day, $this->day->modify('+1 day'), (string) $theirs->id));
    }

    /** And it is not merely absent from a filtered query — an unfiltered one cannot reach it either. */
    public function testAnotherUsersEventsAreNotInAnUnfilteredQuery(): void
    {
        $theirs = $this->seedCalendar('Theirs', false, $this->otherUser());
        $this->seedEvent($theirs, 'Their meeting', $this->day->setTime(10, 0));

        self::assertNotContains('Their meeting', $this->titlesOf($this->idsInWindow($this->day, $this->day->modify('+1 day'))));
    }

    /**
     * An unbounded query is refused rather than answered.
     *
     * Occurrences exist only inside RecurrenceMaterialiser's horizon, so
     * "everything" would come back looking complete while stopping two years
     * out — and a client cannot detect a truncation nobody reported.
     */
    public function testAQueryWithNoWindowIsRefusedRatherThanTruncated(): void
    {
        $this->expectException(MethodException::class);

        $this->query->handle(['accountId' => $this->accountId()], $this->context());
    }

    /** A condition that is not understood is refused, never quietly dropped: dropping one returns too much. */
    public function testAnUnknownFilterConditionIsRefused(): void
    {
        $this->expectException(MethodException::class);

        $this->query->handle([
            'accountId' => $this->accountId(),
            'filter' => [
                'after' => $this->day->format(DATE_ATOM),
                'before' => $this->day->modify('+1 day')->format(DATE_ATOM),
                'title' => 'Standup',
            ],
        ], $this->context());
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * @return list<string>
     */
    private function idsInWindow(DateTimeImmutable $after, DateTimeImmutable $before, ?string $inCalendar = null): array
    {
        $filter = [
            'after' => $after->format(DATE_ATOM),
            'before' => $before->format(DATE_ATOM),
        ];

        if (null !== $inCalendar) {
            $filter['inCalendar'] = $inCalendar;
        }

        $result = $this->query->handle([
            'accountId' => $this->accountId(),
            'filter' => $filter,
        ], $this->context());

        return $result['ids'];
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string>
     */
    private function titlesOf(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        $result = $this->get->handle([
            'accountId' => $this->accountId(),
            'ids' => $ids,
        ], $this->context());

        $titles = [];

        foreach ($result['list'] as $event) {
            $titles[] = (string) ($event['title'] ?? '');
        }

        return $titles;
    }

    /** The rows the range read actually overlaps, which is what the ids are collapsed from. */
    private function occurrencesInWindow(Calendar $calendar, DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM calendar_event_occurrence
             WHERE calendar_id = :calendarId AND span && tsrange(:from, :to, \'[)\')',
            [
                'calendarId' => (int) $calendar->id,
                'from' => $from->format('Y-m-d H:i:s'),
                'to' => $to->format('Y-m-d H:i:s'),
            ],
        );
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string>
     */
    private function sortedTitles(array $ids): array
    {
        $titles = $this->titlesOf($ids);
        sort($titles);

        return $titles;
    }
}
