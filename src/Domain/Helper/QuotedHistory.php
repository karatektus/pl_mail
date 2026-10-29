<?php

declare(strict_types=1);

namespace App\Domain\Helper;

use Dom\Element;
use Dom\HTMLDocument;

/**
 * The part of a reply that somebody else wrote, taken off.
 *
 * Code that reads mail for what the SENDER says — a date proposed in prose,
 * say — has to stop where the quoted history begins, or every reply in a
 * thread repeats the first mail's claims as its own. A meeting arranged in one
 * mail was proposed again for "Thanks!" underneath it, and again for the reply
 * to that, because each was a new message with the same sentence in it (#437).
 *
 * Reading line by line is not enough, and was what there was before. A filter
 * that drops lines starting with `>` and the "Am … schrieb …:" line above them
 * works for a client that still quotes that way. Most do not: Gmail, Outlook
 * and Apple Mail put the history of an HTML mail in a container, and with the
 * tags gone it is ordinary prose under an attribution; Outlook does the same
 * in plain text, under a `Von:/Gesendet:` block. So this cuts — from the start
 * of the history to the end of the mail — rather than filtering.
 *
 * Two passes, because the two shapes carry different evidence:
 *
 *   fromHtml() works on the RAW body. The sanitised copy has lost every class
 *   and id, and the ids are what Outlook marks its history with. A blockquote
 *   becomes a single `>` line instead of disappearing, so that what follows it
 *   is read the way the same mail in plain text would be (see below).
 *
 *   fromText() cuts at the first attribution, forward separator or forwarded
 *   header block — UNLESS the history under it is quoted with `>` and the
 *   sender wrote between or below the quotes. That is an interleaved or
 *   bottom-posted reply, and the answers are the sender's own words; the `>`
 *   lines are left for the reader's own line filter
 *   (DeterministicDateDetector::QUOTATION), which has always dropped them.
 *   Cutting there would lose every answer for nothing.
 *
 * Precision over recall, like the readers this serves: a forward is cut too,
 * although the invitation in it may be exactly what the user wants. A card that
 * did not appear is invisible; one that offers a meeting again after the user
 * refused it is the reason people switch the feature off.
 *
 * Static, like the other helpers here: it holds nothing and decides nothing
 * that could differ between two callers.
 */
final class QuotedHistory
{
    /**
     * Ids that mark where Outlook's history begins. Everything from there to
     * the end of the document is somebody else's mail: classic and web Outlook
     * put an empty `appendonsend` div, a rule and `divRplyFwdMsg` in front of
     * it, the new Outlook wraps it in the reference container.
     *
     * Language-independent, which is what the header-block rule in the text
     * pass is not — it would catch the German and English header block anyway,
     * but not the Dutch one.
     *
     * @var list<string>
     */
    private const array HISTORY_STARTS = [
        'appendonsend',
        'divRplyFwdMsg',
        'mail-editor-reference-message-container',
    ];

    /**
     * The verb an attribution is built around: "On … Paul wrote:", "Am …
     * schrieb Paul:", "Paul <…> schrieb am …:", "Le …, Paul a écrit :".
     */
    private const string ATTRIBUTION_VERB = '~(?:\bwrote\b|\bschrieb\b|\ba\s+écrit\b)~iu';

    /** The first word of an attribution that wrapped before its verb. */
    private const string ATTRIBUTION_OPENING = '~^\s*(?:on|am|le)\s~iu';

    /**
     * The separator a forward or an Outlook plain-text reply puts above the
     * history. The same list the detector filters by.
     */
    private const string SEPARATOR = '~^\s*-{2,}\s*(?:original|forwarded|urspr|weitergeleitete|message transf|message d.origine)~iu';

    /** The first line of a forwarded header block… */
    private const string HEADER_FROM = '~^\s*(?:from|von|de)\s*:~iu';

    /**
     * …and the lines that confirm it is one. `Date:`/`Datum:` belongs here
     * although the detector keeps it: on its own it is how German business
     * mail states an appointment, but under a `Von:` line it is a header.
     */
    private const string HEADER_FOLLOWING = '~^\s*(?:sent|gesendet|date|datum|envoy[ée]|to|an|à|cc|subject|betreff|objet)\s*:~iu';

    /** How many non-blank lines below `From:` the rest of its block may start. */
    private const int HEADER_REACH = 2;

    /** An attribution is a line of furniture, not a paragraph that mentions writing. */
    private const int MAX_ATTRIBUTION_CHARS = 400;

    /**
     * The HTML with its quoted history marked or removed, still as HTML.
     *
     * A document that cannot be parsed comes back as it was: the text pass
     * still sees its attribution and header block, which is most of the
     * protection, and refusing to read the mail at all would be a worse answer
     * to a parser's opinion of its markup.
     */
    public static function fromHtml(string $html): string
    {
        if ('' === trim($html)) {
            return $html;
        }

        $lower = strtolower($html);

        if (false === str_contains($lower, '<blockquote') && false === self::mentionsAHistoryStart($lower)) {
            return $html;
        }

        try {
            $document = HTMLDocument::createFromString($html, LIBXML_NOERROR, 'UTF-8');
        } catch (\Throwable) {
            return $html;
        }

        foreach (self::HISTORY_STARTS as $id) {
            $start = $document->getElementById($id);

            if (null !== $start) {
                self::cutFrom($start);
            }
        }

        // A static list, so replacing an outer quote before reaching the
        // quotes nested in it is harmless: those are already detached.
        foreach ($document->querySelectorAll('blockquote') as $quote) {
            $marker = $document->createElement('p');
            $marker->textContent = '>';
            $quote->replaceWith($marker);
        }

        return $document->saveHtml();
    }

