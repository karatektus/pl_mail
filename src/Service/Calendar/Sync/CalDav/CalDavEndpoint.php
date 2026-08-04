<?php

declare(strict_types=1);

namespace App\Service\Calendar\Sync\CalDav;

/**
 * Where a connection's calendars were found, and how.
 *
 * Two outcomes, because the two ways a person configures CalDAV are genuinely
 * different questions and collapsing them loses the difference:
 *
 *   A **home** is what bootstrapping finds — a collection whose children are
 *   calendars. Listing it with Depth: 1 is what discovery is for, and a
 *   calendar added at the server later appears on the next look.
 *
 *   A **collection** is what a pasted URL usually is: someone copied the
 *   address of one calendar out of another client. There is nothing to list;
 *   that URL *is* the answer, and walking up to its parent to find siblings
 *   would offer a user calendars they did not ask for and, on a shared server,
 *   possibly somebody else's.
 *
 * Not a Domain DTO. It never leaves this directory — the driver turns it into
 * RemoteCalendar, which is the shape that crosses the boundary — and putting it
 * in Domain/DTO/Calendar would put "principal" and "calendar home" into the
 * vocabulary the engine reads, which is precisely the CalDAV concept
 * CalendarSyncDriverInterface exists to keep out.
 */
final readonly class CalDavEndpoint
{
    /**
     * @param string|null $calendarHome absolute URL of the collection whose
     *                                  children are calendars, null when a
     *                                  single collection was named outright
     * @param string|null $collection   absolute URL of the one calendar, null
     *                                  when there is a home to list
     * @param string|null $principal    absolute URL of the current user's
     *                                  principal, kept for the error messages
     *                                  and for anything later that needs a
     *                                  calendar-user-address; never null when
     *                                  bootstrapping found one
     */
    private function __construct(
        public ?string $calendarHome,
        public ?string $collection,
        public ?string $principal,
    ) {
    }

    public static function home(string $calendarHome, ?string $principal = null): self
    {
        return new self($calendarHome, null, $principal);
    }

    public static function collection(string $collection): self
    {
        return new self(null, $collection, null);
    }

    public function isSingleCollection(): bool
    {
        return null !== $this->collection;
    }
}
