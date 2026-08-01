<?php

declare(strict_types=1);

namespace App\Service\Calendar\Extraction;

use App\Domain\Enum\Calendar\EventSource;
use App\Domain\Enum\Calendar\EventStatus;
use App\Domain\Enum\Calendar\ExtractionKind;
use App\Entity\Mail\MessagePart;
use DateTimeImmutable;

/**
 * One event an extractor found in one message.
 *
 * Not a CalendarEvent: what an extractor produces is a *claim*, and whether it
 * becomes a row, updates one, or is filed as a superseded duplicate is
 * EventReconciler's decision. Keeping the two apart is what lets several
 * extractors run over the same message and disagree without any of them
 * writing.
 *
 * $sourcePayload is stored verbatim on the resulting EventSourceLink. It is
 * the whole reason extraction can be re-run as a backfill rather than a
 * resync: the extractor's input sits next to its output, so improving a mapper
 * and replaying it needs no mail server.
 */
final readonly class ExtractedEvent
{
    /**
     * @param string                $uid           the event's identity — a real iCalendar UID where
     *                                             there is one, since RFC 5546 already decided what
     *                                             identity means and re-deciding it breaks every
     *                                             other calendar
     * @param string                $dedupKey      what this extraction claims to be about, used
     *                                             before an event exists and for suppressions
     * @param array<string,mixed>   $jscalendar    the canonical object
     * @param array<string,mixed>   $sourcePayload the fragment as it was read
     */
    public function __construct(
        public string             $uid,
        public string             $dedupKey,
        public array              $jscalendar,
        public DateTimeImmutable  $startsAt,
        public DateTimeImmutable  $endsAt,
        public string             $extractor,
        public EventSource        $source,
        public int                $confidence,
        public ?string            $title = null,
        public ?string            $location = null,
        public ?string            $timeZone = null,
        public bool               $isAllDay = false,
        public EventStatus        $status = EventStatus::Confirmed,
        public ?ExtractionKind    $kind = null,
        public int                $sequence = 0,
        public ?MessagePart       $part = null,
        public array              $sourcePayload = [],
    ) {
    }
}
