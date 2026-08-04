<?php

declare(strict_types=1);

namespace App\Service\Calendar\Sync\Google;

use App\Domain\DTO\Calendar\CalendarChangeSet;
use App\Domain\DTO\Calendar\CalendarSource;
use App\Domain\DTO\Calendar\RemoteCalendar;
use App\Domain\DTO\Calendar\RemoteWriteResult;
use App\Domain\Enum\Account\MailProvider;
use App\Domain\Exception\CalendarSyncException;
use App\Domain\Exception\CalendarSyncPermanentException;
use App\Domain\Interface\CalendarSyncDriverInterface;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\Mail\Account;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Google Calendar API v3, behind the sync driver contract.
 *
 * Rides the mail account's own OAuth grant and holds no credentials of its own:
 * MailProvider::Google::scopes() already asks for
 * https://www.googleapis.com/auth/calendar alongside the mailbox, and
 * OAuthTokenManager hands over a valid bearer token. Building a second
 * connection for calendars would mean a second OAuth app, a second consent
 * screen and a second thing to configure, to reach the same account.
 *
 * The consequence, and it is not theoretical: Google's consent screen lets a
 * user untick an individual scope, so a perfectly working mail account can hold
 * a token that every calendar endpoint refuses. That is discovered here, on the
 * first call, and reported as a permanent failure whose message names the fix —
 * see GoogleCalendarApiClient, which owns the classification.
 *
 * ── The three decisions worth arguing with ────────────────────────────────
 *
 * **Recurring events are pulled as series, not as instances.** `singleEvents`
 * stays false, so a weekly standup arrives once, carrying its RRULE, and
 * RecurrenceMaterialiser expands it locally the way it expands every other
 * recurring event. Asking Google to expand instead would turn one row into
 * hundreds, make the sync token's window meaningless, and give plMail two
 * different mechanisms for the same thing depending on where the event came
 * from. Per-instance changes then arrive as separate resources carrying
 * `recurringEventId`, and GoogleEventMapper turns each one into an override on
 * the series rather than an event of its own — see RemoteEvent. Letting them
 * through as events, which is what this driver did until that was written, put
 * a duplicate on the day the instance moved to beside a series that still drew
 * it on the day it left.
 *
 * **The first read is bounded; every read after it is not.** See
 * INITIAL_WINDOW.
 *
 * **Paging is followed here, to the end, every time.** The engine is handed one
 * complete window because `nextSyncToken` is the position after the last event
 * and Google only sends it on the final page — returning a page cursor as if it
 * were a sync position is how a delta feed silently stops halfway and never
 * catches up.
 *
 * Docs: https://developers.google.com/calendar/api/v3/reference/events/list
 */
