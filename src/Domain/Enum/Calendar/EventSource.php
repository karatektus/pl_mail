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
 *
 * AcceptedProposal is the other half of that argument and the reason Llm must
 * not be reached for to cover it. A guess a person confirmed and a guess nobody
 * saw are different facts, and the enum is the only place either of them is
 * written down — the row that results looks identical otherwise.
 */
enum EventSource: string
{
    /** The user typed it. */
    case Manual = 'manual';

    /**
     * plMail read a date out of prose and a person said yes to it.
     *
     * Not Manual, which is a date nobody but the user proposed. Here the day,
     * the hour and the title were this application's reading of somebody
     * else's sentence and the user only agreed with it — so when such an event
     * turns out to be an hour out, the parser is the suspect, and that is a
     * question no query can ask once the row claims a human typed it.
     *
     * Not extraction either. Ics, StructuredData and SenderParser arrive
     * without anyone being asked and stay EventReconciler's to revise as more
     * mail about the same booking turns up; this one was decided, once, by the
     * person whose calendar it is — see mayBeRewrittenByMail(). It carries no
     * ExtractionKind for the same reason, so isExtracted() answers false and
     * none of the "found in your email" affordances appear beside it.
     *
     * And not Llm, which stays reserved: that is a guess *nobody* confirmed.
     * Folding the two together would lose the single fact that makes this one
     * safe to show at full confidence — that a person looked at it.
     */
    case AcceptedProposal = 'acceptedProposal';

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
     * A stranger booked it on a public booking page.
     *
     * The one case where the event exists because somebody who is not the
     * owner, and who has no account here, asked for it. That is the fact worth
     * recording and it is not recoverable from anything else on the row: the
     * booking lands on a calendar the owner nominated, at a time the owner's
     * own availability rules allowed, with a title the owner's page supplied —
     * so every column reads exactly like an event the owner typed.
     *
     * Not Manual for that reason, and the distinction is not cosmetic. A booked
     * hour is the only kind of event on the calendar that a person outside this
     * install can cause to appear, so "which of these did I not put here?" is a
     * question somebody will ask the first time a booking page is abused, and
     * a query can only answer it if the answer was written down at the time.
     *
     * Not an ExtractionKind either, although Meeting is sitting right there.
     * A kind means "plMail read this out of your mail" — isExtracted() is
     * literally `null !== $kind` — so borrowing one would put a booking behind
     * every "found in your email" affordance, offer to dismiss it as a
     * misreading, and file it into Happening Soon beside parcel deliveries.
     * The event is not a claim about a message; it is a commitment somebody
     * made.
     *
     * The booker's own details — their name, their address, the note they left
     * — are NOT here and are not on the event. They live on CalendarBooking,
     * because they are facts about the booking rather than about the meeting,
     * and because the row that carries them is also the row whose unique
     * constraint stops the slot being taken twice.
     */
    case Booking = 'booking';

    /**
     * Whether this source may write to the calendar unsupervised.
     *
     * An untrusted event is Tentative, capped in confidence, never pushed
     * outward to a connected calendar, and shown with an explicit confirm
     * affordance. The asymmetry is the reason: a missed event is an annoyance,
     * an invented flight time is a missed flight.
     *
     * Exhaustive, where this used to be `self::Llm !== $this`. That spelling
     * answers "trusted" for every case written after it, so the next
     * unsupervised source would silently inherit the calendar-writing
     * permission this method exists to withhold — the same fallthrough
     * Integration\Provider::authKind() carried until it was closed.
     */
    public function isTrusted(): bool
    {
        return match ($this) {
            self::Manual,
            self::AcceptedProposal,
            self::Ics,
            self::StructuredData,
            self::SenderParser,
            self::RemoteSync,
            // A booking is trusted for the one thing this answers: it may be
            // pushed outward. The owner published the page, wrote the hours and
            // named the calendar, so the meeting on it is the owner's own
            // arrangement — and a booking that stayed Tentative and never
            // reached the connected calendar would be a booking the owner's
            // phone never shows, which is the whole point of the feature.
            self::Booking    => true,
            self::Llm        => false,
        };
    }

    /**
     * The badge a chip draws beside this event, or null for the sources that
     * get none.
     *
     * Modelled on ExtractionKind::icon() — the case carries its own icon rather
     * than a template carrying a match table — and null for almost everything
     * on purpose. A badge on every event is a badge on nothing: Manual is the
     * ordinary case, and an extracted event already has ExtractionKind::icon()
     * to draw, so a second mark here would double it.
     *
     * Exhaustive with no default, which is the point of putting it here. The
     * requirement a booked event has to keep is that it is *clearly visible as
     * one*, and a `?? null` fallthrough would let the next source added inherit
     * "no badge" without anybody deciding that it should.
     */
    public function icon(): ?string
    {
        return match ($this) {
            self::Manual,
            self::AcceptedProposal,
            self::Ics,
            self::StructuredData,
            self::SenderParser,
            self::Llm,
            self::RemoteSync => null,
            self::Booking    => 'fa-solid fa-calendar-check',
        };
    }

    /**
     * Translation key for the badge's label and tooltip. Null wherever icon()
     * is null, and the two are answered by one match so they cannot disagree
     * about which sources are marked.
     */
    public function badgeTransKey(): ?string
    {
        return match ($this) {
            self::Manual,
            self::AcceptedProposal,
            self::Ics,
            self::StructuredData,
            self::SenderParser,
            self::Llm,
            self::RemoteSync => null,
            self::Booking    => 'calendar.booking.badge',
        };
    }

    /**
     * Whether a later message may rewrite an event that came from here.
     *
     * EventReconciler's question, answered by the enum rather than by a column,
     * because it is a fact about where the event came from and not about
     * anything that has happened to it since. An extraction is a claim, and
     * claims get revised: a booking moves, an invite arrives with a higher
     * SEQUENCE, and the row has to follow. An event a person decided on is not
     * a claim, and mail arriving afterwards does not get to disagree with them.
     *
     * RemoteSync answers true on purpose. A mirrored event and a mailed invite
     * carrying the same UID are two views of one booking, and the mail is the
     * organiser's own word about it — narrowing this would quietly stop invite
     * updates from reaching events that also live on a connected calendar.
     *
     * CalendarEvent::$isUserEdited does not replace this and is not a
     * duplicate of it: that flag is only ever set on an *extracted* event
     * (CalendarEventWriter::markUserEdited), so on its own it protects nothing
     * that was never extracted in the first place.
     */
    public function mayBeRewrittenByMail(): bool
    {
        return match ($this) {
            self::Manual,
            self::AcceptedProposal,
            // For the same reason AcceptedProposal answers false, arrived at
            // from the other end. A booking is not a claim about a meeting that
            // some later message might know more about — it IS the meeting, and
            // the only party who could send mail carrying its UID is the booker,
            // who has no standing to move an hour in somebody else's diary. A
            // reconciler that could rewrite it would turn the booking page into
            // a way to edit the owner's calendar by email afterwards.
            self::Booking          => false,
            self::Ics,
            self::StructuredData,
            self::SenderParser,
            self::Llm,
            self::RemoteSync => true,
        };
    }
}
