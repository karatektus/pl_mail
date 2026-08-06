<?php

declare(strict_types=1);

namespace App\Tests\Jmap\Method\Calendar;

use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Jmap\Method\Calendar\CalendarEventGetMethod;
use App\Jmap\Method\Calendar\CalendarEventQueryMethod;
use App\Jmap\Method\Calendar\CalendarEventSetMethod;
use App\Jmap\Protocol\Exception\MethodException;
use DateTimeImmutable;

/**
 * `CalendarEvent/query` with `expandRecurrences`: which days a series actually
 * lands on, in one query.
 *
 * The collapsed answer names a series and says nothing about where its
 * instances are, so a client drawing a month had one way to find out — ask
 * about one day at a time, thirty-one times, and see which windows the series
 * comes back in. Everything below is an assertion about the shape that replaces
 * that: one entry per occurrence, in occurrence order, paged over occurrences.
 *
 * The fixture is a Mon/Wed/Fri weekly series over three weeks because that is
 * the case where the two answers differ most visibly — one id collapsed, nine
 * expanded — and because a moved instance in the middle of it is the only way
 * to tell an expansion of the *rule* from a projection of what the server
 * actually materialised. A client is forbidden from expanding rules itself
 * (docs/CLIENT_DEVELOPMENT.md), so a server that answered from the rule rather
 * than from the occurrence rows would put the moved instance back where the rule
 * had it and nothing else in the response would say so.
 *
 * Times are anchored on a real Monday a few days out rather than a literal date:
 * occurrences exist only inside RecurrenceMaterialiser's horizon, so a fixture
 * pinned to a calendar date is a suite that starts failing one morning.
 */
final class CalendarEventQueryExpandRecurrencesTest extends CalendarMethodTestCase
{
    private CalendarEventQueryMethod $query;
    private CalendarEventGetMethod $get;
    private CalendarEventSetMethod $set;

    private Calendar $work;

    /** The Monday the series starts on, 00:00 UTC. */
    private DateTimeImmutable $monday;

    private CalendarEvent $standup;

    protected function setUp(): void
    {
        parent::setUp();

        $container = self::getContainer();
        $this->query = $container->get(CalendarEventQueryMethod::class);
        $this->get = $container->get(CalendarEventGetMethod::class);
        $this->set = $container->get(CalendarEventSetMethod::class);

        $this->work = $this->seedCalendar('Work');
        $this->monday = $this->baseDay()->modify('next monday');

        $this->standup = $this->seedEvent(
            $this->work,
            'Standup',
            $this->monday->setTime(9, 0),
            '+30 minutes',
            [
                '@type' => 'RecurrenceRule',
                'frequency' => 'weekly',
                'byDay' => [['day' => 'mo'], ['day' => 'we'], ['day' => 'fr']],
            ],
        );
    }

    /**
     * Nine occurrences over three weeks, in start order, each naming the series
     * it belongs to and the instant it happens at.
     *
     * The collapsed query answers the same window with one id, which is the
     * whole reason this exists.
     */
    public function testEveryOccurrenceInTheWindowIsItsOwnEntryInStartOrder(): void
    {
        $result = $this->expand($this->monday, $this->monday->modify('+21 days'));

        self::assertSame($this->expectedStarts(), $this->startsOf($result['ids']));
        self::assertSame(9, $result['total']);
        self::assertCount(9, array_unique($result['ids']), 'an expanded id must name one occurrence and no other');
        self::assertCount(1, $this->collapsedIds($this->monday, $this->monday->modify('+21 days')));
    }

