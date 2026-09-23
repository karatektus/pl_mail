<?php

declare(strict_types=1);

namespace App\Tests\Service\Search;

use App\Service\Search\SearchHighlighter;
use PHPUnit\Framework\TestCase;

/**
 * The fragment the highlighter builds when Postgres could not build one.
 *
 * SearchHighlightTest already proves the feature end to end against the real
 * parser, which is where "does `ts_headline` mark this" has to be answered. The
 * cases here are the ones that are pure PHP and expensive to provoke through a
 * rendered page: where the window's edges fall, which occurrences get marked,
 * and which terms are not terms at all.
 *
 * Every assertion goes through toHtml(), deliberately. The sentinels are an
 * implementation detail of one class and asserting on them would let a fallback
 * that skipped the escape pass look correct — the thing that matters is what
 * reaches the page.
 */
final class SearchHighlighterTest extends TestCase
{
    private SearchHighlighter $highlighter;

    protected function setUp(): void
    {
        $this->highlighter = new SearchHighlighter();
    }

    /** `ts_headline`'s answer is used whenever it has one. */
    public function testAHeadlineWithAMarkerIsPreferredOverAnythingBuiltHere(): void
    {
        $html = $this->fallback(
            "a \x02hit\x03 Postgres found",
            'chargecloud',
            'a different body mentioning chargecloud once',
        );

        self::assertSame('a <mark>hit</mark> Postgres found', $html);
    }

    /**
     * The gap itself, in miniature: a term inside a `host` token.
     */
    public function testATermInsideACompoundTokenIsMarkedWhereTheHeadlineIsEmpty(): void
    {
        $html = $this->fallback(null, 'chargecloud', 'Neue Jobs bei chargecloud.de und anderen Firmen');

        self::assertSame('Neue Jobs bei <mark>chargecloud</mark>.de und anderen Firmen', $html);
    }

    /**
     * Offsets come from mb_stripos and are used with mb_substr.
     *
     * Umlauts before the match make every byte offset in the string disagree
     * with its character offset. Mixing the two slices through the middle of a
     * multi-byte sequence, and the visible symptom is a mark that has slid a
     * character or two to the left — on a German mailbox, which is most of
     * this one, that is the ordinary case and not an exotic one.
     */
    public function testAccentedTextBeforeTheMatchDoesNotShiftTheMark(): void
    {
        $html = $this->fallback(null, 'chargecloud', 'Grüße für Jürgen über chargecloud.de heute');

        self::assertSame('Grüße für Jürgen über <mark>chargecloud</mark>.de heute', $html);
    }

    /** Case-insensitive, and the text's own casing survives being marked. */
    public function testTheSearchIsCaseInsensitiveAndKeepsTheTextsOwnCasing(): void
    {
        $html = $this->fallback(null, 'CHARGECLOUD', 'siehe ChargeCloud.de dort');

        self::assertSame('siehe <mark>ChargeCloud</mark>.de dort', $html);
    }

    /**
     * Every occurrence in the window, not just the anchor.
     */
    public function testEveryOccurrenceInTheWindowIsMarked(): void
    {
        $html = $this->fallback(null, 'chargecloud', 'a chargecloud.de and b chargecloud.de too');

        self::assertSame(2, substr_count((string) $html, '<mark>chargecloud</mark>'));
    }

    /**
     * A long body is windowed to roughly what `ts_headline` would have given,
     * with the same ellipsis at both cut ends and no word cut through.
     */
    public function testALongBodyIsWindowedAroundTheMatchWithEllipsesAtTheCuts(): void
    {
        $filler = trim(str_repeat('filler ', 40));
        $html   = $this->fallback(null, 'chargecloud', $filler.' hit chargecloud.de here '.$filler);

        self::assertStringStartsWith('… filler', $html ?? '');
        self::assertStringEndsWith('filler …', $html ?? '');
        self::assertStringContainsString('hit <mark>chargecloud</mark>.de here', $html ?? '');
        self::assertLessThanOrEqual(
            24,
            substr_count((string) $html, ' '),
            'the fragment should be about the 24 words ts_headline works to',
        );
    }

    /**
     * Multi-word queries mark ANY term that occurs, not only queries where all
     * of them do — which is what `ts_headline` itself does with an AND query,
     * and this path is only reached when it found none of them.
     */
    public function testAMultiWordQueryMarksTheTermsThatOccurAndIgnoresTheRest(): void
    {
        $html = $this->fallback(null, 'chargecloud rechnung zzznothere', 'Die rechnung von chargecloud.de kommt');

        self::assertSame('Die <mark>rechnung</mark> von <mark>chargecloud</mark>.de kommt', $html);
    }