final readonly class GoogleCalendarSyncDriver implements CalendarSyncDriverInterface
{
    /**
     * How far back the first read of a calendar goes.
     *
     * A calendar that has been in use for a decade holds tens of thousands of
     * events, and an unbounded first read fetches every one of them — dozens of
     * pages, an hour of quota, and a local table full of meetings from 2016
     * that no view will ever show. A year covers "what happened recently",
     * which is the only backward-looking question a calendar is actually asked,
     * and the forward direction is deliberately unbounded because next year's
     * appointments are the entire point.
     *
     * The cost is honest and worth stating: an event older than this window is
     * never learned about, and a full resync re-establishes the same window
     * rather than a wider one. A recurring series that started before it still
     * arrives, because Google matches a series on its instances rather than on
     * its first one.
     */
    private const string INITIAL_WINDOW = '-1 year';

    /**
     * Events per page. Google's ceiling is 2500 and its default is 250; the
     * default is kept, because a page is held in memory whole and the number of
     * round trips is not what makes a sync slow.
     */
    private const int PAGE_SIZE = 250;

    /**
     * The access roles that can be written to. A calendar is read-only unless
     * it is one of these — stated as an allow-list rather than as "reader and
     * freeBusyReader mean read-only", so a role Google adds later is treated as
     * unwritable until somebody decides otherwise. Guessing the other way means
     * pushing at a calendar that will refuse it, once per sweep, forever.
     *
     * @var list<string>
     */
    private const array WRITABLE_ACCESS_ROLES = ['owner', 'writer'];

    public function __construct(
        private GoogleCalendarApiClient $api,
        private GoogleEventMapper       $mapper,
    ) {
    }

    public function supports(CalendarSource $source): bool
    {
        return MailProvider::Google === $source->mailProvider();
    }

    /**
     * Every calendar in the account's list, unfiltered.
     *
     * `hidden` and `selected` are deliberately ignored: they are how the user
     * arranged Google's own sidebar, and a calendar they collapsed there is not
     * a calendar they refuse to mirror here. The subscribe screen is where that
     * choice is made, and pre-making it invisibly would leave a user hunting
     * for a calendar plMail decided not to mention.
     *
     * @return list<RemoteCalendar>
     *
     * @throws CalendarSyncException
     */
    public function discover(CalendarSource $source): array
    {
        $account   = $this->accountOf($source);
        $calendars = [];
        $pageToken = null;

        do {
            $query = ['maxResults' => self::PAGE_SIZE];

            if (null !== $pageToken) {
                $query['pageToken'] = $pageToken;
            }

            $body = $this->api->get($account, '/users/me/calendarList', $query, 'calendarList.list');

            foreach ($this->itemsOf($body) as $item) {
                $calendar = $this->toRemoteCalendar($item);

                if (null !== $calendar) {
                    $calendars[] = $calendar;
                }
            }

            $pageToken = $this->nextPage($body, $pageToken, 'calendarList.list');
        } while (null !== $pageToken);

        return $calendars;
    }

    /**
     * @throws CalendarSyncException
     */
    public function pull(Calendar $calendar, ?string $syncToken): CalendarChangeSet
    {
        $account   = $this->accountOf(CalendarSource::ofCalendar($calendar));
        $path      = $this->eventsPath($calendar);
        $fullRead  = null === $syncToken;
        $events    = [];
        $pageToken = null;
        $nextToken = null;

        do {
            $body = $this->api->get($account, $path, $this->pullQuery($syncToken, $pageToken), 'events.list');

            foreach ($this->itemsOf($body) as $item) {
                $event = $this->mapper->toRemoteEvent($item, $calendar->timeZone);

                if (null === $event) {
                    continue;
                }

                // A full read must carry no tombstones — the engine treats it
                // as authoritative and removes whatever it does not mention, so
                // a tombstone in it is at best noise.
                //
                // A cancelled *instance* is deliberately not one of them, and
                // this is where it matters most: Google returns them even with
                // showDeleted=false while singleEvents is false, and they are
                // the only statement anywhere that an occurrence of a live
                // series is off. Dropped here, a full resync would resurrect
                // every instance the user had ever cancelled.
                if (true === $fullRead && true === $event->isDeleted && false === $event->isSeriesInstance()) {
                    continue;
                }

                $events[] = $event;
            }

            // Only the last page carries one, so the last one seen is the
            // position after the whole window.
            $nextToken = $this->stringOrNull($body['nextSyncToken'] ?? null) ?? $nextToken;
            $pageToken = $this->nextPage($body, $pageToken, 'events.list');
        } while (null !== $pageToken);

        return new CalendarChangeSet($events, $nextToken);
    }

    /**
     * @throws CalendarSyncException
     */
    public function push(Calendar $calendar, CalendarEvent $event): RemoteWriteResult
    {
        $account  = $this->accountOf(CalendarSource::ofCalendar($calendar));
        $payload  = $this->mapper->toGoogleEvent($event);
        $remoteId = $event->remoteId;

        if (null === $remoteId) {
            // No iCalUID is sent with the create, because events.insert does not
            // accept one — only events.import does, and that is for copying an
            // event that already exists elsewhere. Google mints its own, and the
            // next pull writes it over the placeholder this row was created
            // with; the row is found by remote id, so the change of UID costs
            // nothing.
            $body = $this->api->write($account, 'POST', $this->eventsPath($calendar), $payload, null, 'events.insert');

            return $this->writeResult($body, 'events.insert');
        }

        // PATCH rather than PUT: an update must not erase the parts of a Google
        // event plMail does not model — reminders, conference details, guest
        // permissions, the event's colour. A PUT sends the whole resource, so
        // everything absent from this payload would be cleared, and a user who
        // fixed a typo in a title would lose the Meet link on the meeting.
        //
        // If-Match carries the etag this edit was made against. Without it a
        // change somebody else made in between is overwritten silently and they
        // never learn it is gone; with it Google answers 412 and the engine
        // re-reads instead.
        $body = $this->api->write(
            $account,
            'PATCH',
            $this->eventPath($calendar, $remoteId),
            $payload,
            $event->remoteEtag,
            'events.patch',
        );

        return $this->writeResult($body, 'events.patch');
    }

    /**
     * @throws CalendarSyncException
     */
    public function delete(Calendar $calendar, CalendarEvent $event): void
    {
        $remoteId = $event->remoteId;

        if (null === $remoteId) {
            return;
        }

        // No If-Match here, deliberately. The contract says a delete is
        // idempotent and an event already gone is a success; adding a version
        // check would turn "somebody else edited it first" into a failure of a
        // deletion that is going to happen anyway, and leave the local row
        // stuck in PendingDelete re-attempting it on every sweep.
        $this->api->delete(
            $this->accountOf(CalendarSource::ofCalendar($calendar)),
            $this->eventPath($calendar, $remoteId),
            'events.delete',
        );
    }

    // ── The listing ───────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $item one calendarList entry
     */
    private function toRemoteCalendar(array $item): ?RemoteCalendar
    {
        $remoteId = $this->stringOrNull($item['id'] ?? null);

        if (null === $remoteId) {
            return null;
        }

        // summaryOverride is the name this user gave the calendar, and summary
        // is the name its owner gave it. Showing the owner's name for a
        // calendar the user has renamed is showing them a calendar they do not
        // recognise.
        $name = $this->stringOrNull($item['summaryOverride'] ?? null)
            ?? $this->stringOrNull($item['summary'] ?? null)
            ?? $remoteId;

        $accessRole = (string) $this->stringOrNull($item['accessRole'] ?? null);

        return new RemoteCalendar(
            remoteId:   $remoteId,
            name:       $name,
            color:      $this->hexColorOrNull($item['backgroundColor'] ?? null),
            timeZone:   $this->stringOrNull($item['timeZone'] ?? null),
            isReadOnly: false === in_array($accessRole, self::WRITABLE_ACCESS_ROLES, true),
            isPrimary:  true === ($item['primary'] ?? false),
        );
    }

    /**
     * Calendar::$color is a seven-character column and every reader assumes
     * #rrggbb, so a colour in any other shape is no colour at all — the
     * provisioner then picks from the palette, which is a better outcome than a
     * truncated string that renders as nothing.
     */
    private function hexColorOrNull(mixed $color): ?string
    {
        $color = $this->stringOrNull($color);

        if (null === $color || 1 !== preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            return null;
        }

        return $color;
    }

    // ── Requests ──────────────────────────────────────────────────────────

    /**
     * @return array<string,string|int>
     */
    private function pullQuery(?string $syncToken, ?string $pageToken): array
    {
        $query = [
            'maxResults'   => self::PAGE_SIZE,
            // Series, not instances — see the class docblock.
            'singleEvents' => 'false',
        ];

        if (null !== $syncToken) {
            $query['syncToken']   = $syncToken;
            $query['showDeleted'] = 'true';
        } else {
            // timeMin is incompatible with syncToken and Google rejects the
            // pair outright, which is the other half of why the window is only
            // ever established on the first read.
            $query['showDeleted'] = 'false';
            $query['timeMin']     = new DateTimeImmutable(self::INITIAL_WINDOW, new DateTimeZone('UTC'))
                ->format(DateTimeInterface::RFC3339);
        }

        if (null !== $pageToken) {
            $query['pageToken'] = $pageToken;
        }

        return $query;
    }

    /**
     * The next page to ask for, or null when the window is complete.
     *
     * A page token identical to the one just used is refused rather than
     * followed. Nothing in the API is supposed to do that, and a loop that does
     * it asks the same page forever — a sweep that never returns, holding a
     * worker and a database transaction, is far harder to notice than a
     * calendar that failed.
     *
     * @param array<string,mixed> $body
     *
     * @throws CalendarSyncException
     */
    private function nextPage(array $body, ?string $current, string $operation): ?string
    {
        $next = $this->stringOrNull($body['nextPageToken'] ?? null);

        if (null !== $next && $next === $current) {
            throw new CalendarSyncException(sprintf(
                'Google Calendar %s keeps answering with the same page, so the listing was stopped.',
                $operation,
            ));
        }

        return $next;
    }

    /**
     * @param array<string,mixed> $body
     *
     * @return list<array<string,mixed>>
     */
    private function itemsOf(array $body): array
    {
        $items = $body['items'] ?? null;

        if (false === is_array($items)) {
            return [];
        }

        $rows = [];

        foreach ($items as $item) {
            if (true === is_array($item)) {
                $rows[] = $item;
            }
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $body
     *
     * @throws CalendarSyncException
     */
    private function writeResult(array $body, string $operation): RemoteWriteResult
    {
        $remoteId = $this->stringOrNull($body['id'] ?? null);

        if (null === $remoteId) {
            // Accepting a write whose id we did not learn is worse than failing
            // it: the event exists at Google, the local row still looks
            // unsynced, and the next push creates a second copy of the same
            // meeting.
            throw new CalendarSyncException(sprintf(
                'Google Calendar %s answered without an event id, so the write could not be recorded.',
                $operation,
            ));
        }

        return new RemoteWriteResult($remoteId, $this->stringOrNull($body['etag'] ?? null));
    }

    private function eventsPath(Calendar $calendar): string
    {
        return sprintf('/calendars/%s/events', rawurlencode($this->calendarIdOf($calendar)));
    }

    private function eventPath(Calendar $calendar, string $remoteId): string
    {
        return sprintf(
            '/calendars/%s/events/%s',
            rawurlencode($this->calendarIdOf($calendar)),
            rawurlencode($remoteId),
        );
    }

    /**
     * @throws CalendarSyncPermanentException
     */
    private function calendarIdOf(Calendar $calendar): string
    {
        $remoteId = $calendar->remoteId;

        if (null === $remoteId || '' === $remoteId) {
            throw new CalendarSyncPermanentException(
                'This calendar has no Google calendar behind it, so there is nothing to sync it with.',
            );
        }

        return $remoteId;
    }

    /**
     * The account whose grant this driver borrows.
     *
     * Permanent rather than a LogicException, although reaching it means the
     * registry handed over a source this driver never claimed: everything
     * crossing this boundary is a CalendarSyncException by contract, and a
     * driver that throws something else takes down the sweep instead of the one
     * calendar it belongs to.
     *
     * @throws CalendarSyncPermanentException
     */
    private function accountOf(?CalendarSource $source): Account
    {
        $account = $source?->account;

        if (null === $account) {
            throw new CalendarSyncPermanentException(
                'This calendar is not connected to a Google account.',
            );
        }

        return $account;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (false === is_string($value) || '' === $value) {
            return null;
        }

        return $value;
    }
}
