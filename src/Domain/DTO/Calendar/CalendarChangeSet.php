<?php

declare(strict_types=1);

namespace App\Domain\DTO\Calendar;

/**
 * Everything that changed at the remote since the token the caller presented,
 * and the token to present next time.
 *
 * One window, not one page. Providers page their delta feeds and every one of
 * them pages differently — Google follows `nextPageToken` until it hands back a
 * `nextSyncToken`, Graph follows `@odata.nextLink` until it hands back an
 * `@odata.deltaLink`, CalDAV answers a REPORT whole. Making the engine drive
 * that loop would mean the engine knowing which of the two links it just
 * received, which is exactly the provider concept this contract exists to keep
 * out. So the driver follows its own paging internally and returns the complete
 * window; $nextSyncToken is the *end* of it, never an intermediate page cursor.
 *
 * $requiresFullResync is the one answer every provider has and none of them
 * spells the same way: Google answers 410 Gone to an expired sync token, Graph
 * answers `resyncRequired` in the delta response, and a CalDAV server whose
 * ctag has moved in a way the sync-token cannot bridge answers 403 with
 * `valid-sync-token`. Reduced to a boolean here because the recovery is
 * identical in all three cases — forget the token, read the calendar from
 * scratch — and a caller that had to recognise three provider errors to work
 * that out would be a caller that gets one of them wrong.
 *
 * When it is true, $events is ignored and $nextSyncToken is meaningless. A
 * driver may return both anyway; the engine will not look at them.
 */
final readonly class CalendarChangeSet
{
    /**
     * @param list<RemoteEvent> $events             creates, updates and
     *                                              tombstones, in the order the
     *                                              remote reported them —
     *                                              applied in order, so a
     *                                              driver that sorts them
     *                                              changes the outcome
     * @param string|null       $nextSyncToken      opaque; stored verbatim in
     *                                              Calendar::$syncToken and
     *                                              handed back untouched. Null
     *                                              means the provider issued
     *                                              none for this window, and the
     *                                              engine keeps whatever it had
     *                                              rather than downgrading a
     *                                              working token to a full read
     *                                              on the next run.
     * @param bool              $requiresFullResync the token is dead; see the
     *                                              class docblock
     */
    public function __construct(
        public array   $events,
        public ?string $nextSyncToken = null,
        public bool    $requiresFullResync = false,
    ) {
    }

    /**
     * Nothing moved, and the token still works. The answer to most polls, and
     * worth a named constructor so a driver does not have to spell an empty
     * list every fifteen minutes.
     */
    public static function unchanged(?string $syncToken): self
    {
        return new self([], $syncToken);
    }

    /**
     * The token is dead and the calendar must be read from scratch.
     */
    public static function resyncRequired(): self
    {
        return new self([], null, true);
    }
}
