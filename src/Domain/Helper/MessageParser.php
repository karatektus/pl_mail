<?php

declare(strict_types=1);

namespace App\Domain\Helper;

use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Message;

/**
 * Message::fromString(), with a repeated once-only header collapsed when that
 * is what the library could not read (#357).
 *
 * The repair ImapQuery::make() gives mail the IMAP sync fetches, for every
 * place that holds a whole message as a string instead: a POP3 RETR or TOP
 * answer, a JMAP Email/import blob, a decrypted message, a stored .eml. A Date
 * line there three times makes webklex hand preg_match() an array, and each of
 * those callers caught the TypeError and gave up on the message — POP3 never
 * stored it, Email/import refused it, decryption left it sealed.
 *
 * Tried as it came first, so a message the parser reads is parsed exactly as
 * before. The repaired copy is parsed only when the first try fails and there
 * is something to repair; the header as it arrived is put back on the result,
 * so whoever writes the message out writes every line of it (see
 * RepeatedSingletonHeaders for why the first copy is kept).
 *
 * Only the three throwables ImapQuery catches, for its reasons: a TypeError or
 * ValueError is a value the parser did not expect, an InvalidArgumentException
 * is Carbon refusing a date. Anything else is not about this header and is
 * thrown as it is — and so is the first failure when the repair changes
 * nothing.
 */
final class MessageParser
{
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
