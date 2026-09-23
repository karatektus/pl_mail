<?php

declare(strict_types=1);

namespace App\Service\Mail;

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
 * `bodyHtmlSafe`, never `bodyHtml`: the sanitised copy is the one the reading
 * path trusts, and stripping tags off raw sender HTML would put script text and
 * style rules into a list row.
 *
 * Costs no query of its own. The list fetches the two body columns of each
 * row's latest message only — see App\Service\Mail\ThreadRows.
 */
final class MessageSnippet
{
    /** Enough for the widest row; the template truncates for narrower ones. */
    private const int LENGTH = 200;

    /**
     * How long a block has to be before the preview is willing to start there.
     *
     * Bulk mail does not open with its message. It opens with "View in browser",
     * a logo's alt text, a nav bar, an unsubscribe link, a greeting — a run of
     * short blocks the reader's eye skips and the preview did not, so the list
     * showed the chrome and not the mail. Gmail starts at the sentence; this is
     * the rule that does the same without a list of known-bad strings, which is
     * what the note above this class refuses and is right to refuse: such a list
     * is wrong in every language nobody thought of.
     *
     * Forty characters and at least one space, so the test is "does this look
     * like prose" rather than "do I recognise it". A logo alt, a nav item and an
     * unsubscribe line all fail it in any language; a URL on its own fails it
     * for want of a space. A greeting fails it too, which is deliberate — "Hallo
     * Paul," tells the reader nothing they cannot see in the sender column.
     *
     * When NOTHING clears the bar the whole text is used exactly as before. That
     * is the case of a genuinely short message — "Sounds good, see you then" —
     * and it must read the same as it always has rather than come back empty.
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

    public function of(?Message $message): string
    {
        if (null === $message) {
            return '';
        }

        return $this->fromBodies($message->bodyHtmlSafe, $message->bodyText);
    }

    /**
     * The same preview from the two columns alone, for a caller that fetched
     * them without hydrating the Message — see App\Service\Mail\ThreadRows.
     */
    public function fromBodies(?string $bodyHtmlSafe, ?string $bodyText): string
    {
        $fromHtml = $this->flatten((string) $bodyHtmlSafe);

        if ('' !== $fromHtml) {
            return $fromHtml;
        }

        // No rendered body — a plain-text mail, or one whose HTML sanitised
        // down to nothing. The text part is all there is, and it is still run
        // through the same flattening, because a sender who put markup in it is
        // exactly the case that made this necessary.
        return $this->flatten((string) $bodyText);
    }

    /**
     * Markup to a single line of readable text, starting where the mail does.
     *
     * Block-level tags become a separator before the rest are stripped, or the
     * last word of one paragraph runs into the first of the next — "Hallo
     * PaulWie geht es dir" is the shape of that mistake, and it looks like a
     * data problem rather than a rendering one. They used to become a space
     * directly; they become [self::BLOCK] now so that the boundaries survive
     * long enough for [self::fromProse] to read them, and are spaces again by
     * the time anything is returned.
     */
    private function flatten(string $html): string
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

        $blocks = array_values(array_filter(
            array_map(trim(...), explode(self::BLOCK, $text)),
            static fn (string $block): bool => '' !== $block,
        ));

        return mb_substr($this->fromProse($blocks), 0, self::LENGTH);
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
        // the whole thing is the preview, exactly as it was before any of this.
        return implode(' ', $blocks);
    }
}
