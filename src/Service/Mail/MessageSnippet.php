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
 * Costs no query. The row already hydrates the whole Message to read
 * `bodyText`, so the HTML is in memory either way.
 */
final class MessageSnippet
{
    /** Enough for the widest row; the template truncates for narrower ones. */
    private const int LENGTH = 200;

    public function of(?Message $message): string
    {
        if (null === $message) {
            return '';
        }

        $fromHtml = $this->flatten((string) $message->bodyHtmlSafe);

        if ('' !== $fromHtml) {
            return $fromHtml;
        }

        // No rendered body — a plain-text mail, or one whose HTML sanitised
        // down to nothing. The text part is all there is, and it is still run
        // through the same flattening, because a sender who put markup in it is
        // exactly the case that made this necessary.
        return $this->flatten((string) $message->bodyText);
    }

    /**
     * Markup to a single line of readable text.
     *
     * Block-level tags become spaces before the rest are stripped, or the last
     * word of one paragraph runs into the first of the next — "Hallo PaulWie
     * geht es dir" is the shape of that mistake, and it looks like a data
     * problem rather than a rendering one.
     */
    private function flatten(string $html): string
    {
        if ('' === trim($html)) {
            return '';
        }

        $spaced = (string) preg_replace(
            '#<(br|/p|/div|/tr|/td|/li|/h[1-6])[^>]*>#i',
            ' ',
            $html,
        );

        $text = html_entity_decode(strip_tags($spaced), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Non-breaking spaces included: mail is full of them, and a run of
        // them left in place reads as a gap in the middle of the preview.
        $text = (string) preg_replace('/[\s\x{00A0}]+/u', ' ', $text);
        $text = trim($text);

        return mb_substr($text, 0, self::LENGTH);
    }
}
