<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar;

use App\Domain\DTO\Calendar\HappeningSoonRow;
use App\Domain\Enum\Calendar\CalendarRole;
use App\Domain\Enum\Calendar\ExtractionKind;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\Calendar\CalendarEventOccurrence;
use App\Entity\Calendar\EventProposal;
use App\Entity\Calendar\EventSourceLink;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Service\Calendar\CalendarEventWriter;
use App\Service\Calendar\HappeningSoonReader;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * "Happening Soon" lists what is about to happen.
 *
 * Three claims are the subject, and each of them is a way the panel becomes a
 * liar rather than a way it breaks.
 *
 * It lists **the owner's events as well as the extracted ones**. It used to
 * carry `event.kind IS NOT NULL` and list extracted events only, which meant an
 * appointment somebody typed was missing from a panel that claims to say what is
 * coming up — the one case where the omission is least explicable, because the
 * user put it there. What must still hold is that a *proposal* stays out: a
 * guessed date is not a thing that is happening, and that distinction is the one
 * the kind filter was mistaken for.
 *
 * It lists **what is coming up**. Both edges matter and neither is visible: a
 * window that forgot its lower bound shows last month's flight as though it were
 * pending, and one whose far edge is a month instead of a fortnight fills the
 * glance with things nobody can act on yet.
 *
 * And every row **says which message it came from**. That link is the whole
 * reason extracted events are marked as extracted; a row that lost it, or that
 * named the superseded confirmation instead of the reschedule that replaced it,
 * answers "why is this on my calendar?" with the wrong message and is worse than
 * silence.
 *
 * Against a real container and a real database rather than doubles. Every
 * collaborator is final, so none can be doubled; and what is being pinned is the
 * result of a query with five conditions in it against rows a materialiser
 * wrote — a mock would assert that the reader calls itself.
 */
