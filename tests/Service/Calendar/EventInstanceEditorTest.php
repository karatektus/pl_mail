<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar;

use App\Domain\Enum\Calendar\CalendarRole;
use App\Domain\Enum\Calendar\SyncState;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\Calendar\CalendarEventOccurrence;
use App\Entity\User\User;
use App\Service\Calendar\CalendarEventWriter;
use App\Service\Calendar\EventInstanceEditor;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * "This event" has to mean one occurrence, and go on meaning it.
 *
 * A recurring event is one row and a rule, so editing a single instance is not
 * an edit of anything — it is a patch filed against the series under the
 * LocalDateTime the rule originally put that instance at. Everything that can go
 * wrong here goes wrong silently, which is why each of these exists:
 *
 *   A key written in the wrong zone, or taken from where the instance was moved
 *   to rather than where the rule put it, is filed where RecurrenceMaterialiser
 *   never looks — the occurrence stays exactly as it was and the user's edit
 *   vanishes with no error anywhere.
 *
 *   A patch that is a whole event object rather than a partial states a
 *   location, a description and an all-day flag for that one instance which
 *   nobody chose and which no later reader can tell from a real decision.
 *
 *   A second edit of the same instance that stacks instead of replacing leaves
 *   two patches, one of which is a lie about where the occurrence is.
 *
 *   And a change that never marks the event queues no push, so a synced calendar
 *   silently keeps the old times and the next full read can drop the local one.
 *
 * Against a real container and a real database rather than doubles. Every
 * collaborator is final, so none can be doubled; and the claim worth pinning is
 * the one that emerges from all of them together — a patch written, occurrences
 * re-materialised from it, and rows in the table a calendar view actually reads.
 *
 * Dates are relative to the run rather than fixed. RecurrenceMaterialiser only
 * writes occurrences inside a horizon around now, so a series pinned to a
 * literal year is a suite that passes until that year leaves the window and then
 * fails for a reason that has nothing to do with the code.
 */
