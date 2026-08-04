<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar;

use App\Domain\Enum\Calendar\CalendarRole;
use App\Domain\Enum\Calendar\ExtractionKind;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\User\User;
use App\Service\Calendar\CalendarEventWriter;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Editing an event rebuilds its JSCalendar object, and must not lose what the
 * form has no field for.
 *
 * The writer derives the canonical object from the columns, which is what keeps
 * the two from disagreeing — and means anything the editor cannot show is
 * dropped unless it is carried across deliberately. Participants are the case
 * that bites: they hold the RSVP, so correcting the title of a meeting somebody
 * had already accepted silently un-answered it here while the organiser went on
 * believing the answer they were sent.
 */
final class CalendarEventWriterTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private CalendarEventWriter $writer;
    private User $user;
    private Calendar $calendar;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->writer     = $container->get(CalendarEventWriter::class);

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

    public function testAnEditKeepsTheParticipantsAndTheirAnswers(): void
    {
        $event = $this->invitedEvent();

        $this->rename($event, 'Quarterly review (moved)');

        self::assertArrayHasKey('participants', $event->jscalendar);
        self::assertSame(
            'accepted',
            $event->jscalendar['participants']['me@example.test']['participationStatus'],
            'an RSVP must survive somebody fixing the title',
        );
    }

    /** Per-instance decisions are the user's, and a series edit is not a reason to lose them. */
    public function testAnEditKeepsRecurrenceOverrides(): void
    {
        $event = $this->invitedEvent();

        $this->rename($event, 'Quarterly review (moved)');

        self::assertArrayHasKey('recurrenceOverrides', $event->jscalendar);
    }

    /**
     * Re-extraction must not un-answer an invitation.
     *
     * The invitation says NEEDS-ACTION about the reader forever — that is what
     * it said when it was sent — and re-running extraction over stored mail is
     * routine. Without this, every RSVP reverted to unanswered the next time
     * `app:backfill events` ran, while the organiser went on knowing better.
     */
    public function testAnAnswerSurvivesTheInvitationBeingReadAgain(): void
    {
        $event = $this->invitedEvent();

        $this->reextract($event, 'needs-action');

        self::assertSame(
            'accepted',
            $event->jscalendar['participants']['me@example.test']['participationStatus'],
        );
    }

    /**
     * The organiser's own attendee list, on the other hand, is newer than
     * anything here — it knows about replies from people this install never saw.
     */
    public function testAnIncomingAnswerStillWins(): void
    {
        $event = $this->invitedEvent();

        $this->reextract($event, 'declined');

        self::assertSame(
            'declined',
            $event->jscalendar['participants']['me@example.test']['participationStatus'],
        );
    }

    /** What the editor does show is still written from the columns. */
    public function testAnEditStillRewritesTheDerivedFields(): void
    {
        $event = $this->invitedEvent();

        $this->rename($event, 'Quarterly review (moved)');

        self::assertSame('Quarterly review (moved)', $event->jscalendar['title']);
        self::assertSame('Quarterly review (moved)', $event->title);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function rename(CalendarEvent $event, string $title): void
    {
        $utc = new DateTimeZone('UTC');

        $this->writer->write(
            event:    $event,
            calendar: $this->calendar,
            user:     $this->user,
            title:    $title,
            startsAt: new DateTimeImmutable('2026-06-02 11:00', $utc),
            endsAt:   new DateTimeImmutable('2026-06-02 12:00', $utc),
            timeZone: 'UTC',
        );

        $this->em->flush();
    }

    /**
     * The same invitation read again, the way EventReconciler applies one: the
     * extractor's canonical object as an overlay.
     */
    private function reextract(CalendarEvent $event, string $incomingStatus): void
    {
        $utc = new DateTimeZone('UTC');

        $this->writer->write(
            event:    $event,
            calendar: $this->calendar,
            user:     $this->user,
            title:    'Quarterly review',
            startsAt: new DateTimeImmutable('2026-06-02 09:00', $utc),
            endsAt:   new DateTimeImmutable('2026-06-02 10:00', $utc),
            timeZone: 'UTC',
            jscalendarOverlay: [
                '@type'        => 'Event',
                'title'        => 'Quarterly review',
                'participants' => [
                    'me@example.test' => [
                        '@type'               => 'Participant',
                        'email'               => 'me@example.test',
                        'roles'               => ['attendee' => true],
                        'participationStatus' => $incomingStatus,
                    ],
                ],
            ],
        );

        $this->em->flush();
    }

    private function invitedEvent(): CalendarEvent
    {
        $utc = new DateTimeZone('UTC');

        $event             = new CalendarEvent();
        $event->calendar   = $this->calendar;
        $event->usr        = $this->user;
        $event->uid        = 'quarterly-review@example.org';
        $event->title      = 'Quarterly review';
        $event->startsAt   = new DateTimeImmutable('2026-06-02 09:00', $utc);
        $event->endsAt     = new DateTimeImmutable('2026-06-02 10:00', $utc);
        $event->timeZone   = 'UTC';
        $event->kind       = ExtractionKind::Meeting;
        $event->jscalendar = [
            '@type'        => 'Event',
            'uid'          => 'quarterly-review@example.org',
            'title'        => 'Quarterly review',
            'participants' => [
                'me@example.test' => [
                    '@type'               => 'Participant',
                    'email'               => 'me@example.test',
                    'roles'               => ['attendee' => true],
                    'participationStatus' => 'accepted',
                ],
            ],
            'recurrenceOverrides' => [
                '2026-06-09T09:00:00' => ['excluded' => true],
            ],
        ];

        $this->em->persist($event);
        $this->em->flush();

        return $event;
    }

    private function seed(): void
    {
        $user            = new User();
        $user->email     = 'writer-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Writer';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $calendar           = new Calendar();
        $calendar->usr      = $user;
        $calendar->name     = 'Writer fixture';
        $calendar->role     = CalendarRole::Custom;
        $calendar->timeZone = 'UTC';
        $this->em->persist($calendar);

        $this->em->flush();

        $this->user     = $user;
        $this->calendar = $calendar;
    }
}
