<?php

declare(strict_types=1);

namespace App\Infrastructure\ImapClient;

use App\Infrastructure\ImapClient\Exception\ImapProtocolException;

/**
 * Turns the bytes a server sends into whole responses.
 *
 * Bytes arrive as the network delivers them — half a line, three lines and a
 * bit, a literal split anywhere — so they are kept until a response is
 * complete. A response ends at a line break, unless that line announces a
 * literal (`{123}`), in which case the 123 bytes after the break belong to it
 * and the response goes on after them.
 *
 * Line breaks are taken as CRLF and as a bare LF. The RFC says CRLF; a few
 * servers and proxies say less, and refusing them would gain nothing.
 */
final class ImapResponseReader
{
    /**
     * The largest literal accepted. Large enough for any message body this
     * client will be asked to fetch; small enough that a server announcing a
     * nonsense size is refused instead of being waited for until memory runs out.
     */
    public const int MAX_LITERAL_BYTES = 256 * 1024 * 1024;

    private string $buffer = '';

    /** Where in the buffer the current response's next line starts. */
    private int $scan = 0;

    public function feed(string $bytes): void
    {
        $this->buffer .= $bytes;
    }

    /** Whether bytes are held that do not yet make a whole response. */
    public function hasPartial(): bool
    {
        return '' !== $this->buffer;
    }

    /** The next complete response, or null until more bytes arrive. */
    public function next(): ?ImapResponse
    {
        while (true) {
            $end = strpos($this->buffer, "\n", $this->scan);

            if (false === $end) {
                return null;
            }

            $line = rtrim(substr($this->buffer, $this->scan, $end - $this->scan), "\r");

            if (1 === preg_match('~\{(\d+)\+?\}$~', $line, $match)) {
                $size = (int) $match[1];

                if ($size > self::MAX_LITERAL_BYTES) {
                    throw new ImapProtocolException(sprintf('The server announced a literal of %d bytes.', $size));
                }

                if (strlen($this->buffer) < $end + 1 + $size) {
                    return null;
                }

                $this->scan = $end + 1 + $size;

                continue;
            }

            $raw          = substr($this->buffer, 0, $end + 1);
            $this->buffer = substr($this->buffer, $end + 1);
            $this->scan   = 0;

            return self::parse($raw);
        }
    }

    /**
     * Read one complete response.
     *
     * Public and static so the reading of every shape is stated behaviour
     * with tests on it, apart from the framing above.
     */
    public static function parse(string $raw): ImapResponse
    {
        if (true === str_starts_with($raw, '+')) {
            return new ImapResponse(
                kind:     ImapResponseKind::Continuation,
                tag:      null,
                status:   null,
                number:   null,
                keyword:  '',
                code:     null,
                codeData: [],
                data:     [],
                text:     trim(substr($raw, 1)),
                raw:      $raw,
            );
        }

        if (1 !== preg_match('~^(\S+) +(\S+)~', $raw, $head, PREG_OFFSET_CAPTURE)) {
            throw new ImapProtocolException(sprintf('Not an IMAP response: %s', rtrim($raw)));
        }

        $tag    = $head[1][0];
        $word   = $head[2][0];
        $offset = $head[2][1] + strlen($word);
        $kind   = '*' === $tag ? ImapResponseKind::Untagged : ImapResponseKind::Tagged;
        $number = null;

        if (ImapResponseKind::Untagged === $kind && 1 === preg_match('~^\d+$~', $word)) {
            if (1 !== preg_match('~\G +([^\s(\[]+)~', $raw, $keyword, 0, $offset)) {
                throw new ImapProtocolException(sprintf('A numbered response without a keyword: %s', rtrim($raw)));
            }

            $number = (int) $word;
            $word   = $keyword[1];
            $offset += strlen($keyword[0]);
        }

        $keyword = strtoupper($word);

        if (null === $number && true === in_array($keyword, ImapResponse::STATUSES, true)) {
            [$code, $codeData, $text] = self::statusText($raw, $offset);

            return new ImapResponse($kind, '*' === $tag ? null : $tag, $keyword, null, $keyword, $code, $codeData, [], $text, $raw);
        }

        if (ImapResponseKind::Tagged === $kind) {
            throw new ImapProtocolException(sprintf('A tagged response without a status: %s', rtrim($raw)));
        }

        return new ImapResponse(
            kind:     $kind,
            tag:      null,
            status:   null,
            number:   $number,
            keyword:  $keyword,
            code:     null,
            codeData: [],
            data:     ImapTokenizer::tokenize($raw, $offset),
            text:     '',
            raw:      $raw,
        );
    }

    /**
     * The rest of a status response: an optional `[CODE arguments]`, then
     * text for a person.
     *
     * @return array{0: string|null, 1: list<mixed>, 2: string}
     */
    private static function statusText(string $raw, int $offset): array
    {
        $rest = ltrim(substr($raw, $offset), ' ');

        if (false === str_starts_with($rest, '[')) {
            return [null, [], rtrim($rest, "\r\n")];
        }

        $start = strlen($raw) - strlen($rest) + 1;

        if (1 !== preg_match('~\G([^\s\]]+)~', $raw, $codeMatch, 0, $start)) {
            return [null, [], rtrim($rest, "\r\n")];
        }

        [$arguments, $after] = ImapTokenizer::codeArguments($raw, $start + strlen($codeMatch[1]));

        return [strtoupper($codeMatch[1]), $arguments, trim(substr($raw, $after))];
    }
}