final class EventInstanceEditorTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private EventInstanceEditor $editor;
    private CalendarEventWriter $writer;
    private User $user;
    private Calendar $calendar;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->editor     = $container->get(EventInstanceEditor::class);
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

    public function testMovingOneInstanceLeavesItsSiblingsWhereTheyWere(): void
    {
        $event = $this->weeklySeries();

        $this->move($event, 1, 14, 0);

        self::assertSame('14:00', $this->localStart($event, 1), 'the instance that was edited should have moved');

        foreach ([0, 2, 3] as $untouched) {
            self::assertSame(
                '09:00',
                $this->localStart($event, $untouched),
                sprintf('occurrence %d is a different one and was not edited', $untouched),
            );
        }
    }

    /**
     * "All events", chosen in an editor opened on the fourth occurrence, having
     * changed nothing but the title.
     *
     * The editor is prefilled with the INSTANCE's times, so the fields it posts
     * are that occurrence's — and reading them as the series' new times rebases
     * the whole series onto the clicked day. Renaming a weekly meeting from a
     * later occurrence moved it there, silently, which is the worst shape a
     * calendar bug takes: nothing failed and the meeting is on a different day.
     */
    public function testRenamingTheSeriesFromALaterOccurrenceDoesNotMoveIt(): void
    {
        $event    = $this->weeklySeries();
        $instance = $this->instanceAt($event, 3);
        $before   = $event->startsAt;

        [$startsAt, $endsAt] = $this->editor->seriesTimesFor(
            $event,
            $instance,
            // Exactly where the editor would have put them: the instance's own
            // times, untouched.
            $instance->startsAt,
            $instance->endsAt,
        );

        self::assertSame(
            $before->format(DATE_ATOM),
            $startsAt->format(DATE_ATOM),
            'the series keeps the day it already had',
        );
        self::assertSame(
            $before->diff($event->endsAt)->s,
            $startsAt->diff($endsAt)->s,
            'and the length it already had',
        );
    }

    /** Moving the instance an hour on, for all events, moves the series an hour on. */
    public function testMovingTheSeriesFromOneOccurrenceShiftsItByWhatChanged(): void
    {
        $event    = $this->weeklySeries();
        $instance = $this->instanceAt($event, 3);

        [$startsAt] = $this->editor->seriesTimesFor(
            $event,
            $instance,
            $instance->startsAt->modify('+1 hour'),
            $instance->endsAt->modify('+1 hour'),
        );

        self::assertSame(
            $event->startsAt->modify('+1 hour')->format(DATE_ATOM),
            $startsAt->format(DATE_ATOM),
        );
    }

    /**
     * The key is the instance's ORIGINAL start, as a LocalDateTime in the
     * SERIES' zone — not an instant, not the instance's own zone, and not where
     * it was moved to. Berlin is the fixture zone precisely because it is an
     * hour or two off UTC: a key written from the UTC instant would read 07:00
     * or 08:00 and never be looked up again.
     */
    public function testTheKeyIsTheOriginalStartInTheSeriesZone(): void
    {
        $event = $this->weeklySeries('Europe/Berlin');

        $this->move($event, 1, 13, 0, 'Europe/Berlin');

        self::assertSame(
            [$this->localKey(1, 'Europe/Berlin')],
            array_keys($event->jscalendar['recurrenceOverrides']),
            'the override is filed under the local start the rule gave the instance',
        );

        self::assertSame(
            '13:00',
            $this->localStart($event, 1, 'Europe/Berlin'),
            'a key the expander cannot find is an edit that silently does nothing',
        );
    }

    /**
     * A patch is a partial. The editor posts a whole event — location,
     * description, calendar, repeat rule — and none of that belongs in the map:
     * RecurrenceMaterialiser honours start, duration and a cancelled status and
     * would ignore the rest, so every other key written here would be a
     * statement about the instance that nobody made and nothing reads.
     */
    public function testAPatchCarriesOnlyWhatAnOccurrenceCanDraw(): void
    {
        $event = $this->weeklySeries();

        $start = $this->originalStart(1)->setTime(14, 0);

        $this->editor->edit($event, $this->instanceAt($event, 1), 'Standup', $start, $start->modify('+45 minutes'));
        $this->em->flush();

        $patch = $event->jscalendar['recurrenceOverrides'][$this->localKey(1)];

        self::assertSame(['@type', 'start', 'duration'], array_keys($patch));
        self::assertSame('PT45M', $patch['duration'], 'an instance that moved is routinely also a different length');
    }

    /**
     * A title is written only when it is not the series'. Repeating it would
     * claim the instance had been renamed, and a later rename of the series
     * would then leave this one occurrence still carrying the old name with
     * nothing on screen to explain why.
     */
    public function testATitleIsOnlyStatedWhenThisInstanceHasItsOwn(): void
    {
        $event = $this->weeklySeries();

        $this->rename($event, 1, 'Retro');

        self::assertSame('Retro', $event->jscalendar['recurrenceOverrides'][$this->localKey(1)]['title']);
        self::assertSame('Standup', $event->title, 'the series keeps its own name');

        $this->rename($event, 2, 'Standup');

        self::assertArrayNotHasKey(
            'title',
            $event->jscalendar['recurrenceOverrides'][$this->localKey(2)],
            'a patch repeating the series title is a rename that never happened',
        );
    }

    /**
     * The case the recurrence id exists for. An instance moved once is edited
     * again from the chip it now draws — at 14:00 — and the second patch has to
     * land on the key the first one used, which is where the RULE put it. Keyed
     * off the occurrence's current start instead, the second edit would file a
     * patch under 14:00, nothing would ever look it up, and the instance would
     * sit at the time the first edit gave it forever.
     */
    public function testEditingAMovedInstanceAgainUpdatesItsPatchRatherThanStackingASecond(): void
    {
        $event = $this->weeklySeries();

        $this->move($event, 1, 14, 0);
        $this->move($event, 1, 16, 30);

        self::assertCount(
            1,
            $event->jscalendar['recurrenceOverrides'],
            'one instance, one patch, however many times it is edited',
        );

        self::assertSame('16:30', $this->localStart($event, 1), 'the second edit is where the instance ends up');
    }

    /** Other instances' patches are other decisions, and this edit says nothing about them. */
    public function testEditingOneInstanceKeepsThePatchesOnTheOthers(): void
    {
        $event = $this->weeklySeries();

        $this->move($event, 1, 14, 0);
        $this->move($event, 2, 11, 0);

        self::assertCount(2, $event->jscalendar['recurrenceOverrides']);
        self::assertSame('14:00', $this->localStart($event, 1));
        self::assertSame('11:00', $this->localStart($event, 2));
    }

    public function testCancellingOneInstanceLeavesTheRestOfTheSeries(): void
    {
        $event  = $this->weeklySeries();
        $before = count($event->occurrences);
        $going  = $this->localKey(1);

        $this->editor->cancel($event, $this->instanceAt($event, 1));
        $this->em->flush();

        self::assertCount($before - 1, $event->occurrences, 'exactly one instance goes');

        foreach ($event->occurrences as $occurrence) {
            self::assertNotSame(
                $going,
                $occurrence->recurrenceId?->format('Y-m-d\TH:i:s'),
                'the cancelled instance is off the calendar',
            );
        }
    }

    /**
     * `{"excluded": true}` is the one override value that has to be exactly
     * right — anything else in that slot is an instance that keeps being drawn
     * after it was called off. Spelled by RecurrenceRuleConverter, and asserted
     * here so a second spelling cannot creep in beside it.
     */
    public function testACancellationIsSpelledTheWayTheExpanderReadsIt(): void
    {
        $event = $this->weeklySeries();

        $this->editor->cancel($event, $this->instanceAt($event, 1));
        $this->em->flush();

        self::assertSame(
            [$this->localKey(1) => ['excluded' => true]],
            $event->jscalendar['recurrenceOverrides'],
        );
    }

    /**
     * Per-instance decisions are the user's, and rewriting the series is not a
     * reason to lose them. CalendarEventWriter::write() carries
     * recurrenceOverrides across; this pins that it still holds now that there
     * is finally a path that produces them.
     */
    public function testAnOverrideSurvivesALaterSeriesEdit(): void
    {
        $event = $this->weeklySeries();

        $this->move($event, 1, 14, 0);
        $this->renameSeries($event, 'Standup (all hands)');

        self::assertArrayHasKey('recurrenceOverrides', $event->jscalendar);
        self::assertSame('14:00', $this->localStart($event, 1), 'the moved instance is still where the user put it');
        self::assertSame('09:00', $this->localStart($event, 2), 'and the rest of the series is still the rule');
    }

    public function testASyncedCalendarIsMarkedForPushWhenOneInstanceMoves(): void
    {
        $event = $this->weeklySeries(calendar: $this->remoteCalendar());

        self::assertSame(SyncState::Clean, $event->syncState);

        $this->move($event, 1, 14, 0);

        self::assertSame(
            SyncState::PendingUpdate,
            $event->syncState,
            'the master carries the override, so the master is what owes the remote a write',
        );
    }

    public function testCancellingOneInstanceOfASyncedSeriesIsAlsoAPush(): void
    {
        $event = $this->weeklySeries(calendar: $this->remoteCalendar());

        $this->editor->cancel($event, $this->instanceAt($event, 1));
        $this->em->flush();

        self::assertSame(
            SyncState::PendingUpdate,
            $event->syncState,
            'an instance taken off a series is a change to the series, not a delete',
        );
    }

    /**
     * Not a delete. The row and its copy at the remote both stay; only one
     * instance stops being drawn. PendingDelete here would have CalendarPusher
     * remove the whole series at the server.
     */
    public function testCancellingOneInstanceDoesNotQueueTheSeriesForDeletion(): void
    {
        $event = $this->weeklySeries(calendar: $this->remoteCalendar());

        $this->editor->cancel($event, $this->instanceAt($event, 1));
        $this->em->flush();

        self::assertNotSame(SyncState::PendingDelete, $event->syncState);
    }

    /** A calendar that mirrors nothing has nobody to tell, and must not be queued. */
    public function testALocalCalendarIsNotMarkedForPush(): void
    {
        $event = $this->weeklySeries();

        $this->move($event, 1, 14, 0);

        self::assertSame(SyncState::Clean, $event->syncState);
    }

    /**
     * Reopening a renamed instance has to show the name it was given. Read off
     * the patch, because an occurrence row has no title column — without it the
     * editor shows the series' name and saving writes that back, undoing the
     * rename with no sign that anything happened.
     */
    public function testTheEditorReopensARenamedInstanceUnderItsOwnName(): void
    {
        $event = $this->weeklySeries();

        $this->rename($event, 1, 'Retro');

        self::assertSame('Retro', $this->editor->titleOf($event, $this->instanceAt($event, 1)));
        self::assertSame('Standup', $this->editor->titleOf($event, $this->instanceAt($event, 2)));
    }

    // ── The instance a request names ──────────────────────────────────────

    public function testAnInstanceIsFoundByTheIdentityTheChipCarries(): void
    {
        $event    = $this->weeklySeries();
        $expected = $this->instanceAt($event, 1);

        $found = $this->editor->instance($event, $this->editor->identify($expected));

        self::assertNotNull($found);
        self::assertSame($expected->id, $found->id);
    }

    /**
     * The identity is the ORIGINAL start, so a moved instance is still named by
     * it. Were the chip to carry where the instance went, the editor would open
     * on nothing the second time that chip was clicked.
     */
    public function testAMovedInstanceIsStillNamedByWhereTheRulePutIt(): void
    {
        $event = $this->weeklySeries();

        $this->move($event, 1, 14, 0);

        $moved = $this->instanceAt($event, 1);

        self::assertSame(
            $this->originalStart(1)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
            $this->editor->identify($moved),
        );

        self::assertNotNull($this->editor->instance($event, $this->editor->identify($moved)));
    }

    /**
     * A one-off has one instance, so there is nothing to choose between and the
     * editor must not offer the choice. Answering an occurrence here would put a
     * patch on an event that has no rule to patch.
     */
    public function testAOneOffEventNamesNoInstance(): void
    {
        $event = $this->oneOff();

        $identity = $this->editor->identify($event->occurrences->first() ?: null);

        self::assertNull($this->editor->instance($event, $identity));
    }

    /**
     * Everything in that field arrives from a request. A stale form, a
     * hand-edited URL or a date this series has no occurrence at answers null,
     * and every caller then means the series — which is what this application
     * did before "this event" existed.
     */
    public function testAnUnusableIdentityMeansNoInstanceRatherThanAGuess(): void
    {
        $event = $this->weeklySeries();

        self::assertNull($this->editor->instance($event, ''));
        self::assertNull($this->editor->instance($event, 'the third one'));
        self::assertNull(
            $this->editor->instance($event, $this->originalStart(1)->modify('+1 day')->format('Y-m-d\TH:i:s\Z')),
            'a day this series does not fall on is not one of its instances',
        );
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /**
     * A weekly 09:00 series of eight, starting the Monday after the run.
     *
     * Next week rather than a literal date so it is always inside
     * RecurrenceMaterialiser's horizon, and Monday so the eight instances are
     * eight distinct days whichever day the suite happens to run on.
     */
    private function weeklySeries(string $timeZone = 'UTC', ?Calendar $calendar = null): CalendarEvent
    {
        $start = $this->originalStart(0, $timeZone);

        $event = $this->writer->write(
            event:          new CalendarEvent(),
            calendar:       $calendar ?? $this->calendar,
            user:           $this->user,
            title:          'Standup',
            startsAt:       $start,
            endsAt:         $start->modify('+30 minutes'),
            timeZone:       $timeZone,
            recurrenceRule: ['@type' => 'RecurrenceRule', 'frequency' => 'weekly', 'count' => 8],
        );

        $this->em->flush();

        return $event;
    }

    private function oneOff(): CalendarEvent
    {
        $start = $this->originalStart(0);

        $event = $this->writer->write(
            event:    new CalendarEvent(),
            calendar: $this->calendar,
            user:     $this->user,
            title:    'Dentist',
            startsAt: $start,
            endsAt:   $start->modify('+1 hour'),
            timeZone: 'UTC',
        );

        $this->em->flush();

        return $event;
    }

    /** Where the rule puts the nth instance, before anything moves it. */
    private function originalStart(int $index, string $timeZone = 'UTC'): DateTimeImmutable
    {
        return new DateTimeImmutable('monday next week 09:00', new DateTimeZone($timeZone))
            ->modify(sprintf('+%d weeks', $index));
    }

    /** The recurrenceOverrides key the nth instance is filed under. */
    private function localKey(int $index, string $timeZone = 'UTC'): string
    {
        return $this->originalStart($index, $timeZone)->format('Y-m-d\TH:i:s');
    }

    private function move(CalendarEvent $event, int $index, int $hour, int $minute, string $timeZone = 'UTC'): void
    {
        $start = $this->originalStart($index, $timeZone)->setTime($hour, $minute);

        $this->editor->edit($event, $this->instanceAt($event, $index), 'Standup', $start, $start->modify('+1 hour'));

        $this->em->flush();
    }

    private function rename(CalendarEvent $event, int $index, string $title): void
    {
        $start = $this->originalStart($index);

        $this->editor->edit($event, $this->instanceAt($event, $index), $title, $start, $start->modify('+30 minutes'));

        $this->em->flush();
    }

    private function renameSeries(CalendarEvent $event, string $title): void
    {
        $start = $this->originalStart(0);

        $this->writer->write(
            event:          $event,
            calendar:       $this->calendar,
            user:           $this->user,
            title:          $title,
            startsAt:       $start,
            endsAt:         $start->modify('+30 minutes'),
            timeZone:       'UTC',
            recurrenceRule: ['@type' => 'RecurrenceRule', 'frequency' => 'weekly', 'count' => 8],
        );

        $this->em->flush();
    }

    /**
     * The nth instance, named by its recurrence id — where the rule put it, not
     * where it is now. Ordering on the current start would renumber the series
     * the moment one instance was moved past another.
     */
    private function instanceAt(CalendarEvent $event, int $index): CalendarEventOccurrence
    {
        // In the SERIES' zone: 09:00 Berlin is not 09:00 UTC, and a weekly
        // series is 09:00 on its own clock however far that is from Greenwich.
        $wanted = $this->originalStart($index, $event->timeZone ?? 'UTC')
            ->setTimezone(new DateTimeZone('UTC'))
            ->format(DATE_ATOM);

        foreach ($event->occurrences as $occurrence) {
            if ($occurrence->recurrenceId?->format(DATE_ATOM) === $wanted) {
                return $occurrence;
            }
        }

        self::fail(sprintf('the series should have an instance %d weeks in', $index));
    }

    /** Where one instance actually is, on the clock the series is read by. */
    private function localStart(CalendarEvent $event, int $index, string $timeZone = 'UTC'): string
    {
        return (string) $this->instanceAt($event, $index)
            ->startsAt?->setTimezone(new DateTimeZone($timeZone))->format('H:i');
    }

    private function remoteCalendar(): Calendar
    {
        $calendar           = new Calendar();
        $calendar->usr      = $this->user;
        $calendar->name     = 'Mirrored';
        $calendar->role     = CalendarRole::Remote;
        $calendar->timeZone = 'UTC';
        $calendar->remoteId = 'remote-' . uniqid('', true);
        $this->em->persist($calendar);
        $this->em->flush();

        return $calendar;
    }

    private function seed(): void
    {
        $user            = new User();
        $user->email     = 'instance-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Instance';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $calendar           = new Calendar();
        $calendar->usr      = $user;
        $calendar->name     = 'Instance fixture';
        $calendar->role     = CalendarRole::Custom;
        $calendar->timeZone = 'UTC';
        $this->em->persist($calendar);

        $this->em->flush();

        $this->user     = $user;
        $this->calendar = $calendar;
    }
}
