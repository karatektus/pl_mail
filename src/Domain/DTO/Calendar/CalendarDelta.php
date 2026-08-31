<?php

declare(strict_types=1);

namespace App\Domain\DTO\Calendar;

/**
 * What changed between two state tokens, before either protocol dresses it up.
 *
 * The three partitions are keyed by event id and hold the event's UID, because
 * the two readers need different halves of that pair: JMAP answers with ids,
 * and CalDAV builds an href from the UID. Carrying both is what lets one
 * computation serve both — and for a destroyed event it is the only place the
 * UID still exists, the row it came from being gone.
 *
 * Neutral rather than shaped like JMAP's ChangeSet, which it otherwise
 * resembles. ChangeSet lives in App\Jmap\State and knows about accountIds; a
 * CalDAV controller reaching into another delivery layer for it would be the
 * wrong arrow, and the day CalDAV needed a field JMAP does not have, the shared
 * class would start carrying JMAP-shaped nulls.
 *
 * @see \App\Service\Calendar\Change\CalendarChangeReader
 */
final class CalendarDelta
{
    /**
     * @param array<string,string> $created   event id => RFC 5545 UID
     * @param array<string,string> $updated   event id => RFC 5545 UID
     * @param array<string,string> $destroyed event id => RFC 5545 UID
     */
    public function __construct(
        public readonly string $oldState,
        public readonly string $newState,
        public readonly bool $hasMoreChanges,
        public readonly array $created,
        public readonly array $updated,
        public readonly array $destroyed,
    ) {
    }

    /** @return list<string> */
    public function createdIds(): array
    {
        return array_map(strval(...), array_keys($this->created));
    }

    /** @return list<string> */
    public function updatedIds(): array
    {
        return array_map(strval(...), array_keys($this->updated));
    }

    /** @return list<string> */
    public function destroyedIds(): array
    {
        return array_map(strval(...), array_keys($this->destroyed));
    }
}
