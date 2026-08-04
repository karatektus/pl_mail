<?php

declare(strict_types=1);

namespace App\Tests\Support\Calendar;

use App\Domain\DTO\Calendar\CalendarChangeSet;
use App\Domain\DTO\Calendar\CalendarSource;
use App\Domain\DTO\Calendar\RemoteCalendar;
use App\Domain\DTO\Calendar\RemoteWriteResult;
use App\Domain\Exception\CalendarSyncPermanentException;
use App\Domain\Interface\CalendarSyncDriverInterface;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\Integration\Integration;

/**
 * A calendar service whose answers are written on the connection itself.
 *
 * Registered only in the test environment (config/services_test.yaml) and
 * claims only connections carrying SETTINGS_KEY, so nothing else in either
 * suite can reach it — a CalDAV connection made by a person still goes to
 * CalDavCalendarDriver, in the test environment as everywhere else.
 *
 * It exists because subscribing cannot be exercised end to end any other way.
 * The seam the engine was designed to have is CalendarSyncDriverInterface, and
 * FakeCalendarSyncDriver is the object-level version of this, handed to a
 * service constructed by hand. That works for a service test and is useless to
 * a browser: Playwright drives the real HTTP stack, which builds its
 * collaborators from the real container, so the only way to control what a
 * remote says from a spec is for the container to hold a driver a fixture can
 * script. The script lives in Integration::$settings because that is the one
 * piece of state a console command can write and an HTTP request can read
 * without either of them knowing about the other.
 *
 * The alternative — pointing a spec at a real CalDAV server, or at a stub HTTP
 * server inside the test stack — was rejected twice over: CalDavClient
 * validates every URL it is handed and refuses private addresses, which is the
 * SSRF guard and must not be weakened for a test; and a spec whose green
 * depends on a second server is a spec that goes red for reasons that have
 * nothing to do with plMail.
 */
final class ScriptedCalendarSyncDriver implements CalendarSyncDriverInterface
{
    /** Present and true on a connection this driver speaks for. */
    public const string SETTINGS_KEY = 'calendar.scripted';

    /** What discover() throws instead of answering, when set. */
    public const string SETTINGS_ERROR = 'calendar.scripted.error';

    /**
     * The calendars discover() answers with: a list of maps carrying
     * `remoteId`, `name`, and optionally `color`, `readOnly` and `primary`.
     */
    public const string SETTINGS_CALENDARS = 'calendar.scripted.calendars';

    public function supports(CalendarSource $source): bool
    {
        return null !== $this->scriptedIntegration($source);
    }

    public function discover(CalendarSource $source): array
    {
        $integration = $this->scriptedIntegration($source);

        if (null === $integration) {
            return [];
        }

        $error = $integration->getSetting(self::SETTINGS_ERROR);

        if (true === is_string($error) && '' !== $error) {
            throw new CalendarSyncPermanentException($error);
        }

        $calendars = $integration->getSetting(self::SETTINGS_CALENDARS, []);
        $remotes   = [];

        foreach (true === is_array($calendars) ? $calendars : [] as $entry) {
            if (false === is_array($entry) || false === is_string($entry['remoteId'] ?? null)) {
                continue;
            }

            $remotes[] = new RemoteCalendar(
                remoteId:   $entry['remoteId'],
                name:       is_string($entry['name'] ?? null) ? $entry['name'] : $entry['remoteId'],
                color:      is_string($entry['color'] ?? null) ? $entry['color'] : null,
                timeZone:   is_string($entry['timeZone'] ?? null) ? $entry['timeZone'] : null,
                isReadOnly: true === ($entry['readOnly'] ?? false),
                isPrimary:  true === ($entry['primary'] ?? false),
            );
        }

        return $remotes;
    }

    /** Nothing ever changes, which is the only answer a fixture can promise. */
    public function pull(Calendar $calendar, ?string $syncToken): CalendarChangeSet
    {
        return CalendarChangeSet::unchanged($syncToken);
    }

    public function push(Calendar $calendar, CalendarEvent $event): RemoteWriteResult
    {
        return new RemoteWriteResult('scripted-' . $event->uid, 'scripted-etag');
    }

    public function delete(Calendar $calendar, CalendarEvent $event): void
    {
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function scriptedIntegration(CalendarSource $source): ?Integration
    {
        $integration = $source->integration;

        if (null === $integration) {
            return null;
        }

        return true === $integration->getSetting(self::SETTINGS_KEY) ? $integration : null;
    }
}
