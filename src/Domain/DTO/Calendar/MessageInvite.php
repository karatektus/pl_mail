<?php

declare(strict_types=1);

namespace App\Domain\DTO\Calendar;

use App\Domain\Enum\Calendar\ParticipationStatus;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\Mail\Message;

/**
 * A message that is an invitation, and everything the card above it needs.
 *
 * Assembled by InviteReader because the answer is spread across three places
 * and none of them is reachable from a template: the event carries the times
 * and the participants, the source link carries the METHOD that says whether
 * this was a request or a cancellation, and the account carries the addresses
 * that decide which of those participants is the person reading.
 *
 * The event is the one this thread put on the calendar as it stands *now*, not
 * as this message described it. That distinction matters when an update has
 * since arrived: the original invite is still the message someone opens to
 * answer, and the answer belongs to the current time, not the superseded one.
 */
final readonly class MessageInvite
{
    /**
     * @param list<InviteParticipant> $participants organiser first, then attendees in the order the invite listed them
     */
    public function __construct(
        public Message             $message,
        public CalendarEvent       $event,
        public ?InviteParticipant  $organiser,
        public array               $participants,
        /** Null when none of the account's addresses is on the invitation. */
        public ?InviteParticipant  $me,
        /** METHOD:CANCEL, or an event the organiser has since called off. */
        public bool                $isCancellation,
        /**
         * Whether the RSVP buttons are offered: an invitation addressed to one
         * of this account's addresses, still standing, that somebody else sent.
         */
        public bool                $canRespond,
    ) {
    }

    /**
     * A method rather than a virtual property for the reason
     * InviteParticipant::displayName() gives: no hooks on a readonly class.
     */
    public function myStatus(): ParticipationStatus
    {
        // No `?->`: the coalesce already covers a null $me, and the two
        // together are the same expression written twice.
        return $this->me->status ?? ParticipationStatus::NeedsAction;
    }
}
