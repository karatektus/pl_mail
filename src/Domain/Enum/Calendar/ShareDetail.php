<?php

declare(strict_types=1);

namespace App\Domain\Enum\Calendar;

/**
 * One thing a shared calendar link may reveal beyond "there is something here".
 *
 * Busy/free is the floor and is not a case: a link that revealed nothing at all
 * would be a link with no reason to exist, so every link says when its owner is
 * busy and this enum is the list of what can be added on top of that. That is
 * why there is no `BusyFree` case — it is the absence of every case below, and
 * a case for it would make the empty set expressible twice.
 *
 * Stored on the link as a jsonb list of these values rather than as four
 * boolean columns. The set is what a person ticks, it is read whole every time
 * it is read at all, and nothing queries "which links reveal titles" — so a
 * column per case would buy an index nobody asks for and cost a migration the
 * next time somebody wants to share the organiser too. An unknown value read
 * back from an older or newer install is dropped by tryFrom(), which is the
 * safe direction: a detail plMail does not recognise stays hidden.
 *
 * The rule that makes this safe is on the redactor, not here: a public page is
 * rendered from a DTO carrying only what these cases unlocked, never from the
 * event. See App\Service\Calendar\Sharing\ShareLinkReader — the concrete data
 * is not in the object the template can reach, so a template that forgets to
 * check cannot leak it.
 */
enum ShareDetail: string
{
    case Title        = 'title';
    case Location     = 'location';
    case Description  = 'description';
    case Participants = 'participants';

    /**
     * The JSCalendar property this case unlocks (RFC 8984 §4.2).
     *
     * Exhaustive with no default, so a fifth detail cannot be added without
     * somebody deciding where in the canonical object it comes from — the one
     * question that has to be answered before it can be revealed at all.
     * Locations and participants are maps in JSCalendar rather than scalars;
     * the reader flattens them, and the name here is the key it reaches for.
     */
    public function jsCalendarProperty(): string
    {
        return match ($this) {
            self::Title        => 'title',
            self::Location     => 'locations',
            self::Description  => 'description',
            self::Participants => 'participants',
        };
    }

    /** Translation key for the checkbox label. */
    public function transKey(): string
    {
        return 'calendar.share.detail.' . $this->value;
    }

    /**
     * The cases named by a posted set of values, in declaration order.
     *
     * Declaration order rather than posted order, so two links ticking the same
     * boxes store the same list and the settings summary reads the same way
     * whichever order the browser happened to serialise the form in.
     *
     * @param array<mixed> $values as posted or as stored, so entirely untrusted
     *
     * @return list<self>
     */
    public static function fromList(array $values): array
    {
        $wanted = [];

        foreach ($values as $value) {
            if (true === is_string($value)) {
                $wanted[] = $value;
            }
        }

        $details = [];

        foreach (self::cases() as $case) {
            if (true === in_array($case->value, $wanted, true)) {
                $details[] = $case;
            }
        }

        return $details;
    }
}