    /** The text up to where its quoted history begins, or all of it when there is none. */
    public static function fromText(string $text): string
    {
        $lines = preg_split('~\R~u', $text) ?: [];

        foreach (array_keys($lines) as $index) {
            $start = self::historyStartingAt($lines, $index);

            if (null === $start) {
                continue;
            }

            if (true === self::carriesAnswersAmongQuotes($lines, $index)) {
                continue;
            }

            return rtrim(implode("\n", array_slice($lines, 0, $start)));
        }

        return $text;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private static function mentionsAHistoryStart(string $lowerHtml): bool
    {
        foreach (self::HISTORY_STARTS as $id) {
            if (true === str_contains($lowerHtml, strtolower($id))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The element and everything after it in document order, up to the body.
     *
     * Following siblings at every level, not just its own: the marker is
     * often nested in a wrapper, and the history is then the wrapper's next
     * sibling rather than the marker's.
     */
    private static function cutFrom(Element $start): void
    {
        $node = $start;

        while (false === ($node instanceof Element && 'BODY' === $node->tagName)) {
            $parent = $node->parentNode;

            if (null === $parent) {
                return;
            }

            // Text between blocks is a sibling too, and only an Element can
            // remove() itself, so the parent does it.
            while (null !== $node->nextSibling) {
                $parent->removeChild($node->nextSibling);
            }

            if ($node === $start) {
                $parent->removeChild($node);
            }

            $node = $parent;
        }
    }

    /**
     * Where the history begins, when line $index is what announces it.
     *
     * @param list<string> $lines
     */
    private static function historyStartingAt(array $lines, int $index): ?int
    {
        $line = $lines[$index];

        if (1 === preg_match(self::SEPARATOR, $line)) {
            return $index;
        }

        if (1 === preg_match(self::HEADER_FROM, $line) && true === self::isFollowedByHeaders($lines, $index)) {
            return $index;
        }

        return self::attributionStartingAt($lines, $index);
    }

    /**
     * An attribution ending on line $index, and the line it began on.
     *
     * It ends in a colon and names the verb, and it carries a date or an
     * address — "Wie Paul schrieb:" is a sentence, not a client's furniture.
     * Plain-text clients wrap it at 72 columns, so a line that opens with
     * "On"/"Am"/"Le" just above is the beginning of the same attribution.
     *
     * @param list<string> $lines
     */
    private static function attributionStartingAt(array $lines, int $index): ?int
    {
        $line = rtrim($lines[$index]);

        if (false === str_ends_with($line, ':') || 0 === preg_match(self::ATTRIBUTION_VERB, $line)) {
            return null;
        }

        $start    = $index;
        $previous = self::previousNonBlank($lines, $index);

        if (null !== $previous && 1 === preg_match(self::ATTRIBUTION_OPENING, $lines[$previous])) {
            $start = $previous;
            $line  = $lines[$previous] . ' ' . $line;
        }

        if (mb_strlen($line) > self::MAX_ATTRIBUTION_CHARS || 0 === preg_match('~[\d@]~', $line)) {
            return null;
        }

        return $start;
    }

    /** @param list<string> $lines */
    private static function isFollowedByHeaders(array $lines, int $index): bool
    {
        $seen = 0;

        for ($next = $index + 1; $next < count($lines) && $seen < self::HEADER_REACH; $next++) {
            if ('' === trim($lines[$next])) {
                continue;
            }

            if (1 === preg_match(self::HEADER_FOLLOWING, $lines[$next])) {
                return true;
            }

            $seen++;
        }

        return false;
    }

    /**
     * Whether the history announced on line $index is quoted with `>` and
     * has the sender's own lines among or after its quotes — an interleaved
     * or bottom-posted reply. See the class docblock.
     *
     * A history quoted with `>` and nothing else is cut like any other: there
     * is nothing under it to keep.
     *
     * @param list<string> $lines
     */
    private static function carriesAnswersAmongQuotes(array $lines, int $index): bool
    {
        $quoted = false;

        for ($next = $index + 1; $next < count($lines); $next++) {
            $line = ltrim($lines[$next]);

            if ('' === $line) {
                continue;
            }

            if (true === str_starts_with($line, '>')) {
                $quoted = true;

                continue;
            }

            if (false === $quoted
                && (1 === preg_match(self::HEADER_FROM, $line) || 1 === preg_match(self::HEADER_FOLLOWING, $line))) {
                continue;
            }

            return $quoted;
        }

        return false;
    }

    /** @param list<string> $lines */
    private static function previousNonBlank(array $lines, int $index): ?int
    {
        for ($previous = $index - 1; $previous >= 0; $previous--) {
            if ('' !== trim($lines[$previous])) {
                return $previous;
            }
        }

        return null;
    }
}
