<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar;

use App\Domain\Enum\Calendar\ParticipationStatus;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\Calendar\CalendarEventOccurrence;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessagePart;
use App\Entity\User\User;
use App\Service\Calendar\CalendarProvisioner;
use App\Service\Calendar\EventReconciler;
use App\Service\Calendar\Extraction\EventExtractionRunner;
use App\Service\Calendar\RecurrenceMaterialiser;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * An invitation is on the calendar once it is accepted — or answered "maybe" —
 * and not before.
 *
 * What made this necessary is that the previous behaviour was invisible rather
 * than wrong-looking: an invitation drew a chip the moment the mail arrived, so
 * a week nobody had agreed to anything in looked exactly like a week they had.
 * The RSVP was recorded, and changed nothing on screen.
 *
 * The mechanism under test is deliberately one thing —
 * CalendarEvent::$myParticipation gating whether RecurrenceMaterialiser writes
 * occurrence rows — because occurrences are what every reader reads. So the
 * assertions here are about occurrence rows, not about any one view: a view
 * that drew an unanswered invitation would have to have found it somewhere
 * other than that table.
 *
 * Three things must NOT happen, and each is a way the feature becomes a bug:
 *
 *   The event row must survive. The invite card above the message finds the
 *   event through its EventSourceLink, a later update from the organiser has to
 *   revise it, and declining has to be reversible. Deleting the row instead of
 *   its occurrences would break all three at once.
 *
 *   A booking must not be gated. A flight confirmation names nobody and is
 *   nobody's invitation; it is on the calendar because it is a fact, and
 *   waiting for somebody to "accept" their own flight would empty the feature
 *   that reads it out of mail.
 *
 *   An organiser's resend must not un-answer it. A REQUEST carries the attendee
 *   list as the organiser last saw it, which routinely says NEEDS-ACTION for
 *   somebody who accepted a week ago — and the only symptom of believing it
 *   would be a chip that vanished.
 */
