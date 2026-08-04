<?php

declare(strict_types=1);

namespace App\Domain\Enum\Calendar;

/**
 * How one participant answered an invitation.
 *
 * The case values are JSCalendar's (RFC 8984 §4.4.6) rather than iCalendar's,
 * because JSCalendar is what this application stores — the value goes into
 * `jscalendar.participants[…].participationStatus` verbatim, and a stored value
 * that has to be translated on the way in is a value that will eventually be
 * stored untranslated. iCalendar's spelling is produced on the way out, where
 * an .ics reply is being written, and nowhere else.
 *
 * RFC 8984 also defines "delegated", which is deliberately not a case here.
 * Modelling it means modelling who it was delegated *to* and answering on their
 * behalf, and a case that exists but cannot be honoured is worse than an
 * unknown value: an invitation answered "delegated" reads as NeedsAction, which
 * is true — this install has not answered it.
 */
enum ParticipationStatus: string
{
    case NeedsAction = 'needs-action';
    case Accepted    = 'accepted';
    case Declined    = 'declined';
    case Tentative   = 'tentative';

    /**
     * Whatever was stored, read charitably.
     *
     * Takes iCalendar's spelling too ("NEEDS-ACTION"), because the participants
     * on an event extracted before this enum existed were written straight from
     * a PARTSTAT parameter — and an unrecognised value must read as "has not
     * answered", never as an answer nobody gave.
     */
    public static function fromJsCalendar(?string $value): self
    {
        if (null === $value) {
            return self::NeedsAction;
        }

        return self::tryFrom(mb_strtolower(trim($value))) ?? self::NeedsAction;
    }

    /** The iCalendar PARTSTAT this is written as in an .ics reply. */
    public function partStat(): string
    {
        return match ($this) {
            self::NeedsAction => 'NEEDS-ACTION',
            self::Accepted    => 'ACCEPTED',
            self::Declined    => 'DECLINED',
            self::Tentative   => 'TENTATIVE',
        };
    }

    /**
     * Whether this is a reply worth sending. NeedsAction is the absence of an
     * answer, so it is the one status the RSVP buttons cannot produce.
     */
    public function isAnswer(): bool
    {
        return self::NeedsAction !== $this;
    }

    public function label(): string
    {
        return match ($this) {
            self::NeedsAction => 'calendar.invite.status.needs_action',
            self::Accepted    => 'calendar.invite.status.accepted',
            self::Declined    => 'calendar.invite.status.declined',
            self::Tentative   => 'calendar.invite.status.tentative',
        };
    }

    /**
     * The verb on the button, which is not the noun on the chip: "Accept" is
     * what you press, "Accepted" is what it then says.
     */
    public function action(): string
    {
        return match ($this) {
            self::NeedsAction => 'calendar.invite.action.needs_action',
            self::Accepted    => 'calendar.invite.action.accepted',
            self::Declined    => 'calendar.invite.action.declined',
            self::Tentative   => 'calendar.invite.action.tentative',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::NeedsAction => 'fa-regular fa-circle-question',
            self::Accepted    => 'fa-solid fa-circle-check',
            self::Declined    => 'fa-solid fa-circle-xmark',
            self::Tentative   => 'fa-solid fa-circle-half-stroke',
        };
    }

    /**
     * The three a person can pick, in the order they are offered. Yes first:
     * it is the answer most invitations get, and the one the keyboard should
     * reach first.
     *
     * @return list<self>
     */
    public static function answers(): array
    {
        return [self::Accepted, self::Tentative, self::Declined];
    }
}