    /**
     * An instance somebody dragged is drawn where it went, and is ordered there
     * — the Wednesday moved to the following Sunday afternoon sorts after that
     * week's Friday, not in the slot the rule would have put it in.
     *
     * Its id still carries the ORIGINAL start, because that is the only name the
     * instance keeps once it has moved: it is what recurrenceOverrides is keyed
     * by, and looking it up by where it went would find nothing the second time
     * it is edited.
     */
    public function testAMovedInstanceIsOrderedAtItsNewTimeAndNamedByItsOld(): void
    {
        $original = $this->monday->modify('+9 days')->setTime(9, 0);
        $moved = $this->monday->modify('+13 days')->setTime(17, 0);

        $this->override($this->standup, $original, [
            '@type' => 'Event',
            'start' => $moved->format('Y-m-d\TH:i:s'),
            'duration' => 'PT30M',
        ]);

        $result = $this->expand($this->monday, $this->monday->modify('+21 days'));

        $expected = $this->expectedStarts();
        // The Wednesday of the second week leaves its slot and reappears after
        // that week's Friday.
        unset($expected[4]);
        $expected = array_values($expected);
        array_splice($expected, 5, 0, [$moved->format('Y-m-d\TH:i:s')]);

        self::assertSame($expected, $this->startsOf($result['ids']));
        self::assertContains($this->instanceId($this->standup, $original), $result['ids'], 'a moved instance is still named by where the rule put it');
        self::assertNotContains($this->instanceId($this->standup, $moved), $result['ids']);
    }

    /** An instance taken off the series is not there, and nothing stands in for it. */
    public function testAnExcludedInstanceIsAbsent(): void
    {
        $excluded = $this->monday->modify('+2 days')->setTime(9, 0);

        $this->override($this->standup, $excluded, ['excluded' => true]);

        $result = $this->expand($this->monday, $this->monday->modify('+21 days'));

        $expected = $this->expectedStarts();
        unset($expected[1]);

        self::assertSame(array_values($expected), $this->startsOf($result['ids']));
        self::assertSame(8, $result['total']);
        self::assertNotContains($this->instanceId($this->standup, $excluded), $result['ids']);
    }

    /**
     * A one-off event sorts among the occurrences by its own start, and keeps
     * the plain series id it has always had — its single occurrence IS the
     * event, and the plain id is the one CalendarEvent/set accepts back.
     */
    public function testOneOffEventsInterleaveWithOccurrencesAndKeepTheirPlainId(): void
    {
        $dentist = $this->seedEvent($this->work, 'Dentist', $this->monday->setTime(15, 0));

        $result = $this->expand($this->monday, $this->monday->modify('+3 days'));

        self::assertSame(
            [
                $this->monday->setTime(9, 0)->format('Y-m-d\TH:i:s'),
                $this->monday->setTime(15, 0)->format('Y-m-d\TH:i:s'),
                $this->monday->modify('+2 days')->setTime(9, 0)->format('Y-m-d\TH:i:s'),
            ],
            $this->startsOf($result['ids']),
        );

        self::assertSame((string) $dentist->id, $result['ids'][1]);
    }

    /** position and limit page over occurrences, and total counts them. */
    public function testPositionAndLimitWindowOverOccurrences(): void
    {
        $all = $this->expand($this->monday, $this->monday->modify('+21 days'));

        $page = $this->expand($this->monday, $this->monday->modify('+21 days'), position: 3, limit: 3);

        self::assertSame(3, $page['position']);
        self::assertSame(3, $page['limit']);
        self::assertSame(9, $page['total'], 'total counts occurrences, not series');
        self::assertSame(array_slice($all['ids'], 3, 3), $page['ids']);
    }

    /**
     * The flag absent and the flag false are the same request, and both are the
     * answer this method gave before expansion existed: one id for the series,
     * however many times it occurs.
     */
    public function testAFalseOrAbsentFlagAnswersExactlyAsBefore(): void
    {
        $this->seedEvent($this->work, 'Dentist', $this->monday->setTime(15, 0));

        $absent = $this->query->handle($this->arguments($this->monday, $this->monday->modify('+21 days')), $this->context());
        $false = $this->query->handle(
            $this->arguments($this->monday, $this->monday->modify('+21 days')) + ['expandRecurrences' => false],
            $this->context(),
        );

        self::assertSame($absent, $false);
        self::assertSame(2, $absent['total']);
        self::assertSame([(string) $this->standup->id], array_slice($absent['ids'], 0, 1));
    }

