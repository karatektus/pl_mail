<?php

declare(strict_types=1);

namespace App\Domain\DTO\Mail;

/**
 * One part of a parsed message that is not its text or HTML body: a file, an
 * inline image, an invite, an attached message.
 *
 * What a caller needs to store it and nothing about how it was read. The
 * parser's own part objects stay inside MessageParser, so the day the parser
 * changes again, the callers do not.
 */
final readonly class ParsedPart
{
    public function __construct(
        /** The media type alone, lowercase, without its parameters: "application/pdf". */
        public string  $contentType,
        /**
         * The name the sender gave it, decoded, or null when it gave none.
         *
         * From `Content-Disposition: filename` or, failing that, `Content-Type:
         * name`, which some mailers still use alone (#718). RFC 2231 and RFC
         * 2047 forms are both decoded, and a raw 8-bit name is made UTF-8.
         */
        public ?string $filename,
        /** "inline", "attachment", or null when the part declared none. */
        public ?string $disposition,
        /** Without its angle brackets, or null when the part has none. */
        public ?string $contentId,
        /** The charset the part declared, or null when it declared none. */
        public ?string $charset,
        /** The bytes, with the transfer encoding undone and nothing else. */
        public string  $content,
    ) {}
}
