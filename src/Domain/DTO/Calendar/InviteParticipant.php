<?php

declare(strict_types=1);

namespace App\Domain\DTO\Calendar;

use App\Domain\Enum\Calendar\ParticipationStatus;

/**
 * One person on an invitation, as the card draws them.
 *
 * A flattened read of a JSCalendar Participant rather than the object itself:
 * that object is a free-form map whose keys are optional, and a template that
 * reaches into one ends up writing `participant.roles.owner is defined` — which
 * is the sort of thing that is wrong for a year before anybody notices, because
 * the wrong answer is an empty string.
 */
final readonly class InviteParticipant
{
    public function __construct(
        public string              $email,
        public ?string             $name,
        public ParticipationStatus $status,
        public bool                $isOrganiser,
        /** One of the account's own addresses — the row the RSVP buttons speak for. */
        public bool                $isMe,
    ) {
    }

    /**
     * The name if there is one, and the address when there is not.
     *
     * A method rather than the virtual property this would obviously be: PHP
     * refuses a hooked property on a readonly class, and the class being
     * readonly is the more valuable of the two.
     */
    public function displayName(): string
    {
        return null !== $this->name && '' !== $this->name ? $this->name : $this->email;
    }
}
