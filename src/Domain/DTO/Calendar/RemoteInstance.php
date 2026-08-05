<?php

declare(strict_types=1);

namespace App\Domain\DTO\Calendar;

use DateTimeImmutable;

/**
 * Which occurrence of which series one of the remote's instance resources is.
 *
 * Not a change, and that is the whole reason it is not a RemoteEvent. A provider
 * whose instances are separate resources gives each of them an id of its own,
 * and there is one thing that id is needed for later: Microsoft reports a
 * cancelled occurrence as a `@removed` entry carrying that id and nothing else —
 * not its series, not the start it had. Read against a record of which
 * occurrence each id stands for, that entry becomes "one instance of this series
 * is off", which is a fact the engine can act on. Read without one it matches no
 * row, does nothing at all, and the occurrence the user deleted in Outlook stays
 * on the calendar for good.
 *
 * So this says only what an id means, never that anything happened to it. Put in
 * CalendarChangeSet::$events instead, every unchanged occurrence of every weekly
 * meeting would arrive as a change to apply — fifty-two writes a year per series
 * saying the instance is where the rule already puts it — and the engine would
 * have to decide which of them said nothing. Kept apart, the events list goes on
 * meaning "what changed" and this means "what these ids are".
 *
 * $recurrenceId is the instance's ORIGINAL start, exactly as on RemoteEvent: the
 * one name an occurrence keeps once it has been dragged, and the key its
 * override is filed under.
 */
final readonly class RemoteInstance
{
    /**
     * @param string            $remoteId       the provider's opaque id for this
     *                                          one occurrence — never a local
     *                                          row's, because an instance has
     *                                          never been one
     * @param string            $seriesRemoteId the RemoteEvent::$remoteId of the
     *                                          series it belongs to
     * @param DateTimeImmutable $recurrenceId   UTC. Where the rule put it, not
     *                                          where it was moved to.
     */
    public function __construct(
        public string            $remoteId,
        public string            $seriesRemoteId,
        public DateTimeImmutable $recurrenceId,
    ) {
    }
}
