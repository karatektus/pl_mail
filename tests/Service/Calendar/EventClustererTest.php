<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar;

use App\Domain\Enum\Calendar\CalendarRole;
use App\Domain\Enum\Calendar\EventStatus;
use App\Domain\Enum\Calendar\ExtractionKind;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\Calendar\CalendarEventOccurrence;
use App\Entity\User\User;
use App\Service\Calendar\CalendarEventWriter;
use App\Service\Calendar\EventClusterer;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * One meeting that arrived twice draws one chip — and stops the moment the two
 * copies stop agreeing.
 *
 * An invitation arrives by mail and is extracted onto the account's calendar
 * with the organiser's UID; the provider auto-adds the same meeting to the
 * user's primary calendar and the mirror pulls it onto a Remote calendar, same
 * UID, its own remote id. CalendarPuller's fallback from remoteId to uid is
 * scoped to one calendar and these are two, so both rows exist — correctly, and
 * the user sees the meeting twice. That is the bug this guards.
 *
 * Two failures are guarded in the other direction, and they are the reason the
 * rules are what they are:
 *
 *   Matching on title and time rather than on UID collapses a weekly 1:1 held
 *   with two different people at the same hour into one chip. A meeting silently
 *   disappearing from a calendar is worse than a meeting shown twice.
 *
 *   Merging copies that disagree picks a winner and shows it, hiding the fact
 *   that an update reached one path and not the other. The disagreement IS the
 *   news; a cluster that cannot agree splits back into a chip each.
 *
 * cluster() takes no collaborators — it is a pure function of the rows handed to
 * it — so the agreement rules are table-driven over entities built in memory,
 * which is also what lets a case assert on an unpersisted fixture with no id.
 * copiesOf() reads a repository, so those cases run against a real container and
 * a real database, wrapped in a transaction that is never committed.
 */
