<?php

declare(strict_types=1);

namespace App\Domain\DTO\Calendar;

use App\Domain\Enum\Calendar\ExtractionKind;
use App\Entity\Calendar\CalendarEvent;
use App\Entity\Calendar\CalendarEventOccurrence;
use App\Entity\Mail\Message;
use DateTimeImmutable;

/**
 * One line of "Happening Soon": a thing plMail read out of mail, and the mail
 * it read it out of.
 *
 * A DTO rather than handing the occurrence straight to the template, because
 * three of the four things a row shows are not on it. The kind lives on the
 * event, the provenance lives on an EventSourceLink that has to be queried per
 * page rather than walked per row, and $startsAt is non-null here while every
 * column it comes from is nullable — Doctrine constructs entities before it
 * fills them, so the *entity* has to allow a null the *row* cannot contain.
 * Resolving all three once, in the reader, is what keeps the template free of
 * the `is not null` ladder that would otherwise decide whether a row renders.
 *
 * $source is the one field that may legitimately be null, and the template says
 * so rather than hiding it: an event keeps its kind after the message behind it
 * is expunged provider-side, and a row that silently dropped its provenance
 * would look identical to one that never had any. What it must never be is the
 * *superseded* claim — see HappeningSoonReader, which picks the newest applied
 * link, because "why is this on my calendar?" is answered by the message the
 * event currently reflects and not by the first one that mentioned it.
 */
final readonly class HappeningSoonRow
{
    private function __construct(
        public CalendarEventOccurrence $occurrence,
        public CalendarEvent           $event,
        public ExtractionKind          $kind,
        public DateTimeImmutable       $startsAt,
        public ?Message                $source,
    ) {
    }

    /**
     * A named constructor rather than a public one, so a row can never be built
     * out of step with the occurrence it describes — the event and the kind are
     * read off it here rather than passed in beside it.
     *
     * Answers null for an occurrence that is not an extracted event's. The
     * repository query already excludes those, so this is the belt to that
     * braces: it keeps the "only extracted events are listed" rule true in the
     * one place a caller could otherwise break it by handing over the wrong
     * list, and it is what lets every field above be non-nullable.
     */
    public static function of(CalendarEventOccurrence $occurrence, ?Message $source): ?self
    {
        $event = $occurrence->event;

        if (null === $event || null === $event->kind || null === $occurrence->startsAt) {
            return null;
        }

        return new self($occurrence, $event, $event->kind, $occurrence->startsAt, $source);
    }
}
