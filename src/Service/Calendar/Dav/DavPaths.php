<?php

declare(strict_types=1);

namespace App\Service\Calendar\Dav;

use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\User\User;

/**
 * The URL space the CalDAV server exposes, in one place.
 *
 * Five shapes, and clients walk them in order — the root says where the
 * principal is, the principal says where the calendars are, the home-set lists
 * the collections, and a collection lists its resources. Discovery is the whole
 * protocol at the start of a connection, so every href a response emits has to
 * agree with every href another response emitted, down to the trailing slash:
 *
 *   /caldav/                                     the root
 *   /caldav/principals/{user}/                   who you are
 *   /caldav/calendars/{user}/                    calendar-home-set
 *   /caldav/calendars/{user}/{calendar}/         one collection
 *   /caldav/calendars/{user}/{calendar}/{uid}.ics  one event
 *
 * Trailing slashes on collections are not decoration. A client that is told a
 * collection lives at a slashless href resolves relative hrefs inside it
 * against the parent, and every resource in the listing comes out one level too
 * high — which presents as an empty calendar rather than as an error.
 *
 * The resource name is the event's UID, which is the convention every server
 * follows and the one clients assume when they PUT something new: they choose a
 * filename, put the same string in the VEVENT's UID, and expect a later listing
 * to agree. Percent-encoded because a UID is opaque text — Google's carry no
 * punctuation, but an imported one can carry anything, and an unencoded `/`
 * would invent a collection that does not exist.
 */
final readonly class DavPaths
{
    public const string PREFIX = '/caldav';

    public function root(): string
    {
        return self::PREFIX . '/';
    }

    public function principal(User $user): string
    {
        return sprintf('%s/principals/%d/', self::PREFIX, $user->id);
    }

    public function home(User $user): string
    {
        return sprintf('%s/calendars/%d/', self::PREFIX, $user->id);
    }

    public function collection(Calendar $calendar): string
    {
        return sprintf('%s/calendars/%d/%d/', self::PREFIX, $calendar->usr?->id, $calendar->id);
    }

    public function resource(Calendar $calendar, CalendarEvent $event): string
    {
        return $this->collection($calendar) . $this->resourceName($event->uid);
    }

    /** The filename an event is served under, encoded for a URL path segment. */
    public function resourceName(string $uid): string
    {
        return rawurlencode($uid) . '.ics';
    }

    /**
     * The UID a request path segment names.
     *
     * Tolerates a missing .ics: some clients PUT to the name they were given in
     * a listing and some to the bare UID, and both mean the same resource.
     */
    public function uidFromName(string $name): string
    {
        $name = rawurldecode($name);

        if (true === str_ends_with(strtolower($name), '.ics')) {
            return substr($name, 0, -4);
        }

        return $name;
    }
}
