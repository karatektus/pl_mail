<?php

declare(strict_types=1);

namespace App\Service\Calendar\Sync\IcsUrl;

use App\Domain\DTO\Calendar\CalendarChangeSet;
use App\Domain\DTO\Calendar\CalendarSource;
use App\Domain\DTO\Calendar\IcsFeedResponse;
use App\Domain\DTO\Calendar\RemoteCalendar;
use App\Domain\DTO\Calendar\RemoteEvent;
use App\Domain\DTO\Calendar\RemoteWriteResult;
use App\Domain\Enum\Integration\Provider;
use App\Domain\Exception\CalendarSyncException;
use App\Domain\Exception\CalendarSyncPermanentException;
use App\Domain\Exception\IntegrationException;
use App\Domain\Interface\CalendarSyncDriverInterface;
use App\Domain\Interface\VerifiableDriverInterface;
use App\Entity\Calendar\Calendar;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\Integration\Integration;
use App\Service\Calendar\Ics\IcsDocumentReader;
use App\Service\Calendar\Sync\CalDav\CalDavEventConverter;
use Psr\Log\LoggerInterface;

/**
 * A calendar published as a file at an address — the fourth driver, and the
 * only one whose remote cannot answer a question.
 *
 * A holiday feed, a league's fixture list, the "secret address in iCal format"
 * Google and Outlook hand out for a calendar they will not grant API access to,
 * a colleague's published availability. Every one of them is a static .ics
 * behind an HTTP GET, which makes this the simplest driver here and the one
 * with the least to work with: there is no delta feed, no change token, no
 * per-event resource id, no way to create anything and nobody to ask for
 * permission.
 *
 * ── What stands in for the things a feed does not have ────────────────────
 *
 * **Identity is the UID.** Every other driver has provider ids for its
 * resources; a file has only what RFC 5545 gives it. So a RemoteEvent's
 * $remoteId here IS its UID, which is legitimate under the contract — ids are
 * opaque and only ever compared for equality — and has a real payoff: the
 * meeting an invitation already put on another calendar is recognisable,
 * because CalendarPuller's fallback lookup matches on exactly that.
 *
 * **Change detection is HTTP's.** ETag and Last-Modified from the last
 * successful read are stored, together, in Calendar::$syncToken and presented
 * on the next poll as If-None-Match and If-Modified-Since. An unchanged
 * calendar is a 304 with no body, which is what makes polling a holiday feed
 * every fifteen minutes cost almost nothing. The token is opaque above this
 * class, so packing two values into it is this driver's business alone.
 *
 * **A change surrenders the token instead of reporting a window**, and this is
 * the one decision here worth arguing about. A feed states what exists; it says
 * nothing about what was removed. The engine only treats a listing as
 * authoritative — deleting local rows the listing did not mention — when it
 * asked with a null token, so returning the listing against a live token would
 * apply every edit and keep every cancelled fixture forever. So a poll that
 * finds the feed changed answers CalendarChangeSet::resyncRequired(), the
 * engine clears the token and pulls again from scratch, and the second read
 * carries the whole truth. Two downloads per actual change, none at all in
 * between, in exchange for a calendar that does not accumulate ghosts. It is
 * the same trade CalDavCalendarDriver's ctag fallback makes, for the same
 * reason, and the two are deliberately spelled the same way.
 *
 * ── Read-only is a fact, not a setting ────────────────────────────────────
 *
 * isReadOnly is hard-coded true on the RemoteCalendar this discovers. Every
 * other driver asks — CalDAV reads current-user-privilege-set, Google reads
 * accessRole — because their remotes have an opinion. A file at a URL does not:
 * there is no method that would write to it and no server that would accept
 * one. push() and delete() therefore throw rather than doing nothing quietly.
 * The engine promises never to call them (CalendarSyncService checks
 * Calendar::$isReadOnly before pushing), so reaching either is a bug in the
 * engine or a hand-edited row, and both are worth a loud failure — silence
 * there would present as edits that vanish on the next sweep with no trace.
 *
 * ── No push channel ───────────────────────────────────────────────────────
 *
 * Deliberately no CalendarPushSubscriptionManagerInterface. There is nothing to
 * register with: the far end is a file. CalendarPushRegistry answers null for
 * an unclaimed calendar and every caller skips quietly, so a feed is polled by
 * the ordinary fifteen-minute sweep and nothing else has to learn it exists.
 */
