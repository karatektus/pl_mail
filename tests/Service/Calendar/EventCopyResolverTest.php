<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar;

use App\Domain\DTO\Calendar\EventCopy;
use App\Domain\Enum\Calendar\CalendarRole;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\User\User;
use App\Service\Calendar\CalendarEventWriter;
use App\Service\Calendar\EventCopyResolver;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The editor's calendar list: every calendar the user owns, ticked where the
 * meeting already is, and one row per calendar whether or not it exists yet.
 *
 * The claim worth pinning is the UID. A copy made on a calendar the meeting was
 * not on carries the meeting's UID rather than one of its own, because
 * EventClusterer identifies a meeting by UID plus start instant: a copy with a
 * fresh UID is a second meeting at the same hour of the same day, drawn as its
 * own chip for ever, and no later edit can merge the two. The same rule in the
 * other direction is what makes one new event ticked onto three calendars three
 * rows of one meeting rather than three meetings — the UID is minted once here,
 * not once per row by CalendarEventWriter::write().
 *
 * Two more failures are guarded, and both are silent:
 *
 *   A calendar that already holds a row under this UID must never be handed a
 *   second one. uniq_calendar_event_calendar_uid refuses it with a 500, and the
 *   rows that reach that state are exactly the ones the cluster leaves out — a
 *   copy on a hidden calendar, or one that has drifted out of agreement.
 *
 *   A read-only calendar must be refused server-side. It is rendered disabled,
 *   which is a statement to a browser and not a guarantee to a server.
 *
 * Against a real container and a real database: every collaborator is final so
 * none can be doubled, and the behaviour worth pinning — which rows a UID
 * already occupies — is a question only a database answers.
 */
final class EventCopyResolverTest extends KernelTestCase
{
    /** The organiser's UID, the identity every copy of this meeting shares. */
    private const string UID = 'copy-resolver@organiser.test';

    private EntityManagerInterface $em;
    private Connection $connection;
    private EventCopyResolver $resolver;
    private CalendarEventWriter $writer;
    private User $user;
    private Calendar $account;
    private Calendar $mirror;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->resolver   = $container->get(EventCopyResolver::class);
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

    public function testEveryCalendarIsOfferedAndOnlyTheOnesTheMeetingIsOnAreTicked(): void
    {
        $event = $this->copy($this->account);

        $options = $this->resolver->optionsFor($event, $this->user);

        self::assertSame(
            [$this->account->id, $this->mirror->id],
            array_map(static fn (EventCopy $copy): ?int => $copy->calendar->id, $options),
            'the list is every calendar the user owns, in sidebar order',
        );

        self::assertSame(
            [true, false],
            array_map(static fn (EventCopy $copy): bool => $copy->isChosen, $options),
        );

        self::assertSame(
            [false, true],
            array_map(static fn (EventCopy $copy): bool => $copy->isNew(), $options),
            'the calendar it is not on carries the row a save would make, not nothing',
        );
    }

    /**
     * The decision the whole feature turns on. A copy that minted its own UID
     * would be a second meeting to EventClusterer — same title, same hour, two
     * chips, and nothing able to merge them again.
     */
    public function testACopyOnANewCalendarCarriesTheMeetingsUidRatherThanOneOfItsOwn(): void
    {
        $event = $this->copy($this->account);

        $options = $this->resolver->optionsFor($event, $this->user);

        self::assertSame(self::UID, $options[1]->event->uid);
        self::assertSame(
            $this->mirror,
            $options[1]->event->calendar,
            'and it is already on the calendar whose box would create it',
        );
    }

    /**
     * A new event on three calendars is three rows of one meeting, so the UID is
     * minted once here rather than once per row by write(), which mints for any
     * row that has none.
     */
    public function testANewEventGivesEveryCalendarTheSameUid(): void
    {
        $options = $this->resolver->optionsFor(null, $this->user);

        $uids = array_unique(array_map(static fn (EventCopy $copy): string => $copy->event->uid, $options));

        self::assertCount(1, $uids, 'one meeting, one UID, however many calendars it is written to');
        self::assertNotSame([''], array_values($uids), 'and it is a real UID rather than the empty one write() replaces');
    }

    /** Nothing exists to be a copy of, so the tick says where it would land. */
    public function testANewEventTicksTheDefaultCalendarAndNothingElse(): void
    {
        $options = $this->resolver->optionsFor(null, $this->user);

        self::assertSame(
            [$this->account->id],
            array_map(
                static fn (EventCopy $copy): ?int => $copy->calendar->id,
                array_values(array_filter($options, static fn (EventCopy $copy): bool => $copy->isChosen)),
            ),
        );
    }

