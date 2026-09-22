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
 *
 * There are now TWO producers of that sentinel string — Postgres, and the
 * fallback below for the hits its parser structurally cannot mark — and still
 * exactly ONE conversion, in toHtml(). That is the whole reason the fallback
 * lives in here rather than beside its caller: a second way of arriving at a
 * `<mark>` is a second way of arriving at one without escaping first, which is
 * the bug this class was written to end. See headlineOrFallback().
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
     * The fragment's size, in words, matched to MaxWords=24 above.
     *
     * Split unevenly on purpose. A row is read left to right, so a few words of
     * lead-in are enough to place the match while the words AFTER it are what
     * actually say what the mail is about. Eight and sixteen is that, and their
     * sum is the ceiling `ts_headline` works to — the two paths have to look
     * alike in one list or the fallback reads as a different feature.
     */
    private const int WORDS_BEFORE = 8;

    /** @see self::WORDS_BEFORE */
    private const int WORDS_AFTER = 16;

    /**
     * A backstop on each side of the match, in characters.
     *
     * Counting words does not bound a fragment, because a "word" here is
     * whatever sits between two spaces and a mail can carry a two-kilobyte
     * unbroken run — a pasted base64 part, a tracking URL, a text part whose
     * invalid UTF-8 defeated the whitespace normalisation. Sixteen words of
     * prose is around a hundred characters, so at two hundred this never fires
     * on anything a person wrote; it exists so the degenerate case costs a
     * short preview rather than the whole body.
     *
     * Whole words are dropped from the far end until the side fits, never a
     * cut through the middle of one. The matched text itself is never trimmed:
     * a URL is one token to `ts_headline` too, and half a link is a lie.
     */
    private const int MAX_CONTEXT_CHARS = 200;

    /**
     * Below this a term is not worth marking.
     *
     * The same three as FreeTextCompiler::MIN_SUBSTRING_LENGTH, and for the
     * same reason turned around: a two-character needle matches inside half the
     * words on the page. There it makes the query expensive; here it would
     * scatter `<mark>` through the middles of ordinary German words and make
     * the row harder to read than no highlight at all. Such a term simply is
     * not marked, and its row keeps the ordinary preview.
     */
    private const int MIN_TERM_LENGTH = 3;

    /** The FragmentDelimiter character above, reused where this truncates. */
    private const string ELLIPSIS = '…';

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
     * `ts_headline`'s answer when it marked something, and a fragment built
     * here when it could not — still in sentinel form either way, so the ONE
     * conversion in toHtml() is what turns both into markup.
     *
     * Callers pass the result straight on to toHtml(), toMarkup() or
     * withoutMarkers(); there is deliberately no second route to a `<mark>`.
     *
     * `ts_headline` STAYS FIRST. It agrees with the tsquery that ran the search
     * by construction, it picks the densest cover rather than the first hit,
     * and it knows where a lexeme actually begins. The fallback below knows
     * none of that — it is a substring search — so it only ever runs on the
     * fields `ts_headline` has already declined to mark.
     */
    public function headlineOrFallback(mixed $headline, mixed $text, string $freeText): ?string
    {
        if (true === is_string($headline) && true === str_contains($headline, self::START)) {
            return $headline;
        }

        return $this->fallback($text, $freeText);
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
     * A fragment built here, for the hits `ts_headline` structurally cannot
     * mark. Null when the term genuinely is not in this text.
     *
     * ── WHY `ts_headline` ALONE IS NOT ENOUGH ───────────────────────────────
     * Measured against the running database with the exact call the search
     * makes — `ts_headline('english', body, websearch_to_tsquery('english',
     * 'chargecloud'), HEADLINE_OPTIONS)`:
     *
     *   body text                                                  | marked?
     *   -----------------------------------------------------------+--------
     *   Full Stack Engineer bei ActAI chargecloud Deutschland Remote| YES
     *   Jobangebot ansehen https://www.linkedin.com/jobs/view/…     | no
     *   /4454199659/company=chargecloud                             |
     *   Neue Jobs bei chargecloud.de und anderen Firmen             | no
     *
     * The `english` parser makes the whole link ONE token of type `url` and
     * `chargecloud.de` ONE token of type `host`. Neither stems to
     * `chargecloud`, so the tsquery cannot match INSIDE them, and a highlighter
     * built on lexemes can only mark what a lexeme can be.
     *
     * The search found those rows regardless — through the weight-D
     * `plmail_token_parts` arm of `search_vector` (Version20260818120000),
     * which indexes exactly the pieces of a compound token, and through the
     * substring arm for a single-term query. Both find matches that are, by
     * definition, the thing `ts_headline` refuses to mark. The result on screen
     * was one genuinely relevant mail highlighted and a dozen equally-matched
     * ones not, which reads as the highlighting being broken rather than as the
     * index being cleverer than the highlighter.
     *
     * ── WHAT "THE TERM OCCURS" MEANS FOR A MULTI-WORD QUERY ─────────────────
     * ANY term, not all of them — and that is not a loosening, it is what the
     * primary path already does. Measured: `ts_headline` given the AND query
     * `invoice & zzzznotpresent` over "The invoice is here and needs paying…"
     * answers "The ⟦invoice⟧ is here and needs paying very". It marks whatever
     * it can find and does not care that the query as a whole is unsatisfied.
     *
     * That settles the question, and it settles it strictly: this method is
     * only ever reached when NOT ONE term of the query matched as a lexeme in
     * this field. So a three-word query where only one word appears cannot be
     * over-marked here relative to the primary path — the primary path would
     * have marked that word itself if it could see it. Requiring all three
     * would instead make the fallback quieter than the thing it backs up, which
     * is the wrong direction for a feature whose complaint is "why is this row
     * not highlighted".
     *
     * The window is anchored on the EARLIEST occurrence of any term, and every
     * term is then marked everywhere it appears inside that window. A
     * cover-density choice like `ts_headline`'s was considered and dropped:
     * with no lexeme hits anywhere in the field there is no density to optimise
     * for, only an ordering to pick, and the first one is the one a reader can
     * predict.
     *
     * ── WHAT IS NOT HANDLED ─────────────────────────────────────────────────
     * Stemming. A search for `paying` that found a body saying `paid` matched
     * on the shared lexeme, so `ts_headline` marked it and this never runs; but
     * a search for `pay` over `paying` where the tsquery somehow missed would
     * find `pay` here as a substring and mark it mid-word. That is the same
     * bargain the substring arm of the query already makes, and it is why
     * MIN_TERM_LENGTH exists.
     *
     * `$text` is `mixed` for the same reason `toHtml()`'s argument is: it
     * arrives from a DBAL row or a nullable entity column.
     */
    private function fallback(mixed $text, string $freeText): ?string
    {
        if (false === is_string($text) || '' === $text) {
            return null;
        }

        $terms = $this->terms($freeText);

        if ([] === $terms) {
            return null;
        }

        $anchor = $this->earliestOccurrence($text, $terms);

        // The honest answer for a row found on the sender's name, on a meaning
        // match, or on an operator: this field is not the reason, so it has no
        // fragment and the row keeps the preview every other list shows.
        if (null === $anchor) {
            return null;
        }

        return $this->mark($this->window($text, $anchor[0], $anchor[1]), $terms);
    }

    /**
     * The words of the free text that are worth looking for, deduplicated.
     *
     * Tokenised the way `websearch_to_tsquery` reads the same string, because
     * the box accepts its syntax and the two have to agree about what the user
     * asked for:
     *
     *   - a `"quoted phrase"` is ONE term, matched as written — that is the
     *     whole point of quoting it;
     *   - a term starting with `-` is a NEGATION, and marking a word the user
     *     asked to exclude would be actively wrong, so it is dropped;
     *   - `or` is websearch's alternation keyword rather than a word to look
     *     for, and it needs no special case: it is two characters and so falls
     *     under MIN_TERM_LENGTH already.
     *
     * Leading and trailing punctuation comes off — someone who types
     * `chargecloud,` means the word, and `websearch_to_tsquery` would have
     * tokenised away the comma too. Inner punctuation stays, so `chargecloud.de`
     * typed in full still finds itself.
     *
     * @return list<string>
     */
    private function terms(string $freeText): array
    {
        // Quoted alternative first, so a phrase wins over its own words. On
        // invalid UTF-8 preg_match_all answers false and there is simply
        // nothing to mark — the same shrug toHtml() makes for a broken body.
        if (false === preg_match_all('/"([^"]+)"|(\S+)/u', $freeText, $matches, \PREG_SET_ORDER)) {
            return [];
        }

        $terms = [];

        foreach ($matches as $match) {
            $raw = '' !== ($match[1] ?? '') ? $match[1] : ($match[2] ?? '');

            if (true === str_starts_with($raw, '-')) {
                continue;
            }

            $term = preg_replace('/^[^\p{L}\p{N}]+|[^\p{L}\p{N}]+$/u', '', $raw) ?? $raw;

            if (mb_strlen($term) < self::MIN_TERM_LENGTH) {
                continue;
            }

            // Keyed case-insensitively: "Chargecloud chargecloud" is one term,
            // and marking it twice over the same characters is a no-op that
            // only costs a pass.
            $terms[mb_strtolower($term)] = $term;
        }

        return array_values($terms);
    }

    /**
     * Where the first hit is and how long it is, in CHARACTERS, or null.
     *
     * Characters and not bytes throughout, and the two must not be mixed: an
     * offset from `mb_stripos` fed to `substr` lands in the middle of a
     * multi-byte sequence, which on a German body is not an edge case but the
     * common one. Every consumer of this offset slices with `mb_substr`.
     *
     * @param list<string> $terms
     *
     * @return array{int, int}|null
     */
    private function earliestOccurrence(string $text, array $terms): ?array
    {
        $earliest = null;

        foreach ($terms as $term) {
            $at = mb_stripos($text, $term);

            if (false === $at) {
                continue;
            }

            if (null === $earliest || $at < $earliest[0]) {
                $earliest = [$at, mb_strlen($term)];
            }
        }

        return $earliest;
    }

    /**
     * The text around one hit, sized like a `ts_headline` fragment.
     *
     * Split at the hit and count words outward from there, rather than slicing
     * a character window and repairing its ends. The hit is usually INSIDE a
     * word — that is the entire reason this code exists — so the last piece
     * before it and the first piece after it are two halves of one token, and
     * they are carried whole: cutting `https://…/company=` back to `company=`
     * would hide the link the reader needs to recognise.
     *
     * `explode`/`implode` round-trip exactly, which is what makes this precise:
     * a run of whitespace splits into empty elements that implode back to a
     * single space, so "did the ends lose anything" is a plain count
     * comparison, and the collapsing is the same normalisation oneLine() does
     * for the primary path a moment later.
     *
     * `/\s+/` without the `u` modifier on purpose, twinning the reasoning in
     * toHtml(): whitespace is ASCII, a whitespace byte cannot be part of a
     * multi-byte sequence, and `u` would make the split return false on exactly
     * the malformed bodies that most need a readable preview.
     */
    private function window(string $text, int $at, int $length): string
    {
        $before = mb_substr($text, 0, $at);
        $match  = mb_substr($text, $at, $length);
        $after  = mb_substr($text, $at + $length);

        $beforeWords = preg_split('/\s+/', $before) ?: [''];
        $afterWords  = preg_split('/\s+/', $after) ?: [''];

        $keptBefore = $this->fit(array_slice($beforeWords, -self::WORDS_BEFORE), dropFirst: true);
        $keptAfter  = $this->fit(array_slice($afterWords, 0, self::WORDS_AFTER), dropFirst: false);

        $head = count($keptBefore) < count($beforeWords) ? self::ELLIPSIS.' ' : '';
        $tail = count($keptAfter) < count($afterWords) ? ' '.self::ELLIPSIS : '';

        return $head.implode(' ', $keptBefore).$match.implode(' ', $keptAfter).$tail;
    }

    /**
     * Drop whole words off one end until the side is under MAX_CONTEXT_CHARS.
     *
     * Off the far end — the front of the lead-in, the back of the tail — so
     * what survives is the context ADJACENT to the match, which is the context
     * that explains it.
     *
     * @param list<string> $words
     *
     * @return list<string>
     */
    private function fit(array $words, bool $dropFirst): array
    {
        while ([] !== $words && mb_strlen(implode(' ', $words)) > self::MAX_CONTEXT_CHARS) {
            if (true === $dropFirst) {
                array_shift($words);
            } else {
                array_pop($words);
            }
        }

        return array_values($words);
    }

    /**
     * Every occurrence of every term in the fragment, wrapped in sentinels.
     *
     * Every one, not just the anchor: a fragment plainly containing the word
     * twice with one of them marked reads as the highlighter having given up
     * halfway, and Gmail marks them all.
     *
     * Overlaps are resolved by keeping the earlier span — two terms that share
     * characters, `charge` and `argecloud`, would otherwise produce a sentinel
     * opened inside a pair that is already open, and toHtml()'s balanced-pair
     * conversion would then drop one of them. Sorted first so "earlier" is
     * meaningful across terms.
     *
     * The span length is the TERM's length rather than anything mbstring
     * reports about the hit. Simple case folding does not change how many
     * characters a match spans, and if that ever stopped holding the cost is a
     * mark sitting a character off — still a balanced pair, so still never
     * structure escaping into the page.
     *
     * @param list<string> $terms
     */
    private function mark(string $fragment, array $terms): ?string
    {
        $spans = [];

        foreach ($terms as $term) {
            $length = mb_strlen($term);
            $from   = 0;

            while (false !== ($at = mb_stripos($fragment, $term, $from))) {
                $spans[] = [$at, $length];
                $from    = $at + $length;
            }
        }

        // The anchor was found in the full text, so a window built around it
        // holds at least one hit. Null here would mean the two disagreed, and
        // an unmarked fragment is worse than no fragment: it is the opening of
        // the body again, dressed up as an explanation.
        if ([] === $spans) {
            return null;
        }

        usort($spans, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $marked = '';
        $cursor = 0;

        foreach ($spans as [$at, $length]) {
            if ($at < $cursor) {
                continue;
            }

            $marked .= mb_substr($fragment, $cursor, $at - $cursor)
                .self::START.mb_substr($fragment, $at, $length).self::STOP;

            $cursor = $at + $length;
        }

        return $marked.mb_substr($fragment, $cursor);
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
