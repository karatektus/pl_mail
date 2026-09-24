<?php

declare(strict_types=1);

namespace App\Infrastructure\Imap;

use App\Domain\Helper\CharsetHelper;
use Webklex\PHPIMAP\Decoder\MessageDecoder;

/**
 * The library's body decoder, with one correction: a part that declares a
 * single-byte charset and carries UTF-8 is read as UTF-8.
 *
 * IMAP bodies never pass through CharsetHelper — webklex converts them itself,
 * inside Message::fetchPart(), from whatever the part declared. So a sender
 * that composes in UTF-8 and stamps the part `charset=ISO-8859-1` — which is
 * common, and what German mail arriving as "GrÃ¼ÃŸe" is every time — was
 * converted from a charset it was never in, and the damage was done before any
 * of this application saw the text. Re-syncing could not repair it either: the
 * bytes on the server were always correct, and this conversion ran again.
 *
 * Overriding conversion rather than detection is deliberate. getEncoding() is
 * handed the part structure and never sees the content, so it cannot know the
 * declaration is contradicted; convertEncoding() is handed both, which is
 * exactly what the judgement needs. See CharsetHelper::isUtf8Despite() for why
 * this is a contradiction rather than a guess.
 *
 * And the opposite lie, which the library lets through untouched: it converts
 * nothing when the label already names the target, so a part that says UTF-8
 * is passed on as it came, and so is one that says us-ascii. One windows-1252
 * "€" in such a part was invalid UTF-8 in body_html, and Postgres refused the
 * INSERT and its whole batch with it. Whatever the library hands back is put
 * past CharsetHelper::ensureUtf8() for that reason, the guard every other
 * route into a UTF-8 column already goes through.
 */
final class Utf8AwareMessageDecoder extends MessageDecoder
{
    public function convertEncoding($str, string $from = 'ISO-8859-2', string $to = 'UTF-8'): mixed
    {
        $toUtf8 = true === in_array(strtolower($to), ['utf-8', 'utf8'], true);

        if (
            true === is_string($str)
            && true === $toUtf8
            && true === CharsetHelper::isUtf8Despite($str, $from)
        ) {
            return $str;
        }

        $converted = parent::convertEncoding($str, $from, $to);

        if (true === is_string($converted) && true === $toUtf8) {
            return CharsetHelper::ensureUtf8($converted);
        }

        return $converted;
    }
}