final class HappeningSoonReaderTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private CalendarEventWriter $writer;
    private HappeningSoonReader $reader;
    private User $user;
    private Account $account;
    private Calendar $calendar;
    private ?Calendar $mirrorCalendar = null;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->writer     = $container->get(CalendarEventWriter::class);
        $this->reader     = $container->get(HappeningSoonReader::class);

        // Relative to the real clock rather than a fixed date, because
        // RecurrenceMaterialiser writes occurrences only inside a horizon
        // around now: a literal 2026 date stops being materialised the year
        // after next, and the suite would fail for a reason nothing in it says.
        $this->now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        // Reset per test: the transaction below is rolled back, so a calendar
        // remembered from the previous case is a detached row that no longer
        // exists.
        $this->mirrorCalendar = null;

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
     * The regression this feature's first shape had: an appointment the user
     * typed was silently absent from the list that tells them what is coming up.
     * Seeded beside an extracted one, because what is being asserted is that the
     * two are listed together and not that either is listed at all.
     */
    public function testWhatTheOwnerTypedIsListedBesideWhatWasFoundInMail(): void
    {
        $this->extractedEvent('Flight to Berlin', '+2 days', ExtractionKind::Flight);
        $this->handMadeEvent('Dentist', '+3 days');

        self::assertSame(['Flight to Berlin', 'Dentist'], $this->titles());
    }

    /**
     * The kind is decoration now rather than the filter, and the row still has
     * to carry it: it is what draws the plane on a flight and the link back to
     * the mail. A hand-made event answers null, which is what the icon falls
     * back from.
     */
    public function testTheKindStillTellsTheTwoApart(): void
    {
        $this->extractedEvent('Flight to Berlin', '+2 days', ExtractionKind::Flight);
        $this->handMadeEvent('Dentist', '+3 days');

        $rows = $this->reader->read($this->user, $this->now);

        self::assertSame(ExtractionKind::Flight, $rows[0]->kind);
        self::assertNull($rows[1]->kind);
        self::assertSame('fa-solid fa-plane', $rows[0]->icon());
        self::assertSame('fa-regular fa-clock', $rows[1]->icon(), 'a kindless row still draws something');
    }

    /**
     * The far edge. Fourteen days, so thirteen is soon and fifteen is not —
     * asserted with literals rather than with the constant, so that moving the
     * window is a decision this test makes somebody take deliberately.
     */
    public function testTheFortnightIsWhereSoonStops(): void
    {
        $this->extractedEvent('Parcel', '+13 days', ExtractionKind::Delivery);
        $this->extractedEvent('Conference', '+15 days', ExtractionKind::Ticket);

        self::assertSame(['Parcel'], $this->titles(), 'a booking beyond the fortnight is not yet news');
    }

    /**
     * The near edge. A booking that has already begun is not "coming up", and
     * an unbounded lower edge is the mistake that would show last month's
     * flight as though it were still ahead.
     */
    public function testSomethingThatHasAlreadyStartedIsNotComingUp(): void
    {
        $this->extractedEvent('Yesterday flight', '-1 day', ExtractionKind::Flight);
        $this->extractedEvent('Tomorrow flight', '+1 day', ExtractionKind::Flight);

        self::assertSame(['Tomorrow flight'], $this->titles());
    }

    /** Soonest first: the list is read from the top and abandoned partway. */
    public function testTheSoonestThingIsFirst(): void
    {
        $this->extractedEvent('Later table', '+9 days', ExtractionKind::Dining);
        $this->extractedEvent('Sooner parcel', '+3 days', ExtractionKind::Delivery);
        $this->extractedEvent('Soonest call', '+2 hours', ExtractionKind::Call);

        self::assertSame(['Soonest call', 'Sooner parcel', 'Later table'], $this->titles());
    }

    /** The answer to "why is this on my calendar?", per row. */
    public function testEachRowNamesTheMessageItWasReadOutOf(): void
    {
        $event = $this->extractedEvent('Table for two', '+4 days', ExtractionKind::Dining);
        $this->claim($event, $this->message('Your reservation is confirmed', '-2 days'));

        $rows = $this->reader->read($this->user, $this->now);

        self::assertCount(1, $rows);
        self::assertSame(
            'Your reservation is confirmed',
            $rows[0]->source?->subject,
            'the row must carry the mail it was read out of',
        );
    }

    /**
     * A booking is described by several messages. The one that answers the
     * question is the one the event currently reflects — the reschedule, not
     * the confirmation it replaced — and "newest" has to mean newest by the
     * MESSAGE's date, because mail is not processed in the order it was sent.
     */
    public function testTheMessageNamedIsTheOneTheEventNowReflects(): void
    {
        $event = $this->extractedEvent('Flight to Oslo', '+6 days', ExtractionKind::Flight);

        // Persisted newest-first, so a reader that trusted insertion order
        // rather than the messages' dates would name the wrong one.
        $this->claim($event, $this->message('Your flight has moved', '-1 day'));
        $this->claim($event, $this->message('Booking confirmation', '-20 days'));

        $rows = $this->reader->read($this->user, $this->now);

        self::assertSame('Your flight has moved', $rows[0]->source?->subject);
    }

    /**
     * A superseded claim was read and lost. Naming it would answer the question
     * with the message the event deliberately does not reflect.
     */
    public function testASupersededClaimIsNotTheMessageNamed(): void
    {
        $event = $this->extractedEvent('Flight to Rome', '+5 days', ExtractionKind::Flight);

        $this->claim($event, $this->message('The version that was applied', '-9 days'));
        $this->claim($event, $this->message('The version that lost', '-1 day'), applied: false);

        $rows = $this->reader->read($this->user, $this->now);

        self::assertSame('The version that was applied', $rows[0]->source?->subject);
    }

    /**
     * A proposal is offered, not accepted, and stands on nothing — the reason
     * EventProposal is a table of its own is that it materialises no occurrence
     * and therefore cannot leak into a view. This is that promise, asserted from
     * the view's side: a guessed date inside the window is not something that is
     * happening.
     */
    public function testAProposalIsNotSomethingHappeningSoon(): void
    {
        $this->proposal('Lunch on the 12th', '+3 days');

        self::assertSame([], $this->titles(), 'an unaccepted guess is not a thing that is happening');
    }

    /**
     * A calendar switched off is switched off everywhere, or the setting means
     * nothing — the same rule the topbar dot and every calendar view follow.
     */
    public function testAnEventOnAHiddenCalendarIsNotListed(): void
    {
        $this->extractedEvent('Hidden parcel', '+2 days', ExtractionKind::Delivery);

        $this->calendar->isVisible = false;
        $this->em->flush();

        self::assertSame([], $this->titles());
    }

    /** A cancelled instance is not happening, whatever the series says. */
    public function testACancelledOccurrenceIsNotListed(): void
    {
        $event = $this->extractedEvent('Called-off dinner', '+2 days', ExtractionKind::Dining);

        // Read back rather than walked off $event->occurrences: that is the
        // inverse side of the association, and it is empty — not lazy, empty —
        // for rows the materialiser persisted in this same unit of work.
        foreach ($this->em->getRepository(CalendarEventOccurrence::class)->findBy(['event' => $event]) as $occurrence) {
            $occurrence->cancelled = true;
        }

        $this->em->flush();

        self::assertSame([], $this->titles());
    }

    /**
     * The empty case is a list, not a null. The panel renders "nothing coming
     * up" from an empty list; a null would have it render a broken page or
     * grow a second code path to avoid one.
     */
    public function testNothingComingUpIsAnEmptyListRatherThanNull(): void
    {
        self::assertSame([], $this->reader->read($this->user, $this->now));
    }

    /**
     * What the topbar asks before it renders anything: is there a reason to
     * offer the panel at all? The trigger is drawn from this answer, so a
     * wrong "yes" is a button that opens an empty dialog.
     */
    public function testTheTopbarIsOfferedNothingWhenNothingIsComingUp(): void
    {
        self::assertNull($this->reader->next($this->user, $this->now));
    }

    /** And the soonest one when there is, since the trigger wears its icon. */
    public function testTheTopbarIsOfferedTheSoonestThing(): void
    {
        $this->extractedEvent('Later parcel', '+8 days', ExtractionKind::Delivery);
        $this->extractedEvent('Soonest flight', '+1 day', ExtractionKind::Flight);

        $soonest = $this->reader->next($this->user, $this->now);

        self::assertInstanceOf(HappeningSoonRow::class, $soonest);
        self::assertSame('Soonest flight', $soonest->event->title);
        self::assertSame(ExtractionKind::Flight, $soonest->kind);
    }

    // ── One meeting, one row ──────────────────────────────────────────────

    /**
     * The bug the panel was reported with: a screenshot showing the same meeting
     * on two consecutive lines.
     *
     * A meeting reaches plMail twice by two honest routes at once — extracted
     * from its invitation onto the account's calendar, mirrored from the
     * provider onto a Remote one — and both rows are correct, which is why
     * nothing collapses them in the model. The grid has drawn them as one merged
     * chip since EventClusterer existed. This panel read occurrences and drew
     * two lines, which is the worse place for it: a grid at least shows the two
     * chips sharing one visible hour, while a list of twelve lines simply claims
     * there are two things.
     */
    public function testAMeetingThatReachedPlmailTwiceIsOneRow(): void
    {
        $this->meetingOnTwoCalendars('Weekly sync', '+2 days');

        self::assertSame(['Weekly sync'], $this->titles(), 'two rows of one meeting are one thing happening');
    }

    /**
     * And it says so. A list that silently merges is indistinguishable from a
     * list that lost something, so the row carries its cluster and the template
     * wears the same multicolour dot the merged chip does.
     */
    public function testTheCollapsedRowCarriesTheCalendarsItWasMergedFrom(): void
    {
        $this->meetingOnTwoCalendars('Weekly sync', '+2 days');

        $rows = $this->reader->read($this->user, $this->now);

        self::assertCount(1, $rows);
        self::assertTrue($rows[0]->cluster->isMerged);
        self::assertSame(['Soon fixture', 'Mirror'], $rows[0]->cluster->calendarNames);
    }

    /**
     * The provenance has to come off the EXTRACTED copy, not off whichever row
     * the query happened to return first.
     *
     * The mirrored copy carries no kind and no message; the extracted one
     * carries both. Reading the primary would make the panel's icon and its
     * "why is this on my calendar?" link appear and disappear with the sort
     * order, for a meeting that is the same either way.
     */
    public function testACollapsedRowKeepsTheIconAndTheMailOfItsExtractedCopy(): void
    {
        [$extracted] = $this->meetingOnTwoCalendars('Weekly sync', '+2 days');

        $this->claim($extracted, $this->message('Einladung zum Weekly sync', '-2 days'));

        $rows = $this->reader->read($this->user, $this->now);

        self::assertCount(1, $rows);
        self::assertSame(ExtractionKind::Meeting, $rows[0]->kind);
        self::assertSame('Einladung zum Weekly sync', $rows[0]->source?->subject);
    }

    /**
     * The cap counts meetings, not rows.
     *
     * findUpcoming() caps in SQL and the collapse happens afterwards, so a panel
     * that asked for its twelve and then merged would show as few as six on a
     * user with two calendars — the duplicates would eat the window. The reader
     * reads wide and slices after; this pins the outcome rather than the
     * arithmetic.
     */
    public function testTheCapCountsMeetingsRatherThanRows(): void
    {
        $this->meetingOnTwoCalendars('First', '+1 day');
        $this->meetingOnTwoCalendars('Second', '+2 days');
        $this->meetingOnTwoCalendars('Third', '+3 days');

        $rows = $this->reader->read($this->user, $this->now, 3);

        self::assertSame(
            ['First', 'Second', 'Third'],
            array_map(static fn (HappeningSoonRow $row): string => (string) $row->event->title, $rows),
        );
    }

    /**
     * Copies that no longer agree are two chips on the grid, and they have to be
     * two rows here for the same reason: the disagreement IS the news. An
     * update that reached one path and not the other, hidden behind a tidier
     * panel, is a meeting the user attends at the wrong hour.
     */
    public function testCopiesThatDisagreeAboutTheTimeStayTwoRows(): void
    {
        [, $mirror] = $this->meetingOnTwoCalendars('Weekly sync', '+2 days');

        $this->writer->write(
            event:    $mirror,
            calendar: $mirror->calendar,
            user:     $this->user,
            title:    'Weekly sync',
            startsAt: $this->now->modify('+2 days')->modify('+1 hour'),
            endsAt:   $this->now->modify('+2 days')->modify('+2 hours'),
            timeZone: 'UTC',
        );

        $this->em->flush();

        self::assertSame(['Weekly sync', 'Weekly sync'], $this->titles());
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /**
     * One meeting, two rows: the copy an extractor read out of the invitation
     * onto the account's calendar, and the copy a mirror pulled from the
     * provider onto a second one. One UID, because that is what a UID is —
     * `CalendarPuller` writes the remote's verbatim for exactly this reason.
     *
     * @return array{CalendarEvent, CalendarEvent} extracted copy first
     */
    private function meetingOnTwoCalendars(string $title, string $offset): array
    {
        $uid      = uniqid('meeting-', true) . '@organiser.test';
        $startsAt = $this->now->modify($offset);

        $extracted       = new CalendarEvent();
        $extracted->uid  = $uid;
        $this->writer->write(
            event:    $extracted,
            calendar: $this->calendar,
            user:     $this->user,
            title:    $title,
            startsAt: $startsAt,
            endsAt:   $startsAt->modify('+1 hour'),
            timeZone: 'UTC',
        );
        $extracted->kind = ExtractionKind::Meeting;

        $mirror      = new CalendarEvent();
        $mirror->uid = $uid;
        $this->writer->write(
            event:    $mirror,
            calendar: $this->mirror(),
            user:     $this->user,
            title:    $title,
            startsAt: $startsAt,
            endsAt:   $startsAt->modify('+1 hour'),
            timeZone: 'UTC',
        );

        $this->em->flush();

        return [$extracted, $mirror];
    }

    /** The second calendar, made once and reused, the way a real account has one. */
    private function mirror(): Calendar
    {
        if (null === $this->mirrorCalendar) {
            $calendar           = new Calendar();
            $calendar->usr      = $this->user;
            $calendar->name     = 'Mirror';
            $calendar->color    = '#16a34a';
            $calendar->role     = CalendarRole::Custom;
            $calendar->timeZone = 'UTC';

            $this->em->persist($calendar);
            $this->em->flush();

            $this->mirrorCalendar = $calendar;
        }

        return $this->mirrorCalendar;
    }

    /** @return list<string> */
    private function titles(): array
    {
        return array_map(
            static fn (HappeningSoonRow $row): string => (string) $row->event->title,
            $this->reader->read($this->user, $this->now),
        );
    }

    private function extractedEvent(string $title, string $offset, ExtractionKind $kind): CalendarEvent
    {
        $event = $this->event($title, $offset);

        // Set after write(): the writer projects the JSCalendar object and the
        // columns a query reads, and $kind is neither — extraction stamps it,
        // which is exactly what makes an event one of these.
        $event->kind = $kind;

        $this->em->flush();

        return $event;
    }

    private function handMadeEvent(string $title, string $offset): CalendarEvent
    {
        $event = $this->event($title, $offset);

        $this->em->flush();

        return $event;
    }

    private function event(string $title, string $offset): CalendarEvent
    {
        $startsAt = $this->now->modify($offset);

        $event      = new CalendarEvent();
        $event->uid = uniqid('soon-', true) . '@plmail.test';

        return $this->writer->write(
            event:    $event,
            calendar: $this->calendar,
            user:     $this->user,
            title:    $title,
            startsAt: $startsAt,
            endsAt:   $startsAt->modify('+1 hour'),
            timeZone: 'UTC',
        );
    }

    private function claim(CalendarEvent $event, Message $message, bool $applied = true): void
    {
        $link            = new EventSourceLink();
        $link->event     = $event;
        $link->message   = $message;
        $link->extractor = 'jsonld';
        $link->dedupKey  = uniqid('jsonld:', true);
        $link->applied   = $applied;
        $link->payload   = ['@type' => 'Reservation'];

        $this->em->persist($link);
        $this->em->flush();
    }

    private function message(string $subject, string $offset): Message
    {
        $message                 = new Message();
        $message->account        = $this->account;
        $message->messageId      = uniqid('soon-', true) . '@example.test';
        $message->subject        = $subject;
        $message->fromAddress    = 'bookings@example.test';
        $message->hasAttachments = false;
        $message->receivedAt     = $this->now->modify($offset);

        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    private function proposal(string $title, string $offset): EventProposal
    {
        $startsAt = $this->now->modify($offset);

        $proposal                 = new EventProposal();
        $proposal->usr            = $this->user;
        $proposal->message        = $this->message('Shall we say the 12th?', '-1 day');
        $proposal->title          = $title;
        $proposal->startsAt       = $startsAt;
        $proposal->endsAt         = $startsAt->modify('+1 hour');
        $proposal->timeZone       = 'UTC';
        $proposal->confidence     = 60;
        $proposal->sourceSentence = 'Shall we say the 12th at noon?';
        $proposal->dedupKeyHash   = str_repeat('a', 64);
        $proposal->detector       = 'deterministic';

        $this->em->persist($proposal);
        $this->em->flush();

        return $proposal;
    }

    private function seed(): void
    {
        $user            = new User();
        $user->email     = 'soon-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Soon';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $account                 = new Account();
        $account->usr            = $user;
        $account->email          = 'soon-fixture@example.test';
        $account->username       = 'soon-fixture@example.test';
        $account->imapHost       = 'localhost';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        $account->smtpHost       = 'localhost';
        $account->smtpPort       = 587;
        $account->smtpEncryption = 'starttls';
        $account->password       = 'x';
        $account->authType       = 'password';
        $account->isActive       = true;
        $this->em->persist($account);

        $calendar           = new Calendar();
        $calendar->usr      = $user;
        $calendar->account  = $account;
        $calendar->name     = 'Soon fixture';
        $calendar->role     = CalendarRole::Account;
        $calendar->timeZone = 'UTC';
        $this->em->persist($calendar);

        $this->em->flush();

        $this->user     = $user;
        $this->account  = $account;
        $this->calendar = $calendar;
    }
}