    /**
     * A quoted phrase is one term, so its words are not marked apart.
     */
    public function testAQuotedPhraseIsMatchedWholeRatherThanWordByWord(): void
    {
        $html = $this->fallback(null, '"neue jobs"', 'Hier sind neue jobs und alte jobs');

        self::assertSame('Hier sind <mark>neue jobs</mark> und alte jobs', $html);
    }

    /**
     * A negated term is one the user asked to EXCLUDE. Marking it would say the
     * opposite of what was typed.
     */
    public function testANegatedTermIsNeverMarked(): void
    {
        $html = $this->fallback(null, 'chargecloud -rechnung', 'Die rechnung von chargecloud.de kommt');

        self::assertSame('Die rechnung von <mark>chargecloud</mark>.de kommt', $html);
    }

    /**
     * A term below MIN_TERM_LENGTH matches inside half the words on the page,
     * so it is not marked at all and its row keeps the ordinary preview.
     */
    public function testATermTooShortToBeWorthMarkingProducesNoFragment(): void
    {
        self::assertNull($this->fallback(null, 'de', 'Die rechnung von chargecloud.de kommt'));
    }

    /**
     * A row found on the sender, on a meaning match or on an operator has no
     * fragment here, and must not be given one.
     */
    public function testATermAbsentFromTheTextProducesNoFragment(): void
    {
        self::assertNull($this->fallback(null, 'chargecloud', 'Nothing in this body says it'));
        self::assertNull($this->fallback(null, '', 'chargecloud is right here'));
        self::assertNull($this->fallback(null, 'chargecloud', null));
    }

    /**
     * The v0.2.34 property, on the new path.
     *
     * A fallback that concatenated `<mark>` onto raw text would satisfy every
     * other test in this file. This is the one that distinguishes it from one
     * that escapes first and marks second.
     */
    public function testMarkupInTheMatchedTextIsEscapedAndNotRendered(): void
    {
        $html = $this->fallback(
            null,
            'chargecloud',
            'Anbei <img src=x onerror=alert(1)> siehe chargecloud.de heute',
        );

        self::assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', (string) $html);
        self::assertStringNotContainsString('<img', (string) $html);
        self::assertStringContainsString('<mark>chargecloud</mark>', (string) $html);
    }

    /**
     * A stray sentinel in a body cannot open a tag that never closes — the same
     * guarantee toHtml() already gives `ts_headline` output, now also for text
     * this class windowed itself.
     */
    public function testAStraySentinelInTheBodyCannotEscapeAsStructure(): void
    {
        $html = $this->fallback(null, 'chargecloud', "Anbei \x02 siehe chargecloud.de heute");

        self::assertStringNotContainsString("\x02", (string) $html);
        self::assertSame(1, substr_count((string) $html, '<mark>'));
        self::assertSame(1, substr_count((string) $html, '</mark>'));
    }

    /** The whole pipeline a caller uses, in one call. */
    /**
     * A `ts_headline` fragment is cut down until the mark is on screen.
     *
     * `MaxWords` counts TOKENS, and one LinkedIn tracking link is a single
     * token of some four hundred characters — so the 24 words the options ask
     * for came back as 1148 characters with the mark at 568. The preview is one
     * truncated line, about a hundred characters wide, so the highlight was in
     * the markup and 468 characters past the right-hand edge. Reported as
     * "highlighting does not work on LinkedIn mails", and it looked exactly
     * like that.
     *
     * Measured from the real message rather than imagined: the offsets above
     * are what Postgres returned for that mail.
     */
    public function testALongFragmentIsRecutSoTheMarkIsNearTheStart(): void
    {
        $url  = 'https://www.linkedin.com/comm/jobs/view/4435156777/trackingId='
            .str_repeat('LJ4zIajT9uQ4ijBHJxfQrefIdrQiZmsOSTBeOtSahZ9u0WAlipiurnli', 8);
        $lead = 'Backend Developer PHP Laravel GOLDNER GmbH Muenchberg '.$url.' ';

        // The shape ts_headline hands back: a lead-in of whole tokens, the
        // marked term, then more of the same.
        $headline = $lead."\x02Sandstein\x03".' Neue Medien GmbH Dresden '.$url;

        self::assertGreaterThan(
            500,
            mb_strpos($headline, "\x02"),
            'the fixture has to reproduce a mark that starts far off screen',
        );

        $html = $this->fallback($headline, 'sandstein', null);

        self::assertNotNull($html);
        self::assertStringContainsString('<mark>Sandstein</mark>', $html, 'the mark must survive the cut');

        $markAt = mb_strpos($html, '<mark>');
        self::assertIsInt($markAt);
        self::assertLessThan(
            120,
            $markAt,
            'the mark has to land inside the hundred or so characters a truncated row shows',
        );
    }

    private function fallback(?string $headline, string $freeText, ?string $text): ?string
    {
        return $this->highlighter->toHtml(
            $this->highlighter->headlineOrFallback($headline, $text, $freeText),
        );
    }
}
