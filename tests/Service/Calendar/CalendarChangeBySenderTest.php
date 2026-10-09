<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar;

use App\Domain\Enum\Calendar\EventSource;
use App\Domain\Enum\Calendar\EventStatus;
use App\Domain\Enum\Calendar\ParticipationStatus;
use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\Calendar\EventSourceLink;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessagePart;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Handler\ExtractEventsHandler;
use App\Infrastructure\Messaging\Message\ExtractEventsMessage;
use App\Service\Calendar\CalendarEventWriter;
use App\Service\Calendar\CalendarProvisioner;
use App\Service\Calendar\EventReconciler;
use App\Service\Calendar\Extraction\EventExtractionRunner;
use App\Service\Label\LabelResolver;
use App\Service\Mail\PostIngest\EnrichmentRouter;
use App\Service\Mail\ThreadStatusUpdater;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Who may change a calendar by sending mail — issue #34.
 *
 * Two holes, reported together and closed by one rule.
 *
 * AN INVITATION WAS FOUND BY ITS UID AND UPDATED BY ANYONE WHO KNEW IT.
 * Everyone invited to a meeting holds its UID, and so does anyone a copy was
 * forwarded to. Any of them could send the reader a CANCEL, or a REQUEST with a
 * higher SEQUENCE and a different time or meeting link, and it was applied —
 * with the reader's earlier "accepted" carried over, so the changed meeting
 * looked exactly like the one they had agreed to.
 *
 * A BOOKING IN A MAIL'S MARKUP WAS DRAWN ON THE STRENGTH OF A FROM LINE
 * NOBODY HAD CHECKED. A stranger's "flight" appeared on the calendar unasked,
 * and a forged From with a known booking number — they are printed on boarding
 * passes — moved or cancelled the real one.
 *
 * Every test here is one of those attacks or one of the ordinary cases the fix
 * must not break. The ordinary cases matter as much: a rule that held every
 * genuine update would be "fixed" by people learning to click through it.
 *
 * What is asserted for a refused claim is always three things, because each
 * is a way to get this wrong: the event is unchanged; the claim is still
 * FILED, so "why did nothing happen?" has an answer; and it is marked held,
 * which is what puts "apply anyway" on the message's card.
 */
final class CalendarChangeBySenderTest extends KernelTestCase
{
    private const string ORGANISER = 'alice@example.test';
    private const string OUTSIDER  = 'carol@elsewhere.example';
    private const string ME        = 'bob@example.test';

    private EntityManagerInterface $em;
    private Connection $connection;
    private EventExtractionRunner $runner;
    private EventReconciler $reconciler;
    private Account $account;
    private Calendar $calendar;
    private string $projectDir;

    /** @var list<string> */
    private array $written = [];

