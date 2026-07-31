<?php

declare(strict_types=1);

namespace App\Domain\Enum\Calendar;

/**
 * Where an event came from, which is how much it is trusted.
 *
 * Llm is reserved deliberately and is not produced by anything yet. Extraction
 * is designed to be deterministic — invites are iCalendar, and the reservations
 * worth catching carry schema.org markup — so a model is a last resort for the
 * tail rather than the mechanism. The case exists now because the alternative
 * is a one-way door: if guessed events ever land unmarked next to parsed ones,
 * there is no query that separates them again.
 */
enum EventSource: string
{
    /** The user typed it. */
    case Manual = 'manual';

    /** Parsed from a text/calendar part. Effectively exact. */
    case Ics = 'ics';

    /** schema.org JSON-LD or microdata in the message body. */
    case StructuredData = 'structuredData';

    /** A parser written for one sender's mail format. */
    case SenderParser = 'senderParser';

    /** Guessed by a language model. Quarantined — see isTrusted(). */
    case Llm = 'llm';

    /** Mirrored from a connected calendar. */
    case RemoteSync = 'remoteSync';

    /**
     * Whether this source may write to the calendar unsupervised.
     *
     * An untrusted event is Tentative, capped in confidence, never pushed
     * outward to a connected calendar, and shown with an explicit confirm
     * affordance. The asymmetry is the reason: a missed event is an annoyance,
     * an invented flight time is a missed flight.
     */
    public function isTrusted(): bool
    {
        return self::Llm !== $this;
    }
}
