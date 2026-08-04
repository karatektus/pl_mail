<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar;

use App\Domain\Enum\Calendar\EventStatus;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\Calendar\EventSourceLink;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessagePart;
use App\Entity\User\User;
use App\Repository\Calendar\EventSuppressionRepository;
use App\Service\Calendar\CalendarProvisioner;
use App\Service\Calendar\EventReconciler;
use App\Service\Calendar\Extraction\EventExtractionRunner;
use App\Entity\Calendar\EventSuppression;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * An invite, from a text/calendar part to a row on a calendar.
 *
 * The interesting cases are not "does it parse" — sabre does that — but what
 * happens when the same booking arrives three times. A confirmation, a change
 * and a cancellation is the normal life of a meeting, they are not delivered in
 * order, and getting it wrong shows up as three copies of one standup or a
 * cancelled meeting that quietly un-cancels itself.
 */
final class IcsExtractionTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private EventExtractionRunner $runner;
    private EventReconciler $reconciler;
    private Account $account;
    private User $user;
    private Calendar $calendar;
    private string $projectDir;

    /** @var list<string> */
    private array $written = [];

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->runner     = $container->get(EventExtractionRunner::class);
        $this->reconciler = $container->get(EventReconciler::class);
        $this->projectDir = $container->getParameter('kernel.project_dir');

        $this->connection->beginTransaction();
        $this->seed();
    }

    protected function tearDown(): void
    {
        foreach ($this->written as $path) {
            @unlink($path);
        }

        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAnInviteBecomesAnEvent(): void
    {
        $event = $this->ingest($this->ics('standup@example.test', 'Standup', '20260803T090000Z', '20260803T091500Z'));

        self::assertNotNull($event);
        self::assertSame('Standup', $event->title);
        self::assertSame('standup@example.test', $event->uid);
        self::assertSame(EventStatus::Confirmed, $event->status);
        self::assertSame('2026-08-03 09:00', $event->startsAt->format('Y-m-d H:i'));

        // Materialised, or it is on no calendar anyone can see.
        self::assertCount(1, $event->occurrences);
    }

    /** The provenance that makes "why is this on my calendar?" answerable. */
    public function testTheSourceMessageIsRecorded(): void
    {
        $event = $this->ingest($this->ics('linked@example.test', 'Linked', '20260803T090000Z', '20260803T100000Z'));

        $links = $this->em->getRepository(EventSourceLink::class)->findBy(['event' => $event]);

        self::assertCount(1, $links);
        self::assertSame('ics', $links[0]->extractor);
        self::assertTrue($links[0]->applied);

        // The invite itself, kept verbatim so a better mapper can be replayed
        // over it without going back to the mail server.
        self::assertStringContainsString('BEGIN:VCALENDAR', (string) ($links[0]->payload['ics'] ?? ''));
    }

    /** A resend is the same booking, not a second one. */
    public function testTheSameInviteTwiceIsOneEvent(): void
    {
        $ics = $this->ics('once@example.test', 'Once', '20260803T090000Z', '20260803T100000Z');

        $this->ingest($ics);
        $this->ingest($ics);

        self::assertCount(1, $this->eventsWithUid('once@example.test'));
    }

    public function testAHigherSequenceUpdatesTheEvent(): void
    {
        $this->ingest($this->ics('moved@example.test', 'Review', '20260803T090000Z', '20260803T100000Z'));

        $event = $this->ingest(
            $this->ics('moved@example.test', 'Review, moved', '20260803T140000Z', '20260803T150000Z', sequence: 1),
        );

        self::assertNotNull($event);
        self::assertSame('Review, moved', $event->title);
        self::assertSame('2026-08-03 14:00', $event->startsAt->format('Y-m-d H:i'));
        self::assertCount(1, $this->eventsWithUid('moved@example.test'));
    }

    /**
     * Mail is not delivered in the order it was sent, and a backfill processes
     * all of it at once. An older revision arriving last must not win.
     */
    public function testAnOlderSequenceArrivingLaterIsNotApplied(): void
    {
        $this->ingest(
            $this->ics('stale@example.test', 'Newer', '20260803T140000Z', '20260803T150000Z', sequence: 2),
        );

        $this->ingest(
            $this->ics('stale@example.test', 'Older', '20260803T090000Z', '20260803T100000Z', sequence: 1),
        );

        $events = $this->eventsWithUid('stale@example.test');

        self::assertCount(1, $events);
        self::assertSame('Newer', $events[0]->title);

        // The loser is still recorded — that is the audit trail.
        $links = $this->em->getRepository(EventSourceLink::class)->findBy(['event' => $events[0]]);

        self::assertCount(2, $links);
        self::assertSame([true, false], array_map(static fn (EventSourceLink $l): bool => $l->applied, $links));
    }

    /** Cancelled is a state, not a delete — the answer to "wasn't there something?" */
    public function testACancellationMarksTheEventRatherThanRemovingIt(): void
    {
        $this->ingest($this->ics('called-off@example.test', 'Sync', '20260803T090000Z', '20260803T100000Z'));

        $event = $this->ingest(
            $this->ics('called-off@example.test', 'Sync', '20260803T090000Z', '20260803T100000Z', sequence: 1, method: 'CANCEL'),
        );

        self::assertNotNull($event);
        self::assertSame(EventStatus::Cancelled, $event->status);
        self::assertCount(1, $this->eventsWithUid('called-off@example.test'));

        foreach ($event->occurrences as $occurrence) {
            self::assertTrue($occurrence->cancelled);
        }
    }

    /**
     * A later mail may know more about the booking. It does not know more than
     * the person who corrected it.
     */
    public function testAUserEditedEventIsNeverOverwritten(): void
    {
        $event = $this->ingest($this->ics('mine@example.test', 'Original', '20260803T090000Z', '20260803T100000Z'));

        $event->isUserEdited = true;
        $event->title        = 'My own title';
        $this->em->flush();

        $this->ingest(
            $this->ics('mine@example.test', 'Sender wins?', '20260803T140000Z', '20260803T150000Z', sequence: 5),
        );

        $events = $this->eventsWithUid('mine@example.test');

        self::assertSame('My own title', $events[0]->title);
        self::assertSame('2026-08-03 09:00', $events[0]->startsAt->format('Y-m-d H:i'));
    }

    /**
     * Dismissing an event has to survive re-extraction, or every backfill puts
     * back what the user just threw away.
     */
    public function testASuppressedEventIsNotRecreated(): void
    {
        $suppression               = new EventSuppression();
        $suppression->usr          = $this->user;
        $suppression->dedupKeyHash = EventSuppressionRepository::hash('ics:unwanted@example.test');
        $this->em->persist($suppression);
        $this->em->flush();

        $this->ingest($this->ics('unwanted@example.test', 'Unwanted', '20260803T090000Z', '20260803T100000Z'));

        self::assertSame([], $this->eventsWithUid('unwanted@example.test'));
    }

    /**
     * The one that broke on a real mailbox.
     *
     * findOneByUid() asks the database, which cannot see a queued INSERT, so
     * two messages carrying the same UID in one unflushed batch each found
     * nothing, each created an event, and the flush was rejected by the unique
     * constraint on (calendar_id, uid) — which closed the entity manager and
     * took the rest of the run with it.
     *
     * A resend and its original land in the same batch routinely: a backfill
     * walks a whole mailbox at once, and invites are sent more than once.
     */
    public function testTheSameUidTwiceBeforeAFlushIsStillOneEvent(): void
    {
        $ics = $this->ics('batched@example.test', 'Batched', '20260803T090000Z', '20260803T100000Z');

        // BOTH messages first: creating one persists and flushes, and a flush
        // between the two reconciles would commit the first event and hand the
        // second a database lookup that finds it — which is exactly the
        // situation this test exists to rule out.
        $first  = $this->messageWithInvite($ics);
        $second = $this->messageWithInvite($ics);

        $this->reconciler->reconcile($first, $this->runner->run($first));
        $this->reconciler->reconcile($second, $this->runner->run($second));

        $this->em->flush();

        self::assertCount(1, $this->eventsWithUid('batched@example.test'));
    }

    /** And a genuine second event in the same batch is still its own row. */
    public function testTwoDifferentUidsInOneBatchAreTwoEvents(): void
    {
        $first  = $this->messageWithInvite(
            $this->ics('first@example.test', 'First', '20260803T090000Z', '20260803T100000Z'),
        );
        $second = $this->messageWithInvite(
            $this->ics('second@example.test', 'Second', '20260804T090000Z', '20260804T100000Z'),
        );

        $this->reconciler->reconcile($first, $this->runner->run($first));
        $this->reconciler->reconcile($second, $this->runner->run($second));

        $this->em->flush();

        self::assertCount(1, $this->eventsWithUid('first@example.test'));
        self::assertCount(1, $this->eventsWithUid('second@example.test'));
    }

    /** No UID means no identity, so every resend would be a new event. */
    public function testAnInviteWithNoUidIsIgnored(): void
    {
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\n"
            . "SUMMARY:Anonymous\r\nDTSTART:20260803T090000Z\r\nDTEND:20260803T100000Z\r\n"
            . "END:VEVENT\r\nEND:VCALENDAR";

        self::assertNull($this->ingest($ics));
    }

    /** Senders emit all sorts; a broken part costs an event, never the message. */
    public function testUnparseableCalendarDataIsSurvivable(): void
    {
        self::assertNull($this->ingest('this is not a calendar at all'));
    }

    /**
     * The organiser is usually in the attendee list as well — they are going
     * too. Both lines name the same address, and the participant map is keyed
     * by address, so the second used to land on top of the first and take the
     * `owner` role away with it. The event then had no organiser at all: the
     * invite card had nobody to reply to and offered no answer, on every real
     * Google Calendar invitation there is.
     */
    public function testAnOrganiserWhoIsAlsoAnAttendeeKeepsBothRoles(): void
    {
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nMETHOD:REQUEST\r\nBEGIN:VEVENT\r\n"
            . "UID:both-roles@example.test\r\nSUMMARY:Testevent\r\nSEQUENCE:0\r\n"
            . "DTSTART:20260805T090000Z\r\nDTEND:20260805T100000Z\r\n"
            . "ORGANIZER;CN=The Chair:mailto:chair@example.test\r\n"
            . "ATTENDEE;CN=The Chair;PARTSTAT=ACCEPTED:mailto:chair@example.test\r\n"
            . "ATTENDEE;CN=Me;PARTSTAT=NEEDS-ACTION:mailto:me@example.test\r\n"
            . "END:VEVENT\r\nEND:VCALENDAR";

        $event = $this->ingest($ics);

        self::assertNotNull($event);

        $chair = $event->jscalendar['participants']['chair@example.test'];

        self::assertTrue($chair['roles']['owner'] ?? false, 'the organiser must still be the organiser');
        self::assertTrue($chair['roles']['attendee'] ?? false, 'and is going, which is why they are listed twice');

        // The ORGANIZER line carries no PARTSTAT, so the answer can only have
        // come from the attendee line for the same person.
        self::assertSame('accepted', $chair['participationStatus']);
        self::assertSame('The Chair', $chair['name']);

        self::assertSame(
            ['attendee' => true],
            $event->jscalendar['participants']['me@example.test']['roles'],
            'everybody else is only ever an attendee',
        );
    }

    /**
     * A weekly meeting somebody emailed is a weekly meeting.
     *
     * The rule used to be stashed verbatim under `plmail:rrule` because
     * RRULE→JSCalendar had not been written, and RecurrenceMaterialiser reads
     * recurrenceRules and nothing else — so an invitation to a standing meeting
     * put exactly one morning on the calendar and nobody could tell it apart
     * from a one-off.
     */
    public function testARecurringInviteIsDrawnEveryWeekRatherThanOnce(): void
    {
        $ics = str_replace(
            'DTEND:20260804T093000Z',
            "DTEND:20260804T093000Z\r\nRRULE:FREQ=WEEKLY;COUNT=6",
            $this->ics('weekly@example.test', 'Standup', '20260804T090000Z', '20260804T093000Z'),
        );

        $event = $this->ingest($ics);

        self::assertNotNull($event);
        self::assertTrue($event->isRecurring);
        self::assertCount(6, $event->occurrences);
        self::assertSame(
            ['@type' => 'RecurrenceRule', 'frequency' => 'weekly', 'count' => 6],
            $event->jscalendar['recurrenceRules'][0] ?? null,
        );
        self::assertArrayNotHasKey('plmail:rrule', $event->jscalendar);
    }

    /**
     * An instance the organiser cancelled is an EXDATE on the invitation, and
     * nothing else in the mail says it — so a mapping that skips it draws a
     * meeting that was called off weeks ago.
     */
    public function testAnExcludedInstanceOfAnEmailedSeriesIsNotDrawn(): void
    {
        $ics = str_replace(
            'DTEND:20260804T093000Z',
            "DTEND:20260804T093000Z\r\nRRULE:FREQ=WEEKLY;COUNT=6\r\nEXDATE:20260811T090000Z",
            $this->ics('weekly-gap@example.test', 'Standup', '20260804T090000Z', '20260804T093000Z'),
        );

        $event = $this->ingest($ics);

        self::assertNotNull($event);
        self::assertCount(5, $event->occurrences, 'five of the six remain');

        foreach ($event->occurrences as $occurrence) {
            self::assertNotSame('2026-08-11 09:00', $occurrence->startsAt?->format('Y-m-d H:i'));
        }
    }

    /** An invite that says nothing about its length is not an invite to nothing. */
    public function testAnEventWithNoEndGetsAUsableLength(): void
    {
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:instant@example.test\r\n"
            . "SUMMARY:Instant\r\nDTSTART:20260803T090000Z\r\nEND:VEVENT\r\nEND:VCALENDAR";

        $event = $this->ingest($ics);

        self::assertNotNull($event);
        self::assertGreaterThan($event->startsAt, $event->endsAt);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /**
     * A message carrying one text/calendar part, run through the real
     * extractor and the real reconciler.
     */
    private function ingest(string $ics): ?CalendarEvent
    {
        $message = $this->messageWithInvite($ics);

        $touched = $this->reconciler->reconcile($message, $this->runner->run($message));
        $this->em->flush();

        return $touched[0] ?? null;
    }

    /** A persisted message carrying one text/calendar part with these bytes. */
    private function messageWithInvite(string $ics): Message
    {
        $message = new Message();
        $message->account = $this->account;
        $message->subject = 'Invitation';
        $message->fromAddress = 'organiser@example.test';
        $message->receivedAt = new DateTimeImmutable();
        $message->hasAttachments = false;
        $message->messageId = sprintf('<%s@example.test>', uniqid('', true));
        $this->em->persist($message);
        $this->em->flush();

        $relative = sprintf('var/test-ics/%s.ics', uniqid('', true));
        $absolute = $this->projectDir . '/' . $relative;

        if (false === is_dir(dirname($absolute))) {
            mkdir(dirname($absolute), 0o775, true);
        }

        file_put_contents($absolute, $ics);
        $this->written[] = $absolute;

        $part = new MessagePart();
        $part->message     = $message;
        $part->contentType = 'text/calendar';
        $part->filename    = 'invite.ics';
        $part->disposition = 'inline';
        $part->size        = strlen($ics);
        $part->storagePath = $relative;
        $part->isInline    = true;
        $this->em->persist($part);
        $this->em->flush();

        // Read back, because the builder does not touch the inverse side.
        $this->em->refresh($message);

        return $message;
    }

    /**
     * @return list<CalendarEvent>
     */
    private function eventsWithUid(string $uid): array
    {
        return $this->em->getRepository(CalendarEvent::class)
            ->findBy(['calendar' => $this->calendar, 'uid' => $uid]);
    }

    private function ics(
        string $uid,
        string $summary,
        string $start,
        string $end,
        int    $sequence = 0,
        string $method = 'REQUEST',
    ): string {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nMETHOD:{$method}\r\nBEGIN:VEVENT\r\n"
            . "UID:{$uid}\r\nSUMMARY:{$summary}\r\nSEQUENCE:{$sequence}\r\n"
            . "DTSTART:{$start}\r\nDTEND:{$end}\r\n"
            . "ORGANIZER;CN=Organiser:mailto:organiser@example.test\r\n"
            . "ATTENDEE;CN=Me;PARTSTAT=NEEDS-ACTION:mailto:me@example.test\r\n"
            . "END:VEVENT\r\nEND:VCALENDAR";
    }

    private function seed(): void
    {
        $user = new User();
        $user->email = 'ics-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Ics';
        $user->nameLast = 'Fixture';
        $user->roles = ['ROLE_USER'];
        $user->password = 'x';
        $this->em->persist($user);
        $this->user = $user;

        $account = new Account();
        $account->usr = $user;
        $account->email = 'Ics Fixture';
        $account->username = 'ics-fixture@example.test';
        $account->imapHost = 'localhost';
        $account->imapPort = 993;
        $account->imapEncryption = 'ssl';
        $account->smtpHost = 'localhost';
        $account->smtpPort = 587;
        $account->smtpEncryption = 'starttls';
        $account->password = 'x';
        $account->authType = 'password';
        $account->isActive = true;
        $this->em->persist($account);
        $this->em->flush();

        $this->account = $account;

        $provisioner = self::getContainer()->get(CalendarProvisioner::class);
        $provisioner->defaultFor($user);
        $calendar = $provisioner->forAccount($account);
        $this->em->flush();

        self::assertNotNull($calendar);
        $this->calendar = $calendar;
    }
}