    protected function setUp(): void
    {
        $container        = static::getContainer();
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

    // ── invitations ──────────────────────────────────────────────────────────

    /** The headline: Carol, who was merely invited too, calls Bob's meeting off. */
    public function testSomebodyWhoKnowsTheUidCannotCancelTheMeeting(): void
    {
        $event = $this->invite('uid-1', from: self::ORGANISER);

        $message = $this->invite('uid-1', from: self::OUTSIDER, sequence: 5, method: 'CANCEL', returnMessage: true);

        $this->em->refresh($event);

        self::assertNotSame(EventStatus::Cancelled, $event->status, 'the meeting still stands');
        self::assertHeld($this->linkFor($event, $message));
    }

    /**
     * The quieter one: the same meeting, an hour of somebody else's choosing
     * and their link in the description, still showing as accepted.
     */
    public function testSomebodyWhoKnowsTheUidCannotMoveOrRewriteTheMeeting(): void
    {
        $event = $this->invite('uid-2', from: self::ORGANISER);
        $event->myParticipation = ParticipationStatus::Accepted;
        $this->em->flush();

        $message = $this->invite(
            'uid-2',
            from: self::OUTSIDER,
            sequence: 5,
            start: '20260811T150000Z',
            summary: 'Standup — join here instead',
            returnMessage: true,
        );

        $this->em->refresh($event);

        self::assertSame('Standup', $event->title);
        self::assertSame('2026-08-10 09:00', $event->startsAt->format('Y-m-d H:i'));
        self::assertHeld($this->linkFor($event, $message));
    }

    /**
     * Naming the real organiser inside the file is not a credential: the
     * forged file says ORGANIZER:alice, because the one it was copied from did.
     */
    public function testTheOrganiserLineInTheFileDoesNotVouchForTheSender(): void
    {
        $event = $this->invite('uid-3', from: self::ORGANISER);

        // organiser: is the default — Alice — while the mail comes from Carol.
        $this->invite('uid-3', from: self::OUTSIDER, sequence: 2, method: 'CANCEL');

        $this->em->refresh($event);

        self::assertNotSame(EventStatus::Cancelled, $event->status);
    }

    /** What must keep working: the organiser moves their own meeting. */
    public function testTheSenderOfTheInvitationCanStillUpdateAndCancelIt(): void
    {
        $event = $this->invite('uid-4', from: self::ORGANISER);

        $this->invite('uid-4', from: self::ORGANISER, sequence: 1, start: '20260811T150000Z');
        $this->em->refresh($event);

        self::assertSame('2026-08-11 15:00', $event->startsAt->format('Y-m-d H:i'));

        $this->invite('uid-4', from: self::ORGANISER, sequence: 2, method: 'CANCEL');
        $this->em->refresh($event);

        self::assertSame(EventStatus::Cancelled, $event->status);
    }

    /**
     * A calendar service sends the invitation from an address of its own, not
     * the organiser's — and sends the update from the same one. "The sender
     * must be the organiser" would have held every update such a service ever
     * sent; "the sender the event came from" does not.
     */
    public function testAnInvitationSentThroughARelayIsUpdatedThroughTheSameRelay(): void
    {
        $relay = 'calendar-notification@relay.example';
        $event = $this->invite('uid-5', from: $relay);

        $this->invite('uid-5', from: $relay, sequence: 1, start: '20260811T150000Z');
        $this->em->refresh($event);

        self::assertSame('2026-08-11 15:00', $event->startsAt->format('Y-m-d H:i'));

        // And the organiser the event names, writing from their own mailbox.
        $this->invite('uid-5', from: self::ORGANISER, sequence: 2, start: '20260812T100000Z');
        $this->em->refresh($event);

        self::assertSame('2026-08-12 10:00', $event->startsAt->format('Y-m-d H:i'));
    }

    /**
     * The case the report did not mention and the code had: a meeting mirrored
     * from a connected calendar never arrived by mail at all, and was just as
     * rewritable by anyone who could guess or learn its UID.
     */
    public function testAnEventFromAConnectedCalendarIsNotRewrittenByAStrangersMail(): void
    {
        $event = $this->syncedEvent('uid-6', organiser: self::ORGANISER);

        $this->invite('uid-6', from: self::OUTSIDER, sequence: 9, method: 'CANCEL');
        $this->em->refresh($event);

        self::assertNotSame(EventStatus::Cancelled, $event->status);

        // Its organiser's mail is the organiser's own word about it, as before.
        $this->invite('uid-6', from: self::ORGANISER, sequence: 10, start: '20260811T150000Z');
        $this->em->refresh($event);

        self::assertSame('2026-08-11 15:00', $event->startsAt->format('Y-m-d H:i'));
    }

    /**
     * A From line that a believed server could not vouch for is not a From
     * line. That includes the reader's own address, which is what a forger
     * writes when "mail from yourself is trusted" is the rule.
     */
    public function testAFromThatFailedAuthenticationIsNobody(): void
    {
        $event = $this->invite('uid-7', from: self::ORGANISER);

        foreach ([self::ORGANISER, self::ME] as $forged) {
            $this->invite(
                'uid-7',
                from: $forged,
                sequence: 5,
                method: 'CANCEL',
                authentication: 'mx.example.test; spf=fail smtp.mailfrom=x@elsewhere.example; dmarc=fail header.from=example.test',
            );
        }

        $this->em->refresh($event);

        self::assertNotSame(EventStatus::Cancelled, $event->status);
    }

    /**
     * Held is not dropped. The rule is a judgment about a sender and the
     * reader may know better — the organiser changed jobs, a colleague sent
     * the update on their behalf — so the card offers to apply it, and this is
     * what that button does.
     */
    public function testAHeldChangeIsAppliedWhenTheReaderSaysSo(): void
    {
        $event   = $this->invite('uid-8', from: self::ORGANISER);
        $message = $this->invite('uid-8', from: self::OUTSIDER, sequence: 1, start: '20260811T150000Z', returnMessage: true);

        $this->em->refresh($event);
        self::assertSame('2026-08-10 09:00', $event->startsAt->format('Y-m-d H:i'));

        $this->reconciler->reconcile($message, $this->runner->run($message), confirmed: true);
        $this->em->flush();
        $this->em->refresh($event);

        self::assertSame('2026-08-11 15:00', $event->startsAt->format('Y-m-d H:i'));

        $link = $this->linkFor($event, $message);

        self::assertTrue($link->applied);
        self::assertNull($link->holdReason, 'an applied claim no longer says it is waiting');

        // And having been let in once, that sender is now one the event has
        // come from: their next update is not asked about again.
        $this->invite('uid-8', from: self::OUTSIDER, sequence: 2, start: '20260812T100000Z');
        $this->em->refresh($event);

        self::assertSame('2026-08-12 10:00', $event->startsAt->format('Y-m-d H:i'));
    }

    // ── bookings read out of markup ──────────────────────────────────────────

    /**
     * A stranger's "flight". It is recorded — the card above the mail offers
     * to add it — and it is not on the calendar until somebody says yes.
     */
    public function testABookingFromAnUnverifiedSenderIsOfferedNotDrawn(): void
    {
        $event = $this->booking('ABC123', from: 'noreply@airline.example', authentication: null);

        self::assertNotNull($event);
        self::assertSame(ParticipationStatus::NeedsAction, $event->myParticipation);
    }

    public function testABookingFromAnAuthenticatedSenderIsDrawnAsBefore(): void
    {
        $event = $this->booking('ABC123', from: 'noreply@airline.example');

        self::assertNotNull($event);
        self::assertNull($event->myParticipation, 'nothing to answer: it is simply on the calendar');
    }

    /**
     * The overwrite. The forged mail has the airline's From and the real
     * booking number, so it has the real booking's key — and being newer, it
     * used to win.
     *
     * A cancellation, with the flight otherwise as booked: a leg's identity
     * includes its departure, so a forged mail naming a different day is not
     * this booking at all — it is a new, unverified one, which the test above
     * covers.
     */
    public function testAForgedFromCannotCancelARealBooking(): void
    {
        $event = $this->booking('ABC123', from: 'noreply@airline.example');

        foreach ([null, 'mx.example.test; spf=fail smtp.mailfrom=x@elsewhere.example; dmarc=fail header.from=airline.example'] as $authentication) {
            $message = $this->booking(
                'ABC123',
                from: 'noreply@airline.example',
                authentication: $authentication,
                status: 'http://schema.org/ReservationCancelled',
                returnMessage: true,
            );

            $this->em->refresh($event);

            self::assertNotSame(EventStatus::Cancelled, $event->status);
            self::assertHeld($this->linkFor($event, $message));
        }
    }

    // ── mail in Spam ─────────────────────────────────────────────────────────

    /**
     * The folder a provider puts mail it distrusts in was a way onto the
     * calendar. Read at the moment of extraction, so it follows the message.
     */
    public function testMailInSpamIsNotReadForEventsUntilItIsTakenOut(): void
    {
        $container = static::getContainer();
        $labels    = $container->get(LabelResolver::class);
        $spam      = $labels->systemLabel(LabelRole::Spam, $this->account);

        $message = $this->invite('uid-spam', from: self::ORGANISER, reconcile: false, returnMessage: true);
        $message->addLabel($spam);

        // A conversation to belong to, which is how the status service finds a
        // message's account when it has no IMAP folder.
        $thread                    = new MessageThread();
        $thread->account           = $this->account;
        $thread->subject           = 'Calendar';
        $thread->normalizedSubject = 'calendar';
        $thread->lastMessageAt     = new DateTimeImmutable();
        $thread->threadingMethod   = ThreadingMethod::References;
        $thread->unreadCount       = 0;
        $this->em->persist($thread);
        $thread->addMessage($message);

        $this->em->flush();

        $handler = $container->get(ExtractEventsHandler::class);
        $handler(new ExtractEventsMessage([(int) $message->id]));

        self::assertNull($this->eventByUid('uid-spam'), 'nothing is drawn from a message in Spam');

        // "Not spam": the message is queued to be read after all…
        $container->get(ThreadStatusUpdater::class)->restore([$message]);
        $this->em->flush();

        $transport = $container->get('messenger.transport.' . EnrichmentRouter::LIVE);
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        $queued = array_values(array_filter(
            array_map(
                static fn (object $envelope): object => $envelope->getMessage(),
                $transport->getSent(),
            ),
            static fn (object $sent): bool => $sent instanceof ExtractEventsMessage && [(int) $message->id] === $sent->messageIds,
        ));

        self::assertCount(1, $queued, 'restoring a message queues it for extraction');

        // …and read, now that it is somewhere mail is believed.
        $handler($queued[0]);

        self::assertNotNull($this->eventByUid('uid-spam'));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function eventByUid(string $uid): ?CalendarEvent
    {
        return $this->em->getRepository(CalendarEvent::class)->findOneBy(['calendar' => $this->calendar, 'uid' => $uid]);
    }

    private static function assertHeld(?EventSourceLink $link): void
    {
        self::assertNotNull($link, 'the refused claim is still filed against the event');
        self::assertFalse($link->applied);
        self::assertSame(EventSourceLink::HOLD_UNVERIFIED, $link->holdReason);
    }

    private function linkFor(CalendarEvent $event, Message $message): ?EventSourceLink
    {
        return $this->em->getRepository(EventSourceLink::class)->findOneBy(['event' => $event, 'message' => $message]);
    }

    /**
     * An invitation, an update or a cancellation for `$uid`, as mail from
     * `$from`. The ORGANIZER line always names Alice unless told otherwise —
     * which is the point of half the tests above.
     *
     * @return ($returnMessage is true ? Message : CalendarEvent)
     */
    private function invite(
        string  $uid,
        string  $from,
        int     $sequence = 0,
        string  $method = 'REQUEST',
        string  $start = '20260810T090000Z',
        string  $summary = 'Standup',
        ?string $authentication = null,
        bool    $returnMessage = false,
        bool    $reconcile = true,
    ): CalendarEvent|Message {
        $end = (new DateTimeImmutable($start))->modify('+30 minutes')->format('Ymd\THis\Z');

        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nMETHOD:{$method}\r\nBEGIN:VEVENT\r\n"
            . "UID:{$uid}\r\nSUMMARY:{$summary}\r\nSEQUENCE:{$sequence}\r\n"
            . "DTSTART:{$start}\r\nDTEND:{$end}\r\n"
            . ('CANCEL' === $method ? "STATUS:CANCELLED\r\n" : '')
            . 'ORGANIZER;CN=Alice:mailto:' . self::ORGANISER . "\r\n"
            . 'ATTENDEE;CN=Bob;PARTSTAT=NEEDS-ACTION:mailto:' . self::ME . "\r\n"
            . "END:VEVENT\r\nEND:VCALENDAR";

        $message = $this->message($from, $authentication);

        $relative = 'var/test-invites/' . uniqid('ics-', true) . '.ics';
        $absolute = $this->projectDir . '/' . $relative;

        if (false === is_dir(dirname($absolute))) {
            mkdir(dirname($absolute), 0o775, true);
        }

        file_put_contents($absolute, $ics);
        $this->written[] = $absolute;

        $part              = new MessagePart();
        $part->message     = $message;
        $part->contentType = 'text/calendar';
        $part->filename    = 'invite.ics';
        $part->disposition = 'inline';
        $part->size        = strlen($ics);
        $part->storagePath = $relative;
        $part->isInline    = true;

        $this->em->persist($part);
        $this->em->flush();
        $this->em->refresh($message);

        if (true === $reconcile) {
            $this->reconciler->reconcile($message, $this->runner->run($message));
            $this->em->flush();
        }

        if (true === $returnMessage) {
            return $message;
        }

        return $this->eventByUid($uid) ?? self::fail('the invitation made no event');
    }

    /**
     * A flight confirmation as schema.org markup in the body.
     *
     * `$authentication` defaults to what the account's own server writes for a
     * genuine sender; null is a server that said nothing.
     *
     * @return ($returnMessage is true ? Message : ?CalendarEvent)
     */
    private function booking(
        string  $number,
        string  $from,
        ?string $authentication = 'default',
        string  $departure = '2026-09-01T10:00:00+02:00',
        string  $status = 'http://schema.org/ReservationConfirmed',
        bool    $returnMessage = false,
    ): CalendarEvent|Message|null {
        if ('default' === $authentication) {
            $domain         = substr($from, (int) strrpos($from, '@') + 1);
            $authentication = sprintf('mx.example.test; dkim=pass header.d=%1$s; dmarc=pass header.from=%1$s', $domain);
        }

        $arrival = (new DateTimeImmutable($departure))->modify('+2 hours')->format(DATE_ATOM);

        $message           = $this->message($from, $authentication);
        $message->bodyHtml = '<html><body><script type="application/ld+json">' . json_encode([
            '@context'          => 'http://schema.org',
            '@type'             => 'FlightReservation',
            'reservationNumber' => $number,
            'reservationStatus' => $status,
            'reservationFor'    => [
                '@type'            => 'Flight',
                'flightNumber'     => '110',
                'airline'          => ['@type' => 'Airline', 'name' => 'Example Air', 'iataCode' => 'EX'],
                'departureAirport' => ['@type' => 'Airport', 'name' => 'Frankfurt', 'iataCode' => 'FRA'],
                'arrivalAirport'   => ['@type' => 'Airport', 'name' => 'Munich', 'iataCode' => 'MUC'],
                'departureTime'    => $departure,
                'arrivalTime'      => $arrival,
            ],
        ], JSON_THROW_ON_ERROR) . '</script><p>Your booking</p></body></html>';

        $this->em->flush();

        $touched = $this->reconciler->reconcile($message, $this->runner->run($message));
        $this->em->flush();

        if (true === $returnMessage) {
            return $message;
        }

        return $touched[0] ?? null;
    }

    private function message(string $from, ?string $authentication): Message
    {
        $message                 = new Message();
        $message->account        = $this->account;
        $message->messageId      = uniqid('sender-', true) . '@example.test';
        $message->subject        = 'Calendar';
        $message->fromAddress    = $from;
        $message->hasAttachments = false;
        // Strictly later than the one before, so "newer wins" is never what
        // decided a test about who sent it.
        $message->receivedAt     = new DateTimeImmutable(sprintf('+%d seconds', count($this->written) + $this->clock++));

        if (null !== $authentication) {
            $message->headers = ['authentication-results' => $authentication];
        }

        $this->em->persist($message);
        $this->em->flush();

        return $message;
    }

    private int $clock = 0;

    /** A meeting as a connected calendar mirrors it: no mail behind it at all. */
    private function syncedEvent(string $uid, string $organiser): CalendarEvent
    {
        $event      = new CalendarEvent();
        $event->uid = $uid;

        static::getContainer()->get(CalendarEventWriter::class)->write(
            event:    $event,
            calendar: $this->calendar,
            user:     $this->account->usr,
            title:    'Standup',
            startsAt: new DateTimeImmutable('2026-08-10 09:00:00 UTC'),
            endsAt:   new DateTimeImmutable('2026-08-10 09:30:00 UTC'),
            timeZone: 'UTC',
            jscalendarOverlay: ['participants' => [
                $organiser => ['@type' => 'Participant', 'email' => $organiser, 'roles' => ['owner' => true]],
                self::ME   => ['@type' => 'Participant', 'email' => self::ME, 'roles' => ['attendee' => true]],
            ]],
        );

        $event->source = EventSource::RemoteSync;

        $this->em->flush();

        return $event;
    }

    private function seed(): void
    {
        $user            = new User();
        $user->email     = 'sender-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Bob';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $account                 = new Account();
        $account->usr            = $user;
        $account->email          = self::ME;
        $account->username       = self::ME;
        // A real name: the server in the fixtures' Authentication-Results
        // header has to be recognisably this account's own.
        $account->imapHost       = 'imap.example.test';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        $account->smtpHost       = 'smtp.example.test';
        $account->smtpPort       = 587;
        $account->smtpEncryption = 'starttls';
        $account->password       = 'x';
        $account->authType       = 'password';
        $account->isActive       = true;
        $this->em->persist($account);

        $this->em->flush();

        $this->account = $account;

        $provisioner    = static::getContainer()->get(CalendarProvisioner::class);
        $this->calendar = $provisioner->defaultFor($user);
        $provisioner->forAccount($account);

        $this->em->flush();
    }
}
