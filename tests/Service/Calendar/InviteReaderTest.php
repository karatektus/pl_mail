<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar;

use App\Domain\Enum\Calendar\CalendarRole;
use App\Domain\Enum\Calendar\EventStatus;
use App\Domain\Enum\Calendar\ExtractionKind;
use App\Domain\Enum\Calendar\ParticipationStatus;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\Calendar\EventSourceLink;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Service\Calendar\Extraction\IcsEventExtractor;
use App\Service\Calendar\InviteReader;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Who is allowed to answer an invitation, and as whom.
 *
 * Every question this class answers is one the card cannot ask again later:
 * the RSVP buttons appear or they do not, and the reply that goes out is signed
 * with whichever address this decided was the reader's. Getting the second one
 * wrong is the quiet failure — a reply from an address the organiser has never
 * heard of is filed as an unknown participant, so the user sees an answer sent
 * and the organiser sees nobody respond.
 *
 * Real container, real database: what is being pinned is a read across three
 * tables and the account's own alias set, which is the part that would be
 * assumed rather than observed if any of it were doubled.
 */
final class InviteReaderTest extends KernelTestCase
{
    private const string ORGANISER = 'chair@example.org';

    private EntityManagerInterface $em;
    private Connection $connection;
    private InviteReader $reader;
    private User $user;
    private Account $account;
    private Calendar $calendar;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->reader     = $container->get(InviteReader::class);

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

    public function testAnAttendeeIsOfferedTheButtons(): void
    {
        $message = $this->invitation();

        $invite = $this->reader->forMessage($message, $this->user);

        self::assertNotNull($invite);
        self::assertTrue($invite->canRespond);
        self::assertNotNull($invite->me, 'the reader must know which participant is us');
        self::assertSame($this->address(), $invite->me->email);
        self::assertSame(ParticipationStatus::NeedsAction, $invite->myStatus());
    }

    public function testTheOrganiserIsShownTheInvitationButCannotAnswerIt(): void
    {
        $message = $this->invitation(organiser: $this->address(), attendee: 'someone@example.org');

        $invite = $this->reader->forMessage($message, $this->user);

        self::assertNotNull($invite);
        self::assertFalse(
            $invite->canRespond,
            'answering your own invitation would send a reply to yourself',
        );
    }

    /** A meeting called off is not one to accept, whichever half says so. */
    public function testACancellationOffersNoAnswer(): void
    {
        $byMethod = $this->reader->forMessage($this->invitation(method: 'CANCEL'), $this->user);

        self::assertNotNull($byMethod);
        self::assertTrue($byMethod->isCancellation);
        self::assertFalse($byMethod->canRespond);

        $this->reader->reset();

        $byStatus = $this->reader->forMessage(
            $this->invitation(status: EventStatus::Cancelled),
            $this->user,
        );

        self::assertNotNull($byStatus);
        self::assertTrue($byStatus->isCancellation);
    }

    /**
     * METHOD is supposed to be there and usually is, but senders omit it and
     * Exchange strips it on some paths. An .ics naming an organiser and listing
     * us as an attendee is an invitation whatever its envelope forgot to say.
     */
    public function testAMissingMethodIsStillAnInvitation(): void
    {
        $invite = $this->reader->forMessage($this->invitation(method: ''), $this->user);

        self::assertNotNull($invite);
        self::assertTrue($invite->canRespond);
    }

    /**
     * PUBLISH is a calendar being shared, not a question. Replying to one puts
     * mail in front of somebody who asked for nothing.
     */
    public function testAPublishedEventIsNotAnswerable(): void
    {
        $invite = $this->reader->forMessage($this->invitation(method: 'PUBLISH'), $this->user);

        self::assertNotNull($invite);
        self::assertFalse($invite->canRespond);
    }

    /** An invitation addressed to somebody else is one to read, not answer. */
    public function testAnInvitationWeAreNotOnCannotBeAnswered(): void
    {
        $invite = $this->reader->forMessage(
            $this->invitation(attendee: 'stranger@example.org'),
            $this->user,
        );

        self::assertNotNull($invite);
        self::assertNull($invite->me);
        self::assertFalse($invite->canRespond);
    }

    /**
     * The ownership check is here rather than in the controller because this
     * is reached from a template, with whatever message that template holds.
     */
    public function testAnotherUsersMessageIsNotReadAtAll(): void
    {
        $message = $this->invitation();

        self::assertNull($this->reader->forMessage($message, $this->otherUser()));
    }

