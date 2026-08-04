<?php

declare(strict_types=1);

namespace App\Service\Calendar\Subscription;

use App\Domain\DTO\Calendar\CalendarSource;
use App\Domain\DTO\Calendar\CalendarSubscription;
use App\Domain\Exception\CalendarSyncException;
use App\Entity\Calendar\Calendar;
use App\Repository\Calendar\CalendarRepository;
use App\Service\Calendar\CalendarSyncDriverRegistry;

/**
 * What a connection offers, and which of it plMail already mirrors.
 *
 * One step above the driver's discover(), which answers with RemoteCalendar and
 * deliberately knows nothing about this database. Pairing each remote with the
 * local row mirroring it is the question the subscribe screen actually asks —
 * the tick boxes are not "which calendars exist" but "which of them am I
 * mirroring" — and answering it in the template would mean the template holding
 * a second structure keyed by an id the interface calls opaque.
 *
 * Nothing here is cached. Discovery is a network call per open, and that is the
 * right trade: a calendar created at the provider five seconds ago should
 * appear, and a cache would have to be invalidated by an event this application
 * never sees. The screen is opened rarely and deliberately.
 *
 * Exceptions are not caught here. A CalendarSyncPermanentException from a
 * Google account whose consent screen had the calendar scope unticked carries a
 * sentence written for a person; swallowing it into an empty list would render
 * as "this account has no calendars", which is both wrong and unactionable.
 * The controller catches it and shows what it says.
 */
final readonly class CalendarDiscoverer
{
    public function __construct(
        private CalendarSyncDriverRegistry $drivers,
        private CalendarRepository         $calendars,
    ) {
    }

    /**
     * Whether anything here can talk to this source at all.
     *
     * Asked before a "find my calendars" button is drawn, so a password-only
     * IMAP account — which has no calendar API behind it in any sense — is not
     * offered one. Cheap by contract: supports() must not perform I/O.
     */
    public function supports(CalendarSource $source): bool
    {
        return $this->drivers->has($source);
    }

    /**
     * @return list<CalendarSubscription>
     *
     * @throws CalendarSyncException
     */
    public function discover(CalendarSource $source): array
    {
        $driver   = $this->drivers->for($source);
        $mirrored = $this->mirrored($source);

        $subscriptions = [];

        foreach ($driver->discover($source) as $remote) {
            $subscriptions[] = new CalendarSubscription(
                $remote,
                $mirrored[$remote->remoteId] ?? null,
            );
        }

        return $subscriptions;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * What is already mirrored from this source, keyed by the remote id it
     * mirrors — the only key the two sides share, since a name changes at the
     * provider and a local row is renameable.
     *
     * @return array<string, Calendar>
     */
    private function mirrored(CalendarSource $source): array
    {
        $existing = null !== $source->integration
            ? $this->calendars->findMirroredForIntegration($source->integration)
            : (null !== $source->account ? $this->calendars->findMirroredForAccount($source->account) : []);

        $byRemoteId = [];

        foreach ($existing as $calendar) {
            // A Remote calendar with no remoteId is one the subscribe flow
            // created and never finished binding; it belongs to no remote, so
            // it cannot claim one here.
            if (null !== $calendar->remoteId) {
                $byRemoteId[$calendar->remoteId] = $calendar;
            }
        }

        return $byRemoteId;
    }
}
