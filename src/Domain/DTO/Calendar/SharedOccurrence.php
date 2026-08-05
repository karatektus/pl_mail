<?php

declare(strict_types=1);

namespace App\Domain\DTO\Calendar;

use DateTimeImmutable;

/**
 * One entry on a shared calendar page: the times, plus exactly what the link
 * was ticked to reveal, and nothing else.
 *
 * **This DTO is the redaction.** That is the whole design and it is worth
 * saying plainly, because the obvious alternative — handing the template the
 * occurrence and checking `link.reveals(...)` beside every field — is the one
 * that leaks. A template that forgets a check leaks; a partial that grows a
 * `title` attribute for a tooltip leaks; a JSON payload assembled for a
 * Stimulus controller leaks; an .ics built from the event leaks. None of those
 * can happen here, because the concrete data is not in the object the renderer
 * can reach. A busy/free link produces objects whose $title is null, and there
 * is no path from one of these back to the CalendarEvent it was built from.
 *
 * A `final readonly` DTO rather than an array for the reason §5.6 gives, and
 * with one extra: an array would let a caller add a key.
 *
 * $uid is synthetic and is not the event's. The real UID identifies a meeting
 * across every calendar and mailbox that holds it — that is exactly what
 * EventCopyResolver relies on — so publishing it would let anybody holding a
 * busy/free link correlate the owner's diary with an invitation they had
 * already received, which is concrete data arriving by the back door. See
 * ShareLinkReader for how it is derived and why it is still stable across
 * requests.
 */
final readonly class SharedOccurrence
{
    /**
     * @param list<string> $participants display names or addresses, empty unless
     *                                   ShareDetail::Participants was ticked
     */
    public function __construct(
        public DateTimeImmutable $startsAt,
        public DateTimeImmutable $endsAt,
        public bool              $isAllDay,
        public string            $uid,
        public ?string           $title = null,
        public ?string           $location = null,
        public ?string           $description = null,
        public array             $participants = [],
    ) {
    }

    /**
     * Whether this entry says anything beyond "occupied".
     *
     * Stays a method: it is a question about four fields together, and the
     * public template asks it to decide between a labelled row and a plain
     * busy bar. Deriving it in Twig would mean repeating the four-way check in
     * every partial that renders one of these.
     */
    public function isDetailed(): bool
    {
        return null !== $this->title
            || null !== $this->location
            || null !== $this->description
            || [] !== $this->participants;
    }
}
