<?php

declare(strict_types=1);

namespace App\Domain\Helper;

use App\Entity\Mail\Message;

/**
 * The words of a message, for code that reads mail rather than shows it.
 *
 * The plain-text part where the sender sent one — and where they did not, the
 * HTML with its markup taken away. That second half is why this exists. Every
 * insight extractor read `bodyText` and nothing else, and a shop's shipping
 * notice is very often HTML only: the tracking number was on the page, in a
 * table cell, and the extractor was handed the empty string. The report that
 * was filed about it carried an empty body too, for the same reason.
 *
 * The plain part still comes first when there is one. It is what the sender
 * wrote for exactly this purpose, and the fixtures every extractor is tested
 * against are plain text.
 *
 * WHAT IS REMOVED is more than tags. A mail's HTML carries a stylesheet, and
 * Outlook's conditional comments carry a second one; neither is prose, and a
 * stylesheet is full of numbers. So `<style>`, `<script>`, `<head>` and
 * comments go with their contents. This starts from the raw HTML when the
 * sanitised copy is not there yet — extraction can run before it is built —
 * which is why it does that removal itself rather than trusting it was done.
 *
 * WHAT IS KEPT is the line structure: the end of a paragraph, a table cell or
 * a row becomes a line break, because extractors read "label, then value" and
 * a table laid out as one long line puts every value beside every label. Link
 * targets are not kept. The address behind a link is where a tracker's own
 * ids live, and a twenty-digit run inside a click-tracking URL would be read
 * as a parcel.
 *
 * Static, like the other helpers here: it holds nothing and decides nothing
 * that could differ between two callers.
 */
final class ReadableBody
{
    public static function of(Message $message): string
    {
        $text = trim((string) $message->bodyText);

        if ('' !== $text) {
            return $text;
        }

        return self::fromHtml((string) ($message->bodyHtmlSafe ?? $message->bodyHtml));
    }

    public static function fromHtml(string $html): string
    {
        if ('' === trim($html)) {
            return '';
        }

        $html = (string) preg_replace('~<!--.*?-->~s', ' ', $html);
        $html = (string) preg_replace('~<(style|script|head|title)\b[^>]*>.*?</\1\s*>~is', ' ', $html);

        // Block ends become line breaks; anything else simply goes, so that
        // "<strong>Sendungs</strong>nummer" stays one word.
        $html = (string) preg_replace('~<(br|/p|/div|/tr|/td|/th|/li|/h[1-6])\b[^>]*>~i', "\n", $html);

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Non-breaking and zero-width spaces: the first would sit between a
        // date and its time and hold them further apart than a parser allows,
        // the second is what senders pad an empty paragraph with.
        $text = str_replace(["\u{00A0}", "\u{200B}", "\u{FEFF}"], [' ', '', ''], $text);

        $lines = [];

        foreach (preg_split('~\R~u', $text) ?: [] as $line) {
            $line = trim((string) preg_replace('~[ \t]+~', ' ', $line));

            if ('' !== $line) {
                $lines[] = $line;
            }
        }

        return implode("\n", $lines);
    }
}