    /**
     * Every expanded id is one CalendarEvent/get resolves, which is the round
     * trip the whole feature rests on: a client pairs the two in one request
     * through "#ids", so an id the getter refused would make the expansion a
     * list of strings nothing accepts.
     *
     * The instance object states its own times, names the series it belongs to,
     * and returns null for the two recurrence properties — a client that
     * expanded those again after asking the server to expand would draw the
     * whole series once per instance.
     */
    public function testEveryExpandedIdResolvesToItsOwnInstance(): void
    {
        $result = $this->expand($this->monday, $this->monday->modify('+7 days'));

        $objects = $this->get->handle([
            'accountId' => $this->accountId(),
            'ids' => $result['ids'],
        ], $this->context());

        self::assertSame([], $objects['notFound']);
        self::assertCount(3, $objects['list']);

        foreach ($objects['list'] as $index => $object) {
            self::assertSame($result['ids'][$index], $object['id']);
            self::assertSame((string) $this->standup->id, $object['seriesId']);
            self::assertSame('Standup', $object['title']);
            self::assertSame('PT30M', $object['duration']);
            self::assertSame('UTC', $object['recurrenceIdTimeZone']);
            self::assertNull($object['recurrenceRules']);
            self::assertNull($object['recurrenceOverrides']);
            // Nothing moved these, so where they are and where the rule put
            // them are the same LocalDateTime.
            self::assertSame($object['start'], $object['recurrenceId']);
        }
    }

    /** A renamed instance answers with its own title, not the series'. */
    public function testAnInstanceCarriesItsOwnOverriddenTitle(): void
    {
        $original = $this->monday->modify('+2 days')->setTime(9, 0);

        $this->override($this->standup, $original, ['@type' => 'Event', 'title' => 'Retro']);

        $object = $this->getOne($this->instanceId($this->standup, $original));

        self::assertSame('Retro', $object['title']);
        self::assertSame($original->format('Y-m-d\TH:i:s'), $object['recurrenceId']);
    }

    /**
     * A cancelled instance keeps its row and is struck through rather than
     * removed — so it is not in the query, and an id a client already holds
     * still resolves and says why.
     */
    public function testACancelledInstanceLeavesTheQueryButStillResolves(): void
    {
        $original = $this->monday->modify('+2 days')->setTime(9, 0);
        $id = $this->instanceId($this->standup, $original);

        $this->override($this->standup, $original, ['@type' => 'Event', 'status' => 'cancelled']);

        self::assertNotContains($id, $this->expand($this->monday, $this->monday->modify('+7 days'))['ids']);
        self::assertSame('cancelled', $this->getOne($id)['status']);
    }

    /** An id for an instance that no longer exists is notFound, not a silent series. */
    public function testAnInstanceIdForANonExistentOccurrenceIsNotFound(): void
    {
        $result = $this->get->handle([
            'accountId' => $this->accountId(),
            'ids' => [$this->instanceId($this->standup, $this->monday->modify('+1 day')->setTime(9, 0))],
        ], $this->context());

        self::assertSame([], $result['list']);
        self::assertCount(1, $result['notFound']);
    }

    /**
     * CalendarEvent/set cannot write one instance, and says so rather than
     * answering notFound about an id this server minted.
     */
    public function testAnInstanceIdIsRefusedByCalendarEventSetByName(): void
    {
        $id = $this->instanceId($this->standup, $this->monday->setTime(9, 0));

        $result = $this->set->handle([
            'accountId' => $this->accountId(),
            'update' => [$id => ['title' => 'Renamed']],
            'destroy' => [$id],
        ], $this->context());

        self::assertSame('invalidArguments', $result['notUpdated'][$id]['type']);
        self::assertSame('invalidArguments', $result['notDestroyed'][$id]['type']);
        self::assertStringContainsString('seriesId', $result['notUpdated'][$id]['description']);
    }

    /** A flag that is not a boolean is refused, never read as truthy. */
    public function testANonBooleanFlagIsRefused(): void
    {
        $this->expectException(MethodException::class);

        $this->query->handle(
            $this->arguments($this->monday, $this->monday->modify('+1 day')) + ['expandRecurrences' => 'yes'],
            $this->context(),
        );
    }