final readonly class IcsUrlCalendarDriver implements CalendarSyncDriverInterface, VerifiableDriverInterface
{
    /**
     * The remote id of the one calendar behind a feed.
     *
     * A feed is one calendar, so there is nothing to distinguish, and the
     * obvious id — the URL — is a bad one twice over: Calendar::$remoteId is
     * 255 characters and a feed address with a signed query string exceeds that
     * routinely, and an address the user later corrects would orphan the
     * calendar mirroring it. The address lives on the Integration, where it can
     * be edited without the calendar losing its identity.
     */
    public const string REMOTE_ID = 'feed';

    /**
     * What separates the two validators inside the sync token.
     *
     * A unit separator rather than a pipe or a comma: an ETag is an opaque
     * quoted string chosen by somebody else's server and may legally contain
     * any printable character, so a delimiter has to be one that cannot occur
     * in a header value at all. RFC 9110 §5.5 forbids control characters in
     * field values, which is exactly what makes this one safe.
     */
    private const string TOKEN_SEPARATOR = "\x1f";

    public function __construct(
        private IcsFeedClient        $client,
        private IcsUrlNormaliser     $normaliser,
        private IcsDocumentReader    $reader,
        private CalDavEventConverter $converter,
        private LoggerInterface      $logger,
    ) {
    }

    /**
     * One method for two interfaces, by widening the parameter to the union of
     * theirs — the same trick CalDavCalendarDriver plays, and for the same
     * reason: the alternative is a second class whose only content is that two
     * interfaces chose the same verb. No default arm, so a third caller with a
     * third argument type is a conversation rather than a silent false.
     */
    public function supports(Provider|CalendarSource $subject): bool
    {
        return match (true) {
            $subject instanceof Provider       => Provider::Ics === $subject,
            $subject instanceof CalendarSource => Provider::Ics === $subject->integrationProvider(),
        };
    }

    /**
     * The one calendar the feed is.
     *
     * A list of one rather than a scalar, because that is what the interface
     * says and what the subscribe screen renders — and because it costs
     * nothing: the screen shows a single ticked row, which is exactly the
     * honest picture of what connecting a feed offers.
     *
     * @return list<RemoteCalendar>
     *
     * @throws CalendarSyncException
     */
    public function discover(CalendarSource $source): array
    {
        $url      = $this->urlOfSource($source);
        $document = $this->reader->read($this->client->fetch($url)->body);

        return [new RemoteCalendar(
            remoteId: self::REMOTE_ID,
            name:     $this->reader->nameOf($document) ?? $this->normaliser->suggestedName($url),
            // A feed states no colour. Null lets CalendarSubscriber take the
            // next palette entry, so two feeds added in a row are not the same
            // blue.
            color:      null,
            timeZone:   $this->reader->timeZoneOf($document),
            isReadOnly: true,
            // No feed is anybody's primary calendar, and ticking one by default
            // would subscribe a user to a holiday list they only wanted to look
            // at.
            isPrimary:  false,
        )];
    }

    /**
     * @throws CalendarSyncException
     */
    public function pull(Calendar $calendar, ?string $syncToken): CalendarChangeSet
    {
        $url = $this->urlOfCalendar($calendar);

        [$etag, $lastModified] = $this->validatorsIn($syncToken);

        $response = $this->client->fetch($url, $etag, $lastModified);

        if (true === $response->isUnchanged) {
            // The token is handed straight back rather than rebuilt: a 304
            // carries no validators of its own, and re-deriving one from an
            // empty response would downgrade the next poll to a full download.
            return CalendarChangeSet::unchanged($syncToken);
        }

        if (null !== $syncToken) {
            // Before the body is parsed, not after — see the class docblock.
            // The engine is about to ask again with no token and that read is
            // the authoritative one, so parsing this copy would be work whose
            // result is discarded.
            return CalendarChangeSet::resyncRequired();
        }

        return new CalendarChangeSet(
            $this->eventsIn($response->body, $calendar),
            $this->tokenFor($response),
        );
    }

    /**
     * @throws CalendarSyncPermanentException always
     */
    public function push(Calendar $calendar, CalendarEvent $event): RemoteWriteResult
    {
        throw new CalendarSyncPermanentException(
            'A calendar subscribed from an address is a copy of a published file, so changes cannot be sent back to it.',
        );
    }

    /**
     * @throws CalendarSyncPermanentException always
     */
    public function delete(Calendar $calendar, CalendarEvent $event): void
    {
        throw new CalendarSyncPermanentException(
            'A calendar subscribed from an address is a copy of a published file, so events cannot be removed from it.',
        );
    }

    /**
     * Prove the address by reading a calendar out of it.
     *
     * A full GET and a full parse rather than a HEAD, because the failure worth
     * catching while the user is still looking at the form is not "the host is
     * down" — it is "this link is a web page", which is what a `Subscribe`
     * button copies about half the time. A HEAD would answer 200 to that.
     *
     * @throws IntegrationException
     */
    public function verify(Integration $integration): void
    {
        try {
            $this->reader->read($this->client->fetch($this->urlOf($integration))->body);
        } catch (CalendarSyncException $e) {
            // Carried across with its status so Integration::isAuthFailure()
            // still separates the kinds of failure the settings list reports
            // differently — the same translation CalDavCalendarDriver::verify()
            // makes at the same edge.
            throw new IntegrationException($e->getMessage(), $e->getStatus(), $e);
        }
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Every meeting in the document, as the engine's own vocabulary.
     *
     * An unreadable component costs one event and is logged, never the
     * calendar: a feed is somebody else's file and a single VEVENT with no
     * DTSTART in a thousand is not a reason to report that the whole
     * subscription is broken. That is the same rule CalDAV applies to a
     * resource it cannot read.
     *
     * @return list<RemoteEvent>
     *
     * @throws CalendarSyncException
     */
    private function eventsIn(string $body, Calendar $calendar): array
    {
        $document = $this->reader->read($body);
        $events   = [];

        foreach ($this->reader->resources($document) as $uid => $resource) {
            // Null etag: a file has no per-event version marker, which makes
            // every pull of every event a write. That is correct rather than
            // wasteful — CalendarPuller's etag shortcut is an optimisation, and
            // claiming a version we do not have would make it skip a changed
            // event.
            $event = $this->converter->toRemoteEvent($resource, $uid, null);

            if (null === $event) {
                $this->logger->info('IcsUrl: skipped an entry that is not a usable event', [
                    'calendarId' => $calendar->id,
                    'uid'        => $uid,
                ]);

                continue;
            }

            $events[] = $event;
        }

        return $events;
    }

    /**
     * The two validators packed into one opaque token, or null when the server
     * gave neither.
     *
     * Null on purpose rather than an empty pair: a token that says nothing
     * would still be a token, so the next poll would take the "we have a
     * position" branch, find the feed changed (it cannot tell otherwise) and
     * ask for a resync on every single sweep. Null means "no position", the
     * next read is a full one, and a publisher that sends no validators at all
     * is simply read in full each time — which is the only correct answer when
     * it offers no way to ask whether anything changed.
     */
    private function tokenFor(IcsFeedResponse $response): ?string
    {
        if (null === $response->etag && null === $response->lastModified) {
            return null;
        }

        return ($response->etag ?? '') . self::TOKEN_SEPARATOR . ($response->lastModified ?? '');
    }

    /**
     * @return array{0: string|null, 1: string|null} etag, then last-modified
     */
    private function validatorsIn(?string $syncToken): array
    {
        if (null === $syncToken || '' === $syncToken) {
            return [null, null];
        }

        // Limit 2, so a Last-Modified that somehow contained the separator
        // survives whole rather than being truncated at it. A token written by
        // an older version, or by hand, that carries no separator at all is
        // read as an ETag alone — which is the shape this used before it stored
        // both, and re-presenting it costs at most one redundant download.
        $parts = explode(self::TOKEN_SEPARATOR, $syncToken, 2);

        return [
            '' === $parts[0] ? null : $parts[0],
            '' === ($parts[1] ?? '') ? null : $parts[1],
        ];
    }

    /** @throws CalendarSyncPermanentException */
    private function urlOfSource(CalendarSource $source): string
    {
        $integration = $source->integration;

        if (null === $integration) {
            throw new CalendarSyncPermanentException(
                'This calendar source is not a subscribed address, so there is nothing to read.',
            );
        }

        return $this->urlOf($integration);
    }

    /** @throws CalendarSyncPermanentException */
    private function urlOfCalendar(Calendar $calendar): string
    {
        $integration = $calendar->integration;

        if (null === $integration) {
            throw new CalendarSyncPermanentException(
                'This calendar is not subscribed from an address any more.',
            );
        }

        return $this->urlOf($integration);
    }

    /**
     * The feed's address, normalised and SSRF-checked before it leaves here.
     *
     * Checked although IcsFeedClient checks it again, and deliberately: this is
     * the read that decides whether the connection is usable at all, and an
     * address stored before an administrator tightened INTEGRATIONS_ALLOWED_HOSTS
     * must stop working rather than keep working because it is already in the
     * database. The double check costs one parse.
     *
     * @throws CalendarSyncPermanentException
     */
    private function urlOf(Integration $integration): string
    {
        $url = $integration->baseUrl;

        if (null === $url || '' === $url) {
            throw new CalendarSyncPermanentException(
                'This subscription has no address on it. Add the calendar again.',
            );
        }

        try {
            return $this->normaliser->normalise($url);
        } catch (IntegrationException $e) {
            throw new CalendarSyncPermanentException($e->getMessage(), $e->getStatus(), $e);
        }
    }
}