    public function testAMessageCarryingNoInvitationIsNothing(): void
    {
        self::assertNull($this->reader->forMessage($this->plainMessage(), $this->user));
    }

    /** The organiser leads the list, whatever order the invite listed them in. */
    public function testTheOrganiserComesFirst(): void
    {
        $invite = $this->reader->forMessage($this->invitation(), $this->user);

        self::assertNotNull($invite);
        self::assertNotNull($invite->organiser);
        self::assertSame(self::ORGANISER, $invite->organiser->email);
        self::assertSame(self::ORGANISER, $invite->participants[0]->email);
        self::assertTrue($invite->participants[0]->isOrganiser);
    }

    /** An answer already on the invitation is the one the card must show. */
    public function testAnExistingAnswerIsRead(): void
    {
        $invite = $this->reader->forMessage(
            $this->invitation(myStatus: 'accepted'),
            $this->user,
        );

        self::assertNotNull($invite);
        self::assertSame(ParticipationStatus::Accepted, $invite->myStatus());
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function address(): string
    {
        return (string) $this->account->email;
    }

    private function invitation(
        string      $organiser = self::ORGANISER,
        ?string     $attendee = null,
        string      $method = 'REQUEST',
        EventStatus $status = EventStatus::Confirmed,
        ?string     $myStatus = null,
    ): Message {
        $attendee ??= $this->address();
        $utc        = new DateTimeZone('UTC');

        $participants = [
            mb_strtolower($organiser) => [
                '@type' => 'Participant',
                'email' => $organiser,
                'name'  => 'The Chair',
                'roles' => ['owner' => true],
            ],
            mb_strtolower($attendee) => array_filter([
                '@type'               => 'Participant',
                'email'               => $attendee,
                'roles'               => ['attendee' => true],
                'participationStatus' => $myStatus,
            ], static fn (mixed $value): bool => null !== $value),
        ];

        $event             = new CalendarEvent();
        $event->calendar   = $this->calendar;
        $event->usr        = $this->user;
        $event->uid        = uniqid('invite-', true) . '@example.org';
        $event->title      = 'Quarterly review';
        $event->startsAt   = new DateTimeImmutable('2026-06-02 09:00', $utc);
        $event->endsAt     = new DateTimeImmutable('2026-06-02 10:00', $utc);
        $event->kind       = ExtractionKind::Meeting;
        $event->status     = $status;
        $event->jscalendar = [
            '@type'        => 'Event',
            'title'        => 'Quarterly review',
            'participants' => $participants,
        ];
        $this->em->persist($event);

        $message              = new Message();
        $message->account     = $this->account;
        $message->messageId   = uniqid('invite-', true) . '@example.org';
        $message->subject     = 'Invitation: Quarterly review';
        $message->fromAddress = $organiser;
        $message->hasAttachments = false;
        $message->receivedAt  = new DateTimeImmutable('2026-06-01 08:00', $utc);
        $this->em->persist($message);

        $link            = new EventSourceLink();
        $link->event     = $event;
        $link->message   = $message;
        $link->extractor = IcsEventExtractor::NAME;
        $link->dedupKey  = 'ics:' . $event->uid;
        $link->payload   = ['method' => $method, 'uid' => $event->uid];
        $this->em->persist($link);

        $this->em->flush();
        $this->reader->reset();

        return $message;
    }

    private function plainMessage(): Message
    {
        $message              = new Message();
        $message->account     = $this->account;
        $message->messageId   = uniqid('plain-', true) . '@example.org';
        $message->subject     = 'Lunch?';
        $message->fromAddress = 'friend@example.org';
        $message->hasAttachments = false;
        $message->receivedAt  = new DateTimeImmutable('2026-06-01 08:00', new DateTimeZone('UTC'));
        $this->em->persist($message);

        $this->em->flush();
        $this->reader->reset();

        return $message;
    }

    private function otherUser(): User
    {
        $user            = new User();
        $user->email     = 'other-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Other';
        $user->nameLast  = 'Reader';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }

    private function seed(): void
    {
        $user            = new User();
        $user->email     = 'invite-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Invite';
        $user->nameLast  = 'Reader';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $account                 = new Account();
        $account->usr            = $user;
        $account->email          = 'me@example.test';
        $account->username       = 'me@example.test';
        $account->name           = 'Invite Reader';
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
        $calendar->name     = 'Invite fixture';
        $calendar->role     = CalendarRole::Account;
        $calendar->timeZone = 'UTC';
        $this->em->persist($calendar);

        $this->em->flush();

        $this->user     = $user;
        $this->account  = $account;
        $this->calendar = $calendar;
    }
}
