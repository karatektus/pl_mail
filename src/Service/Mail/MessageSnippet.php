<?php

declare(strict_types=1);

namespace App\Service\Mail;

use App\Domain\Enum\Mail\MessageCategory;
use App\Entity\Mail\Message;

/**
 * The line of preview text under a subject in the list.
 *
 * It used to be `bodyText` verbatim, and that field is the sender's own
 * `text/plain` part — which is not a summary of the mail, it is whatever the
 * sender chose to put in the part most of their recipients will never see. Two
 * ways that goes wrong were reported from the same inbox on the same day:
 *
 *   • "Email is only available as html" as the preview of most rows. Not a bug
 *     in the parser — that IS the text part, written by a bulk sender who put a
 *     sentence there instead of the message. Opening the mail shows the real
 *     text, so the preview was the only thing lying.
 *
 *   • `<p style="margin:0 0 16px 0;"> Hallo, ich möchte…` — raw markup as
 *     visible text, from a sender who put HTML in the text part.
 *
 * Preferring the HTML fixes both without guessing at either. There is no
 * heuristic here that tries to recognise a placeholder sentence: the rule is
 * simply that when a mail has a rendered body, the preview is made of what the
 * reader would see, which is what Gmail does and the only definition that does
 * not need a list of known-bad strings.
 *
 * And of what the sender wrote THIS time (#257). A reply is mostly the mail it
 * answers, so a preview of the whole body was a preview of the quote: a
 * one-line "Passt!" was skipped as too short to be prose and the row showed
 * "Am … schrieb …:" instead, or the reader's own words from the mail before.
 * The reply history is cut off first — at the seam QuoteCollapser finds for
 * the "···" in the reading view, so the two never disagree — and then the
 * signature, at its `-- ` line.
 *
 * `bodyHtmlSafe`, never `bodyHtml`: the sanitised copy is the one the reading
 * path trusts, and stripping tags off raw sender HTML would put script text and
 * style rules into a list row.
 *
 * Costs no query of its own. The list fetches the two body columns and the
 * category of each row's latest message only — see App\Service\Mail\ThreadRows.
 */
