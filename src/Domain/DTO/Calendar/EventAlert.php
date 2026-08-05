<?php

declare(strict_types=1);

namespace App\Domain\DTO\Calendar;

use App\Domain\Enum\Calendar\AlertAction;
use DateTimeImmutable;

/**
 * One alert on an event: when it goes off, and what it does.
 *
 * The in-memory shape of an entry in `jscalendar.alerts` (RFC 8984 §4.5.2),
 * which is a map of Alert objects each carrying a `trigger` and an `action`.
 * Modelled as a value object rather than passed around as the decoded array
 * because every reader of one needs the same three answers — is it relative or
 * absolute, to what, and how far — and computing those at four call sites is how
 * two of them end up disagreeing about the sign.
 *
 * **$offset and $offsetSeconds are two spellings of one fact, deliberately.**
 * The string is what round-trips: it is written back into storage and out to
 * CalDAV exactly as it arrived, so an alarm expressed as `-P1W` does not come
 * back as `-P7D` and make every sync see a change that is not one. The integer
 * is what arithmetic uses, because "seven days before" has to become an instant
 * without re-parsing a grammar. JSCalendar's Duration excludes the month and
 * year designators (RFC 8984 §1.4.6), so the two cannot disagree — every unit it
 * admits is a fixed number of seconds.
 *
 * $key is the map key this alert lives under and is carried rather than
 * regenerated. It is what a delivery record names, so an alert whose key changed
 * because an unrelated field was edited would be an alert that fires a second
 * time.
 *
 * An AbsoluteTrigger sets $absoluteAt and leaves the offset fields null; an
 * OffsetTrigger does the reverse. Both null means a trigger nothing here can
 * read, and triggerFor() answers null rather than guessing an instant.
 */
final readonly class EventAlert
{
    public function __construct(
        public string             $key,
        public AlertAction        $action,
        public ?string            $offset,
        public ?int               $offsetSeconds,
        public bool               $relativeToEnd,
        public ?DateTimeImmutable $absoluteAt,
    ) {
    }

    /**
     * When this alert goes off for one occurrence, or null when its trigger is
     * unreadable.
     *
     * Takes the occurrence's own instants rather than the series': an instance
     * somebody dragged to Thursday must alert on Thursday, and
     * RecurrenceMaterialiser has already applied the override by the time these
     * two values exist. That is the whole reason this takes arguments instead of
     * an event.
     */
    public function triggerFor(DateTimeImmutable $startsAt, DateTimeImmutable $endsAt): ?DateTimeImmutable
    {
        if (null !== $this->absoluteAt) {
            return $this->absoluteAt;
        }

        if (null === $this->offsetSeconds) {
            return null;
        }

        $anchor = true === $this->relativeToEnd ? $endsAt : $startsAt;

        return $anchor->modify(sprintf('%+d seconds', $this->offsetSeconds));
    }

    /**
     * This alert as the RFC 8984 object it is stored as.
     *
     * `relativeTo` is written only when it is "end", because "start" is the
     * default and an object that spells out every default is an object that
     * looks changed to any sync comparing two of them.
     *
     * @return array<string,mixed>
     */
    public function toJsCalendar(): array
    {
        $trigger = null !== $this->absoluteAt
            ? [
                '@type' => 'AbsoluteTrigger',
                'when'  => $this->absoluteAt->format('Y-m-d\TH:i:s\Z'),
            ]
            : [
                '@type'  => 'OffsetTrigger',
                'offset' => (string) $this->offset,
            ];

        if (null === $this->absoluteAt && true === $this->relativeToEnd) {
            $trigger['relativeTo'] = 'end';
        }

        return [
            '@type'   => 'Alert',
            'trigger' => $trigger,
            'action'  => $this->action->value,
        ];
    }
}
