<?php

declare(strict_types=1);

namespace App\Service\Calendar\Proposal;

/**
 * Stage one: does this text contain anything date-shaped at all?
 *
 * A cheap deterministic refusal in front of an expensive parse, and the reason
 * the expensive parse is affordable. Nearly all mail dies here on one regex
 * over text that is already in memory — no query, no disk, no network — and
 * only what survives is worth splitting into sentences and reading properly.
 * The same gate is what makes stage three thinkable at all: a model asked about
 * every message is a bill, and one asked about the few per cent of mail that
 * names a weekday is not.
 *
 * Deliberately over-permissive. A gate that tried to be accurate would be the
 * parser written a second time, the two would disagree, and the failure mode is
 * the invisible one: a message the parser could have read that the gate threw
 * away, which nobody ever notices because nothing was displayed. It answers
 * "possibly" and "certainly not", and only the second answer is acted on.
 *
 * German, English and French in one alternation rather than one pass per
 * language, because a mailbox mixes them and no message says which it is.
 *
 * Every name is listed in full rather than as a stem with a wildcard. The first
 * version used `(?:mon|die|sam|mai)[a-zäöü]*`, which matches "money", "die",
 * "same" and "mail" — the German definite article and the word "mail" appear in
 * essentially every message, so the gate passed everything and stopped being a
 * gate. Umlauted words are matched without a leading word boundary, because
 * PCRE's \b is ASCII-only unless UCP is on and "ü" is therefore not a word
 * character: `\bübermorgen` would only match where the preceding character was
 * a letter, which is nowhere.
 */
final readonly class DateShapeGate
{
    /**
     * Anything that could be a date or a time, in any of the three languages.
     *
     * Assembled from commented fragments rather than written as one literal, so
     * a form can be added without counting brackets in a two-hundred-character
     * string to find out where it goes.
     */
    private const string SHAPE = '~(?:'
        // 2026-08-04, 04.08.2026, 4/8/2026 — anything with two separators.
        . '\b\d{1,4}[./-]\d{1,2}[./-]\d{2,4}\b'
        // 14:00, 9:30.
        . '|\b\d{1,2}:\d{2}\b'
        // 3pm, 3 p.m., 14 Uhr, 14h, 14h30, 14 heures.
        . '|\b\d{1,2}\s*(?:am|pm|a\.m\.|p\.m\.|uhr|heures?)\b'
        . '|\b\d{1,2}\s?h(?:\s?\d{2})?\b'
        // A month, spelled or abbreviated, in any of the three.
        . '|\b(?:jan|januar|january|feb|februar|february|mar|march|apr|april'
        . '|mai|may|jun|juni|june|jul|juli|july|aug|august|sep|sept|september'
        . '|okt|oct|oktober|october|nov|november|dez|dec|dezember|december'
        . '|janvier|janv|fevrier|févr|fevr|mars|avril|avr|juin|juillet|juil|aout|août'
        . '|septembre|octobre|novembre|decembre|déc)\b'
        . '|märz|février|décembre'
        // A weekday, spelled or abbreviated, in any of the three.
        . '|\b(?:mon|monday|montag|tue|tues|tuesday|dienstag|wed|wednesday'
        . '|mittwoch|thu|thur|thurs|thursday|donnerstag|fri|friday|freitag'
        . '|sat|saturday|samstag|sonnabend|sun|sunday|sonntag'
        . '|lundi|mardi|mercredi|jeudi|vendredi|samedi|dimanche)\b'
        // The relative words that name a day without naming a date.
        . '|\b(?:heute|morgen|today|tomorrow|tonight|demain|aujourd|midi)\b'
        . '|übermorgen'
        . ')~iu';

    public function passes(string $text): bool
    {
        return '' !== $text && 1 === preg_match(self::SHAPE, $text);
    }
}