    /**
     * The fallback exists because the tick is now the only thing that says where
     * a new event goes: a user whose default flag never got set would otherwise
     * open the editor with nothing ticked and be told "nothing was chosen" for
     * pressing Save on a form they never touched.
     */
    public function testAUserWithNoDefaultCalendarStillGetsOneTicked(): void
    {
        $this->account->isDefault = false;
        $this->em->flush();

        $options = $this->resolver->optionsFor(null, $this->user);

        self::assertSame(
            [true, false],
            array_map(static fn (EventCopy $copy): bool => $copy->isChosen, $options),
            'the first calendar that accepts writes stands in for the default',
        );
    }

    /**
     * The list stays a true statement of where the meeting can be, and the
     * refusal lives on the server: a disabled checkbox is a statement to a
     * browser, never a guarantee.
     */
    public function testAReadOnlyCalendarIsOfferedUntickedAndRefusedWhenTheRequestNamesIt(): void
    {
        $this->mirror->isReadOnly = true;
        $this->em->flush();

        $event   = $this->copy($this->account);
        $options = $this->resolver->optionsFor($event, $this->user);

        self::assertFalse($options[1]->isChosen);

        self::assertSame(
            [$this->account->id],
            array_map(
                static fn (EventCopy $copy): ?int => $copy->calendar->id,
                // Exactly what a crafted request looks like: both ids posted,
                // including the one whose checkbox was rendered disabled.
                $this->resolver->chosen($options, [(string) $this->account->id, (string) $this->mirror->id]),
            ),
        );
    }

    /**
     * A copy the cluster leaves out is still THE row for its calendar.
     *
     * copiesOf() drops a copy on a hidden calendar — it was never drawn, so
     * writing it would be an edit to something not on screen — and that rule is
     * kept by leaving the box unticked. Leaving the calendar out of the list
     * instead is what would turn ticking it into an insert that
     * uniq_calendar_event_calendar_uid refuses with a 500.
     */
    public function testACopyOnAHiddenCalendarIsOfferedUntickedRatherThanBeingSecondOne(): void
    {
        $event  = $this->copy($this->account);
        $hidden = $this->copy($this->mirror);

        $this->mirror->isVisible = false;
        $this->em->flush();

        $options = $this->resolver->optionsFor($event, $this->user);

        self::assertFalse($options[1]->isChosen, 'a copy that was never drawn is not written by default');
        self::assertFalse($options[1]->isNew(), 'but ticking it writes the row that is there');
        self::assertSame($hidden->id, $options[1]->event->id);
    }

    /** The same guard, for the other reason a copy leaves the cluster. */
    public function testACopyThatNoLongerAgreesIsOfferedUntickedRatherThanBeingASecondOne(): void
    {
        $event    = $this->copy($this->account);
        $diverged = $this->copy($this->mirror);

        $diverged->title = 'Sync — new agenda';
        $this->em->flush();

        $options = $this->resolver->optionsFor($event, $this->user);

        self::assertFalse($options[1]->isChosen);
        self::assertSame($diverged->id, $options[1]->event->id, 'ticking it makes that row agree again, not a third row');
    }

    /**
     * A ticked calendar with no copy on it has nothing to delete. Creating the
     * row in order to remove it is absurd, and refusing the whole delete because
     * a default tick named an empty calendar would be worse.
     */
    public function testTheRowsADeleteCanActOnAreOnlyTheOnesThatExist(): void
    {
        $event   = $this->copy($this->account);
        $options = $this->resolver->optionsFor($event, $this->user);

        $chosen = $this->resolver->chosen($options, [(string) $this->account->id, (string) $this->mirror->id]);

        self::assertCount(2, $chosen);
        self::assertSame(
            [$event->id],
            array_map(static fn (CalendarEvent $row): ?int => $row->id, $this->resolver->existing($chosen)),
        );
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function copy(Calendar $calendar): CalendarEvent
    {
        $event      = new CalendarEvent();
        $event->uid = self::UID;

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

    private function seed(): void
    {
        $user            = new User();
        $user->email     = 'copies-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Copies';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';

        $this->em->persist($user);

        $this->user    = $user;
        $this->account = $this->calendar('Account', '#2563eb', isDefault: true);
        $this->mirror  = $this->calendar('Mirror', '#16a34a');

        $this->em->flush();
    }

    private function calendar(string $name, string $color, bool $isDefault = false): Calendar
    {
        $calendar            = new Calendar();
        $calendar->usr       = $this->user;
        $calendar->name      = $name;
        $calendar->color     = $color;
        $calendar->role      = CalendarRole::Custom;
        $calendar->timeZone  = 'UTC';
        $calendar->isDefault = $isDefault;

        $this->em->persist($calendar);

        return $calendar;
    }
}