final class InviteAcceptanceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private EventExtractionRunner $runner;
    private EventReconciler $reconciler;
    private RecurrenceMaterialiser $materialiser;
    private Account $account;
    private Calendar $calendar;
    private string $projectDir;

    /** @var list<string> */
    private array $written = [];

    protected function setUp(): void
    {
        self::bootKernel();

        $container          = self::getContainer();
        $this->em           = $container->get(EntityManagerInterface::class);
        $this->connection   = $container->get(Connection::class);
        $this->runner       = $container->get(EventExtractionRunner::class);
        $this->reconciler   = $container->get(EventReconciler::class);
        $this->materialiser = $container->get(RecurrenceMaterialiser::class);
        $this->projectDir   = $container->getParameter('kernel.project_dir');

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

    /** The headline. */
    public function testAnUnansweredInvitationIsNotOnTheCalendar(): void
    {
        $event = $this->invite('unanswered@example.test');

        self::assertNotNull($event);
        self::assertSame(ParticipationStatus::NeedsAction, $event->myParticipation);
        self::assertCount(0, $this->occurrencesOf($event), 'nothing to draw until somebody says yes');
    }

    /** And the event is still there to be answered. */
    public function testTheInvitationItselfSurvives(): void
    {
        $event = $this->invite('survives@example.test');

        self::assertNotNull($event?->id, 'the row is what the invite card and the next update both need');
        self::assertSame('Standup', $event->title);
    }

    public function testAcceptingPutsItOnTheCalendar(): void
    {
        $event = $this->invite('accepted@example.test');

        $this->answer($event, ParticipationStatus::Accepted);

        self::assertCount(1, $this->occurrencesOf($event));
    }

    /**
     * "Maybe" counts. A tentative meeting is one whose slot has to be kept, and
     * hiding it is how people double-book.
     */
    public function testAMaybeAlsoPutsItOnTheCalendar(): void
    {
        $event = $this->invite('maybe@example.test');

        $this->answer($event, ParticipationStatus::Tentative);

        self::assertCount(1, $this->occurrencesOf($event));
    }

    public function testDecliningLeavesItOff(): void
    {
        $event = $this->invite('declined@example.test');

        $this->answer($event, ParticipationStatus::Declined);

        self::assertCount(0, $this->occurrencesOf($event));
    }

    /** Nothing here is one-way: changing your mind changes the calendar back. */
    public function testChangingAnAnswerMovesItOnAndOffAgain(): void
    {
        $event = $this->invite('changed@example.test');

        $this->answer($event, ParticipationStatus::Accepted);
        self::assertCount(1, $this->occurrencesOf($event));

        $this->answer($event, ParticipationStatus::Declined);
        self::assertCount(0, $this->occurrencesOf($event));

        $this->answer($event, ParticipationStatus::Accepted);
        self::assertCount(1, $this->occurrencesOf($event));
    }

    /**
     * A recurring invitation, because the gate has to sit in front of the
     * expansion and not inside it: a weekly meeting nobody accepted would
     * otherwise put a hundred chips on the calendar rather than one.
     */
    public function testARecurringInvitationIsGatedAsAWhole(): void
    {
        $event = $this->invite('weekly@example.test', rrule: 'FREQ=WEEKLY;COUNT=10');

        self::assertCount(0, $this->occurrencesOf($event));

        $this->answer($event, ParticipationStatus::Accepted);

        self::assertCount(10, $this->occurrencesOf($event));
    }

    /**
     * The population that must NOT be gated, and the one that would be the most
     * expensive to get wrong: everything read out of mail that is not an
     * invitation at all.
     */
    public function testAnInvitationAddressedToSomebodyElseIsNotGated(): void
    {
        $event = $this->invite('elsewhere@example.test', attendee: 'someone-else@example.test');

        self::assertNull($event?->myParticipation, 'not addressed to this mailbox, so not this mailbox\'s to accept');
        self::assertCount(1, $this->occurrencesOf($event));
    }

    /** A meeting the owner organised is theirs; waiting for them to accept it is absurd. */
    public function testAnInvitationTheOwnerSentIsNotGated(): void
    {
        $event = $this->invite(
            'mine@example.test',
            organiser: 'invite-fixture@example.test',
            attendee: 'invite-fixture@example.test',
        );

        self::assertNull($event?->myParticipation);
        self::assertCount(1, $this->occurrencesOf($event));
    }

    /**
     * The regression an organiser causes by pressing "send update": their
     * attendee list is as they last saw it, so it says NEEDS-ACTION for
     * somebody who accepted last week.
     */
    public function testAResentInvitationDoesNotUnAnswerIt(): void
    {
        $event = $this->invite('resent@example.test');

        $this->answer($event, ParticipationStatus::Accepted);

        // Same UID, higher sequence, and the organiser still believes nobody
        // has answered.
        $updated = $this->invite('resent@example.test', summary: 'Standup, moved', sequence: 1);

        self::assertNotNull($updated);
        self::assertSame('Standup, moved', $updated->title, 'the update itself must still be applied');
        self::assertSame(ParticipationStatus::Accepted, $updated->myParticipation);
        self::assertCount(1, $this->occurrencesOf($updated), 'and the meeting must stay on the calendar');
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /** @return list<CalendarEventOccurrence> */
    private function occurrencesOf(?CalendarEvent $event): array
    {
        self::assertNotNull($event);

        // Read back rather than walked off $event->occurrences: that is the
        // inverse side and is not refreshed for rows written in this same unit
        // of work, which is exactly what is being counted here.
        return $this->em->getRepository(CalendarEventOccurrence::class)->findBy(['event' => $event]);
    }

    /**
     * Answers as the RSVP buttons do — the status onto the column, and the
     * occurrences written again from it.
     *
     * InviteResponder itself is not used, because respond() also sends an iTIP
     * reply through a real mail sender. The two lines it performs locally are
     * the two performed here, and InviteResponder's own test covers that it
     * performs them.
     */
    private function answer(?CalendarEvent $event, ParticipationStatus $status): void
    {
        self::assertNotNull($event);

        $event->myParticipation = $status;

        $this->materialiser->materialise($event);

        $this->em->flush();
    }

    private function invite(
        string  $uid,
        string  $summary = 'Standup',
        int     $sequence = 0,
        ?string $rrule = null,
        string  $organiser = 'organiser@example.test',
        string  $attendee = 'invite-fixture@example.test',
    ): ?CalendarEvent {
        $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nMETHOD:REQUEST\r\nBEGIN:VEVENT\r\n"
            . "UID:{$uid}\r\nSUMMARY:{$summary}\r\nSEQUENCE:{$sequence}\r\n"
            . "DTSTART:20260810T090000Z\r\nDTEND:20260810T093000Z\r\n"
            . (null === $rrule ? '' : "RRULE:{$rrule}\r\n")
            . "ORGANIZER;CN=Organiser:mailto:{$organiser}\r\n"
            . "ATTENDEE;CN=Me;PARTSTAT=NEEDS-ACTION:mailto:{$attendee}\r\n"
            . "END:VEVENT\r\nEND:VCALENDAR";

        $message = $this->message($ics);

        $touched = $this->reconciler->reconcile($message, $this->runner->run($message));

        $this->em->flush();

        return $touched[0] ?? $this->em->getRepository(CalendarEvent::class)
            ->findOneBy(['calendar' => $this->calendar, 'uid' => $uid]);
    }

    private function message(string $ics): Message
    {
        $message                 = new Message();
        $message->account        = $this->account;
        $message->messageId      = uniqid('invite-', true) . '@example.test';
        $message->subject        = 'Invitation';
        $message->fromAddress    = 'organiser@example.test';
        $message->hasAttachments = false;
        $message->receivedAt     = new DateTimeImmutable();

        $this->em->persist($message);

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

        // Read back, because the builder does not touch the inverse side.
        $this->em->refresh($message);

        return $message;
    }

    private function seed(): void
    {
        $user            = new User();
        $user->email     = 'invite-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Invite';
        $user->nameLast  = 'Fixture';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $account                 = new Account();
        $account->usr            = $user;
        $account->email          = 'invite-fixture@example.test';
        $account->username       = 'invite-fixture@example.test';
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

        $this->em->flush();

        $this->account = $account;

        $provisioner    = self::getContainer()->get(CalendarProvisioner::class);
        $this->calendar = $provisioner->defaultFor($user);
        $provisioner->forAccount($account);

        $this->em->flush();
    }
}