final class EventClustererTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private EventClusterer $clusterer;
    private CalendarEventWriter $writer;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->clusterer  = $container->get(EventClusterer::class);
        $this->writer     = $container->get(CalendarEventWriter::class);

        // Never committed, so the suite leaves nothing behind and can be run
        // repeatedly against the same database.
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
     * @param list<array<string,mixed>> $rows
     * @param list<int>                 $expected sizes of the clusters, in order
     */
    #[DataProvider('agreementCases')]
    public function testTheChipsARangeDrawsAreTheClustersItsRowsAgreeOn(array $rows, array $expected): void
    {
        $clusters = $this->clusterer->cluster($this->occurrences($rows));

        self::assertSame(
            $expected,
            array_map(static fn ($cluster): int => count($cluster->members), $clusters),
            'the clusters are the chips, so their sizes are what the user counts',
        );
    }

    /**
     * @return iterable<string, array{list<array<string,mixed>>, list<int>}>
     */
    public static function agreementCases(): iterable
    {
        yield 'two copies of one meeting on two calendars draw one chip' => [
            [
                ['calendar' => 'account', 'uid' => 'invite@organiser.test'],
                ['calendar' => 'mirror',  'uid' => 'invite@organiser.test'],
            ],
            [2],
        ];

        // The pair the "Happening Soon" screenshot showed twice: the copy an
        // extractor read out of the invitation, carrying a kind and a message
        // behind it, and the copy the provider mirrored, carrying neither. They
        // differ in provenance and in nothing a user would notice, which is
        // exactly the case the five-field signature is meant to merge — so
        // provenance must not be a sixth field.
        yield 'the extracted copy and the mirrored copy of one meeting draw one chip' => [
            [
                ['calendar' => 'account', 'uid' => 'invite@organiser.test', 'kind' => ExtractionKind::Meeting],
                ['calendar' => 'mirror',  'uid' => 'invite@organiser.test'],
            ],
            [2],
        ];

        // The other half of the same story, and the reason the fix is a display
        // one rather than a wider match. An extraction that minted its OWN uid
        // — schema.org markup hashes one, an invite carries the organiser's —
        // is a second meeting by construction, and collapsing it on title and
        // time would be the weekly-1:1 bug below wearing a different hat.
        yield 'an extracted copy under a uid of its own is a second meeting' => [
            [
                ['calendar' => 'account', 'uid' => 'invite@organiser.test'],
                ['calendar' => 'account', 'uid' => 'a1b2c3@plmail', 'kind' => ExtractionKind::Ticket],
            ],
            [1, 1],
        ];

        yield 'a lone event is a cluster of one and is left alone' => [
            [
                ['calendar' => 'account', 'uid' => 'dentist@plmail'],
            ],
            [1],
        ];

        // The case that forbids matching on title and time. Two meetings at
        // 09:00 with the same name are a weekly 1:1 held with two different
        // people, and merging them takes one off the calendar.
        yield 'two events sharing a start but not a uid stay separate' => [
            [
                ['calendar' => 'account', 'uid' => 'alice@plmail', 'title' => '1:1'],
                ['calendar' => 'mirror',  'uid' => 'bob@plmail',   'title' => '1:1'],
            ],
            [1, 1],
        ];

        yield 'a copy that starts elsewhere is its own meeting' => [
            [
                ['calendar' => 'account', 'uid' => 'moved@plmail'],
                ['calendar' => 'mirror',  'uid' => 'moved@plmail', 'start' => '2026-05-04 10:00'],
            ],
            [1, 1],
        ];

        yield 'a copy that ends elsewhere splits the cluster' => [
            [
                ['calendar' => 'account', 'uid' => 'longer@plmail'],
                ['calendar' => 'mirror',  'uid' => 'longer@plmail', 'end' => '2026-05-04 11:00'],
            ],
            [1, 1],
        ];

        yield 'a copy renamed on one path splits the cluster' => [
            [
                ['calendar' => 'account', 'uid' => 'renamed@plmail', 'title' => 'Sync'],
                ['calendar' => 'mirror',  'uid' => 'renamed@plmail', 'title' => 'Sync (moved room)'],
            ],
            [1, 1],
        ];

        yield 'a copy that became all-day splits the cluster' => [
            [
                ['calendar' => 'account', 'uid' => 'allday@plmail'],
                ['calendar' => 'mirror',  'uid' => 'allday@plmail', 'isAllDay' => true],
            ],
            [1, 1],
        ];

        // The disagreement that actually reaches a view: the range query drops
        // cancelled occurrence ROWS before anything sees them, so what a reader
        // meets is one copy whose event says cancelled beside one that says
        // confirmed. Merged, the meeting is drawn as though it were still on.
        yield 'a copy called off on one path splits the cluster' => [
            [
                ['calendar' => 'account', 'uid' => 'off@plmail'],
                ['calendar' => 'mirror',  'uid' => 'off@plmail', 'status' => EventStatus::Cancelled],
            ],
            [1, 1],
        ];

        yield 'a copy whose occurrence row is cancelled splits the cluster' => [
            [
                ['calendar' => 'account', 'uid' => 'offrow@plmail'],
                ['calendar' => 'mirror',  'uid' => 'offrow@plmail', 'cancelled' => true],
            ],
            [1, 1],
        ];

        // No partial merge. Two of the three agree, and merging those two would
        // be picking a winner with extra steps — the point of splitting is that
        // the user sees there is a disagreement at all.
        yield 'one disagreeing copy splits the whole cluster rather than the majority winning' => [
            [
                ['calendar' => 'account',  'uid' => 'three@plmail'],
                ['calendar' => 'mirror',   'uid' => 'three@plmail'],
                ['calendar' => 'personal', 'uid' => 'three@plmail', 'title' => 'Sync — new agenda'],
            ],
            [1, 1, 1],
        ];

        // A UID is unique within a calendar, so two rows on ONE calendar at the
        // same instant are one series with an instance dragged onto a sibling's
        // time. Merging them would erase one occurrence from the view.
        yield 'two occurrences of one series on one calendar are never merged' => [
            [
                ['calendar' => 'account', 'uid' => 'standup@plmail'],
                ['calendar' => 'account', 'uid' => 'standup@plmail'],
            ],
            [1, 1],
        ];
    }

    /** The dot has to be able to draw a slice per calendar, and the tooltip to name them. */
    public function testAMergedClusterCarriesEveryMemberCalendarsColourAndName(): void
    {
        $clusters = $this->clusterer->cluster($this->occurrences([
            ['calendar' => 'account', 'uid' => 'colours@plmail'],
            ['calendar' => 'mirror',  'uid' => 'colours@plmail'],
        ]));

        self::assertCount(1, $clusters);
        self::assertTrue($clusters[0]->isMerged);
        self::assertSame(['#2563eb', '#16a34a'], $clusters[0]->colors);
        self::assertSame(['account', 'mirror'], $clusters[0]->calendarNames);
    }

    /**
     * Which member a list surface speaks for.
     *
     * $primary is whichever row the query returned first, and for the pair above
     * that is a coin flip between the mirrored copy — no kind, no message — and
     * the extracted one that carries both. A panel that read the primary would
     * gain and lose its icon and its "why is this on my calendar?" link with the
     * sort order, so the cluster answers the provenance question itself.
     */
    public function testAClusterNamesItsExtractedMemberWhicheverOrderTheRowsArrivedIn(): void
    {
        $mirrorFirst = $this->clusterer->cluster($this->occurrences([
            ['calendar' => 'mirror',  'uid' => 'both@organiser.test'],
            ['calendar' => 'account', 'uid' => 'both@organiser.test', 'kind' => ExtractionKind::Meeting],
        ]));

        self::assertCount(1, $mirrorFirst);
        self::assertSame(
            ExtractionKind::Meeting,
            $mirrorFirst[0]->extracted()?->event?->kind,
            'the extracted member is found even when it is not the primary',
        );
    }

    public function testAClusterNobodyExtractedNamesNoExtractedMember(): void
    {
        $clusters = $this->clusterer->cluster($this->occurrences([
            ['calendar' => 'account', 'uid' => 'typed@plmail'],
            ['calendar' => 'mirror',  'uid' => 'typed@plmail'],
        ]));

        self::assertNull($clusters[0]->extracted());
    }

    public function testALoneClusterIsNotMergedAndAnswersItsOwnColour(): void
    {
        $clusters = $this->clusterer->cluster($this->occurrences([
            ['calendar' => 'account', 'uid' => 'alone@plmail'],
        ]));

        self::assertFalse($clusters[0]->isMerged, 'a chip that is not a merge must not draw itself as one');
        self::assertSame(['#2563eb'], $clusters[0]->colors);
    }

    // ── The copies the editor offers ──────────────────────────────────────

    public function testTheEditorIsOfferedEveryCopyOfTheMeetingIncludingTheOneItWasOpenedOn(): void
    {
        [$account, $mirror] = $this->twoCopies('meeting@organiser.test');

        $copies = $this->clusterer->copiesOf($account, $this->user);

        self::assertSame(
            [$account->id, $mirror->id],
            array_map(static fn (CalendarEvent $copy): ?int => $copy->id, $copies),
        );
    }

    /**
     * The list the editor shows must be the set of chips that were merged into
     * the one clicked. A copy on a hidden calendar was never drawn, so offering
     * to write it is an edit to something not on screen.
     */
    public function testACopyOnAHiddenCalendarIsNotOffered(): void
    {
        [$account, $mirror] = $this->twoCopies('hidden@organiser.test');

        $mirror->calendar->isVisible = false;
        $this->em->flush();

        self::assertSame([$account->id], array_map(
            static fn (CalendarEvent $copy): ?int => $copy->id,
            $this->clusterer->copiesOf($account, $this->user),
        ));
    }

    /** A copy that no longer agrees is a different meeting, and the chip already says so. */
    public function testACopyThatNoLongerAgreesIsNotOffered(): void
    {
        [$account, $mirror] = $this->twoCopies('diverged@organiser.test');

        $mirror->title = 'Sync — new agenda';
        $this->em->flush();

        self::assertCount(1, $this->clusterer->copiesOf($account, $this->user));
    }

    /**
     * A new event has no identity to match on yet. Looking one up on the empty
     * string would answer with every other row that has not been given a UID,
     * and the editor would offer to write them.
     */
    public function testAnEventWithNoUidIsACopyOfNothingButItself(): void
    {
        $fresh = new CalendarEvent();

        self::assertSame([$fresh], $this->clusterer->copiesOf($fresh, $this->user));
    }

    public function testAReadOnlyCopyIsNeverAmongTheCopiesAnEditMayWrite(): void
    {
        [$account, $mirror] = $this->twoCopies('locked@organiser.test');

        $mirror->calendar->isReadOnly = true;
        $this->em->flush();

        $copies = $this->clusterer->copiesOf($account, $this->user);

        self::assertCount(2, $copies, 'it is still listed — the meeting really is on that calendar');

        self::assertSame(
            [$account->id],
            array_map(
                static fn (CalendarEvent $copy): ?int => $copy->id,
                // Exactly what a crafted request looks like: both ids posted,
                // including the one whose checkbox was rendered disabled.
                $this->clusterer->chosen($copies, [(string) $account->id, (string) $mirror->id]),
            ),
            'a mirror that does not accept writes back must be refused server-side too',
        );
    }

    public function testAnUntickedCopyIsLeftOutOfTheWrite(): void
    {
        [$account, $mirror] = $this->twoCopies('unticked@organiser.test');

        $copies = $this->clusterer->copiesOf($account, $this->user);

        self::assertSame(
            [$mirror->id],
            array_map(
                static fn (CalendarEvent $copy): ?int => $copy->id,
                $this->clusterer->chosen($copies, [(string) $mirror->id]),
            ),
        );
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /**
     * The same meeting written onto two calendars under one UID, the way the
     * two honest routes into plMail produce it.
     *
     * @return array{CalendarEvent, CalendarEvent}
     */
    private function twoCopies(string $uid): array
    {
        return [
            $this->copy($uid, $this->persistedCalendar('Account', '#2563eb')),
            $this->copy($uid, $this->persistedCalendar('Mirror', '#16a34a')),
        ];
    }

    private function copy(string $uid, Calendar $calendar): CalendarEvent
    {
        $event      = new CalendarEvent();
        $event->uid = $uid;

        // Relative to the run: RecurrenceMaterialiser only writes occurrences
        // inside a horizon around now, so a literal year is a suite that passes
        // until that year leaves the window.
        $start = new DateTimeImmutable('tomorrow 09:00', new DateTimeZone('UTC'));

        $this->writer->write(
            event:    $event,
            calendar: $calendar,
            user:     $this->user,
            title:    'Sync',
            startsAt: $start,
            endsAt:   $start->modify('+1 hour'),
            timeZone: 'UTC',
        );

        $this->em->flush();

        return $event;
    }

    private function persistedCalendar(string $name, string $color): Calendar
    {
        $calendar            = new Calendar();
        $calendar->usr       = $this->user;
        $calendar->name      = $name;
        $calendar->color     = $color;
        $calendar->role      = CalendarRole::Custom;
        $calendar->timeZone  = 'UTC';

        $this->em->persist($calendar);
        $this->em->flush();

        return $calendar;
    }

    /**
     * Rows as the range query hands them over: in memory, unpersisted, and
     * ordered by start — which is the only ordering cluster() may rely on.
     *
     * Calendars are shared by name within one call, exactly as Doctrine's
     * identity map shares them across a fetch-joined result, so a case naming
     * the same calendar twice really does hand over one Calendar.
     *
     * @param list<array<string,mixed>> $rows
     *
     * @return list<CalendarEventOccurrence>
     */
    private function occurrences(array $rows): array
    {
        $palette   = ['account' => '#2563eb', 'mirror' => '#16a34a', 'personal' => '#db2777'];
        $calendars = [];
        $built     = [];

        foreach ($rows as $row) {
            $name = (string) ($row['calendar'] ?? 'account');

            if (false === array_key_exists($name, $calendars)) {
                $calendar        = new Calendar();
                $calendar->usr   = $this->user;
                $calendar->name  = $name;
                $calendar->color = $palette[$name] ?? Calendar::DEFAULT_COLOR;

                $calendars[$name] = $calendar;
            }

            $event           = new CalendarEvent();
            $event->uid      = (string) ($row['uid'] ?? 'uid@plmail');
            $event->title    = (string) ($row['title'] ?? 'Sync');
            $event->isAllDay = true === ($row['isAllDay'] ?? false);
            $event->status   = $row['status'] ?? EventStatus::Confirmed;
            $event->kind     = $row['kind'] ?? null;
            $event->calendar = $calendars[$name];
            $event->usr      = $this->user;

            $occurrence            = new CalendarEventOccurrence();
            $occurrence->event     = $event;
            $occurrence->calendar  = $calendars[$name];
            $occurrence->usr       = $this->user;
            $occurrence->startsAt  = new DateTimeImmutable((string) ($row['start'] ?? '2026-05-04 09:00'), new DateTimeZone('UTC'));
            $occurrence->endsAt    = new DateTimeImmutable((string) ($row['end'] ?? '2026-05-04 10:00'), new DateTimeZone('UTC'));
            $occurrence->cancelled = true === ($row['cancelled'] ?? false);

            $built[] = $occurrence;
        }

        usort($built, static fn (CalendarEventOccurrence $one, CalendarEventOccurrence $other): int
            => $one->startsAt <=> $other->startsAt);

        return $built;
    }

    private function seed(): void
    {
        $user            = new User();
        $user->email     = 'cluster-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Cluster';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';

        $this->em->persist($user);
        $this->em->flush();

        $this->user = $user;
    }
}
