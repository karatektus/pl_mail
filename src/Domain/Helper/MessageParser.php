<?php

declare(strict_types=1);

namespace App\Domain\Helper;

use App\Infrastructure\Imap\Utf8AwareMessageDecoder;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Message;

/**
 * Message::fromString(), with a repeated once-only header collapsed when that
 * is what the library could not read (#357).
 *
 * Every message plMail parses comes through here as a string: an IMAP fetch,
 * a POP3 RETR or TOP answer, a JMAP Email/import blob, a decrypted message, a
 * stored .eml. A Date line three times makes webklex hand preg_match() an
 * array, and each caller used to catch the TypeError and give up on the
 * message — POP3 never stored it, Email/import refused it, decryption left it
 * sealed.
 *
 * Tried as it came first, so a message the parser reads is parsed exactly as
 * before. The repaired copy is parsed only when the first try fails and there
 * is something to repair; the header as it arrived is put back on the result,
 * so whoever writes the message out writes every line of it (see
 * RepeatedSingletonHeaders for why the first copy is kept).
 *
 * Only three throwables: a TypeError or ValueError is a value the parser did
 * not expect, an InvalidArgumentException is Carbon refusing a date. Anything else is not about this header and is
 * thrown as it is — and so is the first failure when the repair changes
 * nothing.
 */
final class MessageParser
{
    /**
     * What webklex puts in place of a Date header it cannot parse.
     *
     * Without a fallback it throws InvalidMessageDateException while building
     * the message, the fetch fails, and every sync of that folder fails at the
     * same message for ever. The epoch is chosen because nothing real is dated
     * 1970: MessageSyncer::dateFromHeader() reads it as "no usable date" and
     * dates the message by when it arrived here instead.
     */
    public const string UNPARSEABLE_DATE = '1970-01-01 00:00:00 UTC';

    /**
     * How the boundary of a multipart message is read out of its Content-Type.
     *
     * The library's own pattern is `/boundary=(.*?(?=;)|(.*))/i`, which wants
     * the equals sign hard against the word. RFC 2045 does not: a parameter is
     * tokens under RFC 822's lexical rules, and whitespace may stand between
     * them, so
     *
     *     Content-Type: multipart/mixed; boundary = "--=_Part_1"
     *
     * is a legal header that some mailers write. The library found no boundary
     * in it, declared the message to have "no content", and — because that is
     * thrown while a page of mail is being fetched — took the whole folder's
     * sync down with it, on every poll, until the message was deleted (#45).
     *
     * Whitespace is allowed on both sides of the sign. A quoted value is taken
     * whole, spaces and all, since quoting is how a boundary is allowed to
     * contain them; an unquoted one ends at the first semicolon or whitespace.
     * The branch reset `(?|…)` puts either form in group 1, which is the one
     * group Header::find() returns. Nothing trails the value: the obvious
     * smaller change — the library's pattern with `\s*` added — captures the
     * space before a following semicolon, and the library's clean-up does not
     * strip it, so the boundary would be found and then match no line.
     */
    public const string BOUNDARY_PATTERN = '/boundary\s*=\s*(?|"([^"]+)"|([^;\s]+))/i';

    /**
     * The library's configuration as every parse in this application uses
     * it: how mail is decoded and parsed.
     *
     * Every caller that turns bytes into a message passes this — IMAP, POP3,
     * JMAP, decryption, the backfills — and a test that parses a message has
     * to parse it the same way, or it is testing the library's defaults.
     * It lived beside the IMAP connection factory while webklex was also the
     * IMAP client; parsing is all it is about now.
     */
    public static function config(): Config
    {
        return Config::make([
            // The library converts every body part from whatever charset it
            // declares, which is wrong for the senders that declare
            // ISO-8859-1 and send UTF-8 — see Utf8AwareMessageDecoder.
            // Config::make merges into the vendor defaults, so the header and
            // attachment decoders stay as they were.
            'decoding' => [
                'decoder' => [
                    'message' => Utf8AwareMessageDecoder::class,
                ],
            ],
            'options' => [
                'fallback_date' => self::UNPARSEABLE_DATE,
                'boundary'      => self::BOUNDARY_PATTERN,
            ],
        ]);
    }

    public static function fromString(string $blob, Config $config): Message
    {
        try {
            return self::build($blob, $config);
        } catch (\TypeError|\ValueError|\InvalidArgumentException $failure) {
            // Repaired below, or thrown as it is when there is nothing to repair.
        }

        // Split where fromString() splits, so the header repaired is the one
        // it failed to read.
        if (false === str_contains($blob, "\r\n")) {
            $blob = str_replace("\n", "\r\n", $blob);
        }

        $split    = strpos($blob, "\r\n\r\n");
        $header   = false === $split ? $blob : substr($blob, 0, $split);
        $repaired = RepeatedSingletonHeaders::keepFirst($header);

        if ($repaired === $header) {
            throw $failure;
        }

        $message = self::build($repaired . (false === $split ? '' : substr($blob, $split)), $config);
        $built   = $message->getHeader();

        if (null !== $built) {
            $built->raw = $header;
        }

        return $message;
    }

    /**
     * The library's own fromString(), apart because its @throws names only
     * the library's exceptions: called directly, the catch above reads as
     * dead to static analysis, though a TypeError is the very thing it is for.
     */
    private static function build(string $blob, Config $config): Message
    {
        return Message::fromString($blob, $config);
    }
}
