<?php

declare(strict_types=1);

namespace App\Tests\Service\Calendar;

use App\Domain\DTO\Calendar\InviteParticipant;
use App\Domain\DTO\Calendar\MessageInvite;
use App\Domain\Enum\Calendar\ParticipationStatus;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\Mail\Message;
use App\Service\Calendar\ItipReplyBuilder;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mime\Email;

/**
 * An RSVP is a message on the wire, and RFC 5546 says what it must contain.
 *
 * Every claim here is one the user cannot check and would never be told about:
 * a reply whose UID does not match, whose SEQUENCE is wrong, or that arrives
 * without `method=REPLY` on its part is delivered, filed as an attachment, and
 * silently not processed. The sender sees an answer sent; the organiser sees
 * nobody respond.
 *
 * Assertions run against the serialised MIME rather than the object graph
 * wherever the wire format is the claim — the Content-Type parameter in
 * particular, which is set through a Symfony seam that has no argument for it
 * and would be lost by a refactor no unit assertion on the DataPart would
 * notice.
 */
final class ItipReplyBuilderTest extends KernelTestCase
{
    private ItipReplyBuilder $builder;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->builder = self::getContainer()->get(ItipReplyBuilder::class);
    }

    public function testTheReplyIsAddressedToTheOrganiserFromTheInvitedAddress(): void
    {
        $email = $this->build(ParticipationStatus::Accepted);

        self::assertNotNull($email);
        self::assertSame('chair@example.org', $email->getTo()[0]->getAddress());
        self::assertSame(
            'me@example.test',
            $email->getFrom()[0]->getAddress(),
            'the organiser matches a reply to an attendee row by address',
        );
    }

    public function testTheSubjectLeadsWithTheAnswer(): void
    {
        self::assertSame('Accepted: Quarterly review', $this->build(ParticipationStatus::Accepted)?->getSubject());
        self::assertSame('Declined: Quarterly review', $this->build(ParticipationStatus::Declined)?->getSubject());
    }

    public function testTheCalendarPartDeclaresTheReplyMethod(): void
    {
        $mime = (string) $this->build(ParticipationStatus::Accepted)?->toString();

        self::assertStringContainsString('text/calendar', $mime);
        self::assertMatchesRegularExpression(
            '/Content-Type: text\/calendar;[^\r\n]*method=REPLY/i',
            $mime,
            'without method=REPLY a strict organiser never processes the answer',
        );
    }

    public function testTheReplyEchoesTheSendersIdentity(): void
    {
        $ics = $this->ics(ParticipationStatus::Accepted);

        self::assertStringContainsString('METHOD:REPLY', $ics);
        self::assertStringContainsString('UID:quarterly-review@example.org', $ics);
        self::assertStringContainsString('SEQUENCE:3', $ics);
    }

    /**
     * One ATTENDEE, and it is ours. A reply listing the whole invitee list is a
     * claim to have answered for all of them, and organisers that trust it tick
     * off people who never responded.
     */
    public function testExactlyOneAttendeeIsAnswered(): void
    {
        $ics = $this->ics(ParticipationStatus::Tentative);

        self::assertSame(1, substr_count($ics, 'ATTENDEE'));
        self::assertStringContainsString('PARTSTAT=TENTATIVE', $ics);
        self::assertStringContainsString('mailto:me@example.test', $ics);
        self::assertStringContainsString('ORGANIZER', $ics);
    }

    public function testEachAnswerCarriesItsOwnPartStat(): void
    {
        self::assertStringContainsString('PARTSTAT=ACCEPTED', $this->ics(ParticipationStatus::Accepted));
        self::assertStringContainsString('PARTSTAT=DECLINED', $this->ics(ParticipationStatus::Declined));
    }

    /**
     * A date-time in a reply to an all-day event shifts it by the offset at the
     * far end, which is how a whole-day booking arrives on the wrong day.
     */
    public function testAnAllDayEventIsAnsweredWithDateValues(): void
    {
        $ics = $this->ics(ParticipationStatus::Accepted, allDay: true);

        self::assertStringContainsString('DTSTART;VALUE=DATE:20260602', $ics);
        self::assertStringContainsString('DTEND;VALUE=DATE:20260603', $ics);
    }

    public function testATimedEventIsAnsweredInUtc(): void
    {
        self::assertStringContainsString('DTSTART:20260602T090000Z', $this->ics(ParticipationStatus::Accepted));
    }

    /** The organiser's own message is what a reply threads under. */
    public function testTheReplyThreadsUnderTheInvitation(): void
    {
        $mime = (string) $this->build(ParticipationStatus::Accepted)?->toString();

        self::assertStringContainsString('In-Reply-To: <invite-4471@example.org>', $mime);
    }

    /**
     * An .ics with no ORGANIZER is a calendar entry sent for information.
     * Answering it puts mail in front of somebody who asked no question.
     */
    public function testAnInvitationWithNoOrganiserProducesNoReply(): void
    {
        self::assertNull($this->build(ParticipationStatus::Accepted, organiser: null));
    }

    /** "Has not answered" is not an answer, and must not become mail. */
    public function testNeedsActionIsNotSendable(): void
    {
        self::assertNull($this->build(ParticipationStatus::NeedsAction));
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function ics(ParticipationStatus $status, bool $allDay = false): string
    {
        $email = $this->build($status, allDay: $allDay);

        self::assertNotNull($email);

        return (string) $email->getAttachments()[0]->getBody();
    }

    private function build(
        ParticipationStatus $status,
        ?string             $organiser = 'chair@example.org',
        bool                $allDay = false,
    ): ?Email {
        return $this->builder->build($this->invite($organiser, $allDay), $status, 'Invite Reader');
    }

    private function invite(?string $organiser, bool $allDay): MessageInvite
    {
        $utc = new DateTimeZone('UTC');

        $event           = new CalendarEvent();
        $event->uid      = 'quarterly-review@example.org';
        $event->sequence = 3;
        $event->title    = 'Quarterly review';
        $event->isAllDay = $allDay;
        $event->timeZone = true === $allDay ? null : 'UTC';
        $event->startsAt = new DateTimeImmutable('2026-06-02 09:00', $utc);
        $event->endsAt   = true === $allDay
            ? new DateTimeImmutable('2026-06-03 00:00', $utc)
            : new DateTimeImmutable('2026-06-02 10:00', $utc);

        $message            = new Message();
        $message->messageId = 'invite-4471@example.org';
        $message->subject   = 'Invitation: Quarterly review';

        $chair = null === $organiser ? null : new InviteParticipant(
            email:       $organiser,
            name:        'The Chair',
            status:      ParticipationStatus::Accepted,
            isOrganiser: true,
            isMe:        false,
        );

        $me = new InviteParticipant(
            email:       'me@example.test',
            name:        'Invite Reader',
            status:      ParticipationStatus::NeedsAction,
            isOrganiser: false,
            isMe:        true,
        );

        return new MessageInvite(
            message:        $message,
            event:          $event,
            organiser:      $chair,
            participants:   null === $chair ? [$me] : [$chair, $me],
            me:             $me,
            isCancellation: false,
            canRespond:     true,
        );
    }
}
