<?php

declare(strict_types=1);

namespace App\Domain\DTO\Calendar;

/**
 * One calendar as the remote describes it, before plMail has decided whether to
 * mirror it.
 *
 * What discover() answers with, so it deliberately holds only what the
 * subscribe screen needs to draw a row and what provisioning needs to create
 * the local Calendar: an id to come back with, a name to show, and the three
 * facts a user cannot supply themselves.
 *
 * No event counts and no last-modified. Both are another round trip per
 * calendar on every provider, they are stale the moment they are read, and a
 * list that takes four seconds to draw because it counted events nobody asked
 * about is worse than one that does not mention them.
 */
final readonly class RemoteCalendar
{
    /**
     * @param string      $remoteId   opaque at the provider; stored verbatim in
     *                                Calendar::$remoteId and handed back to the
     *                                driver untouched, never parsed by anything
     * @param string|null $color      #rrggbb, or null when the remote has no
     *                                opinion — the provisioner then picks from
     *                                Calendar::COLORS rather than inventing one
     * @param string|null $timeZone   IANA name, or null when the remote does
     *                                not say; the caller falls back to the
     *                                user's own zone
     * @param bool        $isReadOnly true when the account cannot write here —
     *                                a subscribed holiday calendar, a colleague's
     *                                calendar shared read-only. Drives
     *                                Calendar::$isReadOnly, which is what stops
     *                                the engine ever pushing.
     * @param bool        $isPrimary  the account's own default calendar. At most
     *                                one per source; the subscribe screen ticks
     *                                it by default, because it is the one a user
     *                                who connected an account meant.
     */
    public function __construct(
        public string  $remoteId,
        public string  $name,
        public ?string $color = null,
        public ?string $timeZone = null,
        public bool    $isReadOnly = false,
        public bool    $isPrimary = false,
    ) {
    }
}
