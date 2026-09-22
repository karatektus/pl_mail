<?php

declare(strict_types=1);

namespace App\Service\Search;

use Twig\Markup;

/**
 * Postgres `ts_headline` output, turned into highlight HTML that is safe to
 * hand to a reader.
 *
 * `ts_headline` is a highlighter, not an escaper. It walks the document with
 * the same parser the tsvector was built with, inserts StartSel before and
 * StopSel after each matching lexeme, and returns EVERYTHING ELSE VERBATIM —
 * angle brackets, quotes, attributes and all. Asking it for
 * `StartSel=<mark>` therefore produces a string in which our markup and the
 * sender's markup are indistinguishable:
 *
 *     ts_headline('english', '<img src=x onerror=alert(1)> chargecloud invoice',
 *                 websearch_to_tsquery('english', 'chargecloud'), 'StartSel=<mark>, …')
 *     → 'onerror=alert(1)> <mark>chargecloud</mark> invoice'
 *
 * That is not a hypothetical: it is what this codebase shipped to every JMAP
 * client until this class existed, under a docblock claiming the opposite. A
 * "does it contain <mark>" test cannot tell the two apart, because by then
 * there is nothing left to tell apart.
 *
 * THE SENTINEL PATTERN, and it only works in this order:
 *
 *   1. ask `ts_headline` for delimiters that are NOT markup — two control
 *      characters, so nothing in the returned string is HTML yet;
 *   2. escape the whole string, which is now uniformly untrusted text;
 *   3. swap the (untouched, because `htmlspecialchars` has no opinion about
 *      control characters) sentinels for the real tags.
 *
 * STX (0x02) and ETX (0x03) are the delimiters because they cannot occur in
 * displayable mail text: MIME transfer decoding produces them only from a
 * binary part, and a binary part is not `body_text`. If one somehow survives
 * anyway it is harmless here — the swap below only converts BALANCED pairs
 * and drops anything left over, so the worst case is a character disappearing
 * from a preview, not an unclosed tag.
 *
 * The options string is a constant on this class rather than a literal at the
 * two call sites, because a snippet built with one pair of sentinels and
 * converted with another is a snippet that silently shows `\x02` to a user or,
 * worse, highlights nothing and looks like the search matched the wrong thing.
 * The two halves of one decision live in one file.
 */
final class SearchHighlighter
{
    /** @see self::HEADLINE_OPTIONS — the marker `ts_headline` writes. */
    private const string START = "\x02";

    /** Its closing half. */
    private const string STOP = "\x03";

    /**
     * What to pass `ts_headline` as its options argument.
     *
     * One fragment, short enough to sit on a list row, and no ellipsis of our
     * own — the client (or the CSS) decides how to truncate for its width.
     *
     * `ShortWord=0` is the one number here that is not about size. It is what
     * makes a headline over a SUBJECT usable as that subject: at the default 3
     * the fragment has its leading and trailing short words trimmed off, so
     * "Oak for the alcove" searched for `alcove` comes back as just "alcove",
     * and a row rendering that has quietly rewritten the subject line rather
     * than highlighted it. Measured against Postgres 18, not reasoned about.
     * The cost is that a body fragment may now begin with "the", which is
     * cosmetic and true to the text.
     */
    public const string HEADLINE_OPTIONS = 'StartSel="'.self::START.'", StopSel="'.self::STOP.'", '
        .'MaxWords=24, MinWords=8, ShortWord=0, MaxFragments=1, FragmentDelimiter=" … "';

    /**
     * Highlight HTML for a JSON payload, or null when this field had no hit.
     *
     * A plain string, deliberately: this is what goes into a JMAP response,
     * where `Twig\Markup` would be serialised as an object and the "already
     * escaped" promise it carries means nothing. See toMarkup() for the web.
     *
     * Null rather than the field's text when nothing matched, because
     * `ts_headline` answers either way — it falls back to the opening words of
     * the document — and a "snippet" that is just the first line again tells
     * the reader nothing about why the message came back. The absence of a
     * sentinel is the only honest signal that this field is not the reason.
     *
     * `mixed` because the value arrives from a DBAL row, where every column is
     * typed as whatever the driver felt like.
     */
    public function toHtml(mixed $headline): ?string
    {
        if (false === is_string($headline) || '' === $headline) {
            return null;
        }

        if (false === str_contains($headline, self::START)) {
            return null;
        }

        $escaped = htmlspecialchars(
            $this->oneLine($headline),
            // ENT_SUBSTITUTE is load-bearing on mail. Without it a body whose
            // text part is not valid UTF-8 — which is most of what a broken
            // sender produces — makes htmlspecialchars return the empty
            // string, and the row loses its preview with nothing logged.
            \ENT_QUOTES | \ENT_SUBSTITUTE | \ENT_HTML5,
            'UTF-8',
        );

        // Pairs only. A lone sentinel cannot open a tag that never closes,
        // which is the one way a stray control character in a body could still
        // reach the page as structure rather than as text.
        //
        // No `u` modifier, deliberately: the pattern is pure ASCII, a control
        // byte cannot be part of a multi-byte sequence, and `u` would make the
        // whole replacement return null on the invalid UTF-8 that real mail
        // arrives in — losing the highlight on exactly the messages that need
        // the most care.
        $marked = preg_replace(
            '/'.self::START.'([^'.self::START.self::STOP.']*)'.self::STOP.'/',
            '<mark>$1</mark>',
            $escaped,
        ) ?? $escaped;

        return str_replace([self::START, self::STOP], '', $marked);
    }

    /**
     * The same HTML, wrapped so Twig prints it as markup.
     *
     * `Twig\Markup` rather than `|raw` at the call site, and the difference is
     * not style. `|raw` in a template is a switch somebody can copy onto the
     * next value that reaches that line; a Markup instance is a promise made
     * by the one object that actually did the escaping. The row template has
     * no `|raw` in it and this is what keeps it that way.
     */
    public function toMarkup(mixed $headline): ?Markup
    {
        $html = $this->toHtml($headline);

        return null === $html ? null : new Markup($html, 'UTF-8');
    }

    /**
     * The headline as the reader would see it, with the markers taken out.
     *
     * For the one caller that has to compare a headline with the string it was
     * made from — a subject highlight may only replace a subject when it IS
     * that subject, see SearchResultHighlights.
     */
    public function withoutMarkers(mixed $headline): ?string
    {
        if (false === is_string($headline)) {
            return null;
        }

        return str_replace([self::START, self::STOP], '', $headline);
    }

    /**
     * Wrapping, tabs and the non-breaking spaces mail is full of, collapsed to
     * single spaces.
     *
     * A text part is hard-wrapped at 72 columns and a headline taken out of it
     * carries those newlines. HTML would collapse them anyway on the web, but
     * a JMAP client is handed the string and a test asserting on one should
     * not have to know where the sender's line breaks fell.
     *
     * Runs BEFORE the escape and cannot disturb the sentinels: control
     * characters are not whitespace to `\s`.
     */
    private function oneLine(string $headline): string
    {
        $collapsed = preg_replace('/[\s\x{00A0}]+/u', ' ', $headline) ?? $headline;

        return trim($collapsed);
    }
}
