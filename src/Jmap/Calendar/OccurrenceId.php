<?php

declare(strict_types=1);

namespace App\Jmap\Calendar;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The id one dated instance of a series is named by when `CalendarEvent/query`
 * was asked to expand recurrences: the series' id and the instance's recurrence
 * id, in one string.
 *
 * **It is a synthetic id, and the client must treat it as opaque.** That is the
 * JMAP-calendars draft's own word for it — the id "uniquely identifies the base
 * event id + recurrence id within the account, allowing the server to resolve
 * these" — and the reason is that the pair it encodes is a server-side join, not
 * a client-side one. A client that split it and re-assembled its own would be
 * expanding recurrence rules by hand, which is the thing
 * `docs/CLIENT_DEVELOPMENT.md` tells clients not to do.
 *
 * **The spelling is `<eventId>_<recurrenceId>`, not the `<eventId>;<recurrenceId>`
 * other implementations use, because a `;` is not a legal JMAP Id.** RFC 8620
 * §1.2 restricts an Id to the URL-safe base64 alphabet — `A-Za-z0-9`, `-` and
 * `_` — so an id carrying a semicolon or the colons of an ISO timestamp is one a
 * conforming client library is entitled to reject before this server's response
 * is ever read. The draft says "opaque" rather than naming a separator for
 * exactly that reason, so this picks a legal one and keeps it readable:
 *
 *     42_20260304T090000Z
 *
 * The timestamp half is the instance's ORIGINAL start — where the rule put it,
 * never where an override moved it — as a UTC instant in ISO 8601 basic format,
 * which is the same instant `EventInstanceEditor::identify()` writes into the
 * web editor's URL with the separators RFC 8620 forbids. That the two spellings
 * differ is fine and the reason is worth stating: one is a URL parameter and the
 * other is a JMAP Id, and only the second has a charset imposed on it.
 *
 * A one-off event is never named this way. It has one occurrence, that
 * occurrence is the event, and giving it a synthetic id would cost a client the
 * plain series id it can hand straight back to `CalendarEvent/set` — see
 * CalendarEventQueryRunner.
 */
final readonly class OccurrenceId
{
    /**
     * Not `;`, and not a `-` either: the event id is digits and the timestamp
     * begins with digits, so a hyphen would be a separator that also appears
     * inside neither half but reads as though it might. `_` cannot occur in
     * either half at all, so the split is unambiguous by construction.
     */
    private const string SEPARATOR = '_';

    /** ISO 8601 basic format, UTC — the same instant with the colons removed. */
    private const string INSTANT = 'Ymd\THis\Z';

    public function __construct(
        public int               $eventId,
        public DateTimeImmutable $recurrenceId,
    ) {
    }

    /**
     * Null rather than an exception for anything that is not one of these.
     *
     * Every caller is reading an id off the wire, where "this is a plain series
     * id" and "this is a malformed instance id" are both ordinary answers a
     * client is allowed to send. Distinguishing them is the caller's job:
     * `CalendarEvent/get` resolves a plain id as a series and reports a
     * malformed one as notFound, and neither is an error worth aborting a
     * method call over.
     */
    public static function parse(string $id): ?self
    {
        if (1 !== preg_match('/^(\d+)'.self::SEPARATOR.'(\d{8}T\d{6}Z)$/', $id, $matches)) {
            return null;
        }

        // "!" resets the fields the format does not name, so the instant
        // carries no microseconds from the current time — the column it is
        // matched against holds seconds, and a stray fraction matches no row.
        $recurrenceId = DateTimeImmutable::createFromFormat('!'.self::INSTANT, $matches[2], new DateTimeZone('UTC'));

        if (false === $recurrenceId) {
            return null;
        }

        return new self((int) $matches[1], $recurrenceId);
    }

    public static function of(int $eventId, DateTimeImmutable $recurrenceId): string
    {
        return (new self($eventId, $recurrenceId))->toString();
    }

    public function toString(): string
    {
        return $this->eventId
            .self::SEPARATOR
            .$this->recurrenceId->setTimezone(new DateTimeZone('UTC'))->format(self::INSTANT);
    }
}
