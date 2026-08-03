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
 */
final class Utf8AwareMessageDecoder extends MessageDecoder
{
    public function convertEncoding($str, string $from = 'ISO-8859-2', string $to = 'UTF-8'): mixed
    {
        if (
            true === is_string($str)
            && 'utf-8' === strtolower($to)
            && true === CharsetHelper::isUtf8Despite($str, $from)
        ) {
            return $str;
        }

        return parent::convertEncoding($str, $from, $to);
    }
}