final readonly class MessageSnippet
{
    /** Enough for the widest row; the template truncates for narrower ones. */
    private const int LENGTH = 200;

    /**
     * How long a block has to be before the preview of BULK mail is willing to
     * start there.
     *
     * Bulk mail does not open with its message. It opens with "View in browser",
     * a logo's alt text, a nav bar, an unsubscribe link — a run of short blocks
     * the reader's eye skips and the preview did not, so the list showed the
     * chrome and not the mail. Gmail starts at the sentence; this is the rule
     * that does the same without a list of known-bad strings, which is what the
     * note above this class refuses and is right to refuse: such a list is
     * wrong in every language nobody thought of.
     *
     * Forty characters and at least one space, so the test is "does this look
     * like prose" rather than "do I recognise it". A logo alt, a nav item and an
     * unsubscribe line all fail it in any language; a URL on its own fails it
     * for want of a space.
     *
     * Bulk mail only — see [self::skipsChrome]. A person's mail has no chrome
     * to skip, and there the rule did harm: "Passt." over a long signature line
     * skipped the message and previewed the company address.
     *
     * When NOTHING clears the bar the whole text is used. That is the case of a
     * genuinely short message, and it must read as itself rather than come
     * back empty.
     */
    private const int PROSE_MIN = 40;

    /**
     * Where a block ended, while the text is still in pieces.
     *
     * A unit separator rather than a newline: this has to survive the whitespace
     * collapse below, which would otherwise fold the block boundaries away
     * before anything could read them, and it cannot occur in the text of a mail
     * that has been through the sanitiser.
     */
    private const string BLOCK = "\x1F";

    /** The signature delimiter of RFC 3676 §4.3, once trimmed: "-- ". */
    private const string SIGNATURE = '--';

    public function __construct(private QuoteCollapser $quotes)
    {
    }

    public function of(?Message $message): string
    {
        if (null === $message) {
            return '';
        }

        return $this->fromBodies($message->bodyHtmlSafe, $message->bodyText, $message->category);
    }

    /**
     * The same preview from the columns alone, for a caller that fetched them
     * without hydrating the Message — see App\Service\Mail\ThreadRows.
     */
    public function fromBodies(?string $bodyHtmlSafe, ?string $bodyText, ?MessageCategory $category = null): string
    {
        // An encrypted message has no body here to preview — the server keeps
        // none, unless the account keeps decrypted mail and a browser has
        // handed it over (DecryptedMail) — so its preview is empty. It used to
        // be "Encrypted message", appended after the subject; the row now says
        // that with a lock in front of the subject (ThreadRow::$encrypted), in
        // the place a reader looks first, and the preview line is left to
        // what the message says when the server has it.
        $skip     = self::skipsChrome($category);
        $fromHtml = $this->flatten($this->quotes->withoutQuote((string) $bodyHtmlSafe), $skip);

        if ('' !== $fromHtml) {
            return $fromHtml;
        }

        // No rendered body — a plain-text mail, or one whose HTML sanitised
        // down to nothing. The text part is all there is, and it is still run
        // through the same flattening, because a sender who put markup in it is
        // exactly the case that made this necessary. Its lines are its blocks:
        // the newline becomes the separator before anything strips tags, and a
        // newline inside a tag goes with the tag.
        $text = str_replace(["\r\n", "\r", "\n"], self::BLOCK, self::withoutTextQuote((string) $bodyText));

        return $this->flatten($text, $skip);
    }

    /**
     * Whether the preview may skip its way in past short leading blocks.
     *
     * Only for mail sorted as machine-sent — promotions, updates, social
     * notifications — because that is the mail that has chrome. Primary and
     * Forums are people writing, an uncategorised mail is assumed to be, and
     * people's mail starts where they started it: greeting included, the way
     * Gmail and Apple Mail show it.
     */
    private static function skipsChrome(?MessageCategory $category): bool
    {
        return match ($category) {
            MessageCategory::Promotions, MessageCategory::Updates, MessageCategory::Social => true,
            default => false,
        };
    }

    /**
     * A plain-text body with its reply history cut off.
     *
     * The plain-text counterpart of QuoteCollapser, which only ever sees HTML.
     * The quote starts at the first `>` line, and the line that introduced it
     * goes too when it ends in a colon — "On … wrote:", "Am … schrieb …:", in
     * whatever language, without having to know the words. Outlook's header
     * block and "-----Original Message-----" carry no `>`, so they are caught
     * by the same words the HTML side uses.
     *
     * As there, nothing is cut unless something is left: a body that is all
     * quote comes back whole.
     */
    private static function withoutTextQuote(string $text): string
    {
        $lines = preg_split('/\R/u', $text) ?: [];
        $cut   = null;

        foreach ($lines as $index => $line) {
            $trimmed = trim($line);

            if (str_starts_with($trimmed, '>')) {
                $cut = $index;

                // Back over blank lines to the attribution, if there is one.
                $before = $index - 1;

                while ($before >= 0 && '' === trim($lines[$before])) {
                    --$before;
                }

                if ($before >= 0 && str_ends_with(rtrim($lines[$before]), ':')) {
                    $cut = $before;
                }

                break;
            }

            if (1 === preg_match('/^-{2,}\s*(original message|ursprüngliche nachricht|forwarded message)\b/iu', $trimmed)
                || self::startsOutlookHeader($lines, $index)) {
                $cut = $index;

                break;
            }
        }

        if (null === $cut) {
            return $text;
        }

        $kept = implode("\n", array_slice($lines, 0, $cut));

        return '' === trim($kept) ? $text : $kept;
    }

    /**
     * "From:" / "Von:" followed within a few lines by a date — the top of the
     * header block Outlook writes above a quote. Two labels agreeing, because
     * "Von:" alone is an ordinary way to start a German sentence.
     *
     * @param list<string> $lines
     */
    private static function startsOutlookHeader(array $lines, int $index): bool
    {
        if (1 !== preg_match('/^\s*\**(from|von)\**\s*:/iu', $lines[$index])) {
            return false;
        }

        foreach (array_slice($lines, $index + 1, 4) as $line) {
            if (1 === preg_match('/^\s*\**(sent|date|gesendet|datum)\**\s*:/iu', $line)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Markup to a single line of readable text.
     *
     * Block-level tags become a separator before the rest are stripped, or the
     * last word of one paragraph runs into the first of the next — "Hallo
     * PaulWie geht es dir" is the shape of that mistake, and it looks like a
     * data problem rather than a rendering one. They used to become a space
     * directly; they become [self::BLOCK] now so that the boundaries survive
     * long enough for the signature cut and [self::fromProse] to read them,
     * and are spaces again by the time anything is returned.
     */
    private function flatten(string $html, bool $skipChrome): string
    {
        if ('' === trim($html)) {
            return '';
        }

        $split = (string) preg_replace(
            '#<(br|/p|/div|/tr|/td|/li|/h[1-6])[^>]*>#i',
            self::BLOCK,
            $html,
        );

        $text = html_entity_decode(strip_tags($split), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Non-breaking spaces included: mail is full of them, and a run of
        // them left in place reads as a gap in the middle of the preview.
        // The separator is excluded from the class so the blocks survive.
        $text = (string) preg_replace('/[^\S\x{1F}\x{00A0}]|\x{00A0}/u', ' ', $text);
        $text = (string) preg_replace('/ +/', ' ', $text);

        $blocks = self::withoutSignature(array_values(array_filter(
            array_map(trim(...), explode(self::BLOCK, $text)),
            static fn (string $block): bool => '' !== $block,
        )));

        $line = true === $skipChrome ? $this->fromProse($blocks) : implode(' ', $blocks);

        return mb_substr($line, 0, self::LENGTH);
    }

    /**
     * The blocks up to the signature delimiter.
     *
     * A block that is the delimiter and nothing else; one that merely starts
     * with two dashes is a sentence. Not when it is the first block: a body
     * that is only a signature still previews as something.
     *
     * @param list<string> $blocks
     *
     * @return list<string>
     */
    private static function withoutSignature(array $blocks): array
    {
        $at = array_search(self::SIGNATURE, $blocks, true);

        if (false === $at || 0 === $at) {
            return $blocks;
        }

        return array_slice($blocks, 0, $at);
    }

    /**
     * The blocks from the first one that reads like prose, joined back together.
     *
     * See [self::PROSE_MIN] for why the preview may skip its way in. The blocks
     * BEFORE the first prose one are dropped rather than kept and truncated
     * away — the point is the two hundred characters that follow, and a row
     * that spent sixty of them on "View in browser" has not been helped by
     * starting further down.
     *
     * Everything from that point on is kept, including short blocks: a nav bar
     * that appears BELOW the first sentence is rare, while a one-word line in
     * the middle of a message is ordinary, and dropping those would rewrite the
     * mail rather than skip its chrome.
     *
     * @param list<string> $blocks
     */
    private function fromProse(array $blocks): string
    {
        foreach ($blocks as $index => $block) {
            // A space as well as a length, so that one long unbroken token —
            // a URL on its own line, a tracking id — cannot pass for a
            // sentence. Both tests are structural: they ask what this looks
            // like rather than whether it is recognised, which is the rule the
            // note on this class sets and the only one that holds in a language
            // nobody here has thought of.
            if (mb_strlen($block) >= self::PROSE_MIN && str_contains($block, ' ')) {
                return implode(' ', array_slice($blocks, $index));
            }
        }

        // Nothing looked like prose. A short mail is the ordinary case of that
        // — "Sounds good, see you then" clears no bar and IS the message — so
        // the whole thing is the preview.
        return implode(' ', $blocks);
    }
}