    /** A zone this server does not convert into is refused rather than ignored. */
    public function testATimeZoneArgumentIsRefusedWhenExpanding(): void
    {
        $this->expectException(MethodException::class);

        $this->query->handle(
            $this->arguments($this->monday, $this->monday->modify('+1 day'))
            + ['expandRecurrences' => true, 'timeZone' => 'Europe/Berlin'],
            $this->context(),
        );
    }

    /**
     * A window reaching past the materialised horizon is refused when expanding.
     *
     * Collapsed, the same window is merely thin — the series is named and its
     * rule comes with it. Expanded, the answer IS the instances, so a series
     * that stops at the horizon comes back as a series that ends, and nothing in
     * the response says otherwise.
     */
    public function testAWindowOutsideTheMaterialisedHorizonIsRefusedWhenExpanding(): void
    {
        $from = $this->monday->modify('+3 years');
        $to = $from->modify('+1 day');

        // The collapsed query still answers it, which is the behaviour this must
        // not have changed.
        self::assertSame([], $this->collapsedIds($from, $to));

        $this->expectException(MethodException::class);

        $this->expand($from, $to);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * @return array<string,mixed>
     */
    private function arguments(DateTimeImmutable $after, DateTimeImmutable $before): array
    {
        return [
            'accountId' => $this->accountId(),
            'filter' => [
                'inCalendar' => (string) $this->work->id,
                'after' => $after->format(DATE_ATOM),
                'before' => $before->format(DATE_ATOM),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function expand(DateTimeImmutable $after, DateTimeImmutable $before, ?int $position = null, ?int $limit = null): array
    {
        $arguments = $this->arguments($after, $before) + ['expandRecurrences' => true];

        if (null !== $position) {
            $arguments['position'] = $position;
        }

        if (null !== $limit) {
            $arguments['limit'] = $limit;
        }

        return $this->query->handle($arguments, $this->context());
    }

    /**
     * @return list<string>
     */
    private function collapsedIds(DateTimeImmutable $after, DateTimeImmutable $before): array
    {
        return $this->query->handle($this->arguments($after, $before), $this->context())['ids'];
    }

    /**
     * The LocalDateTime each id says it starts at, read back through
     * CalendarEvent/get rather than computed here — an id whose object states a
     * different time is the failure this is looking for.
     *
     * @param list<string> $ids
     *
     * @return list<string>
     */
    private function startsOf(array $ids): array
    {
        $starts = [];

        foreach ($ids as $id) {
            $starts[] = (string) $this->getOne($id)['start'];
        }

        return $starts;
    }

    /**
     * @return array<string,mixed>
     */
    private function getOne(string $id): array
    {
        $result = $this->get->handle([
            'accountId' => $this->accountId(),
            'ids' => [$id],
        ], $this->context());

        self::assertSame([], $result['notFound'], sprintf('CalendarEvent/get could not resolve "%s"', $id));

        return $result['list'][0];
    }

    /**
     * The nine Mon/Wed/Fri starts the fixture's window holds, in order.
     *
     * @return list<string>
     */
    private function expectedStarts(): array
    {
        $starts = [];

        for ($week = 0; $week < 3; ++$week) {
            foreach ([0, 2, 4] as $day) {
                $starts[] = $this->monday->modify(sprintf('+%d days', 7 * $week + $day))
                    ->setTime(9, 0)
                    ->format('Y-m-d\TH:i:s');
            }
        }

        return $starts;
    }

    /**
     * The id CalendarEvent/query mints for one instance, spelled here from the
     * outside so the test asserts against the wire format rather than against
     * the class that produces it.
     */
    private function instanceId(CalendarEvent $event, DateTimeImmutable $originalStart): string
    {
        return sprintf('%d_%s', (int) $event->id, $originalStart->format('Ymd\THis\Z'));
    }

    /**
     * @param array<string,mixed> $patch
     */
    private function override(CalendarEvent $event, DateTimeImmutable $originalStart, array $patch): void
    {
        // Keyed by the instance's original LocalDateTime in the series' zone,
        // which the fixture's calendars keep at UTC.
        $this->writer->overrideInstances($event, [$originalStart->format('Y-m-d\TH:i:s') => $patch]);

        $this->em->flush();
    }
}
