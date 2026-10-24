<?php

declare(strict_types=1);

namespace App\Domain\Helper;

/**
 * Finds a message's header block repeated at the top of its own body, and
 * takes it off again.
 *
 * ── The shape ────────────────────────────────────────────────────────────────
 * The IMAP sync asks for a message in two halves, RFC822.HEADER and
 * RFC822.TEXT (webklex's Query::fetch()), and keeps them apart. Some messages
 * on a Stalwart server answer the second with the WHOLE message, headers
 * included (#173). Nothing between the socket and MessageSyncer looks at what
 * the TEXT literal holds, so webklex parses "headers, blank line, body" as the
 * body: a single-part HTML mail is shown with `Delivered-To: …` above its
 * content, and the stored .eml carries the headers twice. Sometimes there is no
 * blank line between the repeated block and the `<!DOCTYPE`, which is why the
 * blank line is optional below.
 *
 * ── Why an exact match, and nothing looser ───────────────────────────────────
 * Both halves come from the same message on the same server, so a repeat is
 * byte for byte the header literal. Anything looser — "starts with something
 * that parses as headers" — would also match a plain-text body that opens with
 * "Note: …", and cutting a real body is worse than the bug. A body that
 * genuinely begins with an exact copy of its own header block does not occur.
 *
 * Static and free of webklex, so the ingest path and the backfill that repairs
 * stored mail share one rule, and a test can state it without a server.
 */
final class RepeatedHeaderHelper
{
    /**
     * The body without the repeated header block, or null when it does not
     * start with one.
     *
     * @param string $rawHeader the header block as the server sent it, with or
     *                          without the blank line that ends it
     * @param string $rawBody   what the server sent as the body
     */
    public static function strip(string $rawHeader, string $rawBody): ?string
    {
        $header = rtrim($rawHeader, "\r\n");

        if ('' === $header || false === str_starts_with($rawBody, $header)) {
            return null;
        }

        $rest = substr($rawBody, strlen($header));

        // The match has to end where a line ends. Otherwise the block is only
        // the beginning of a longer line — the last header continuing — and
        // what follows is not a body.
        if ('' !== $rest && false === str_starts_with($rest, "\r\n") && false === str_starts_with($rest, "\n")) {
            return null;
        }

        // The line break that ends the last header line, then the blank line
        // that separates headers from body — when there is one. Two at most:
        // a body that itself opens with an empty line keeps it.
        for ($i = 0; $i < 2; ++$i) {
            if (true === str_starts_with($rest, "\r\n")) {
                $rest = substr($rest, 2);
            } elseif (true === str_starts_with($rest, "\n")) {
                $rest = substr($rest, 1);
            }
        }

        return $rest;
    }
}
