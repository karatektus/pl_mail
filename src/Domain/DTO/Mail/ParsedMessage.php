<?php

declare(strict_types=1);

namespace App\Domain\DTO\Mail;

use DateTimeImmutable;

/**
 * An RFC 822 message as MessageParser read it, in the shape every caller
 * needs: IMAP, POP3, JMAP Email/import, decryption, key collection and the
 * backfills that re-read a stored original.
 *
 * Every string in here is UTF-8, so nothing that writes one to a column has
 * to ask. The bytes themselves are kept too ($raw), because the IMAP path
 * stores the original beside the row and a re-read has to be able to start
 * from it.
 *
 * Values, not the parser's objects. The parser's types stay inside
 * MessageParser, which is the lesson of #82: webklex's Message and Attribute
 * classes had reached a dozen services, and each of them had grown its own
 * correction for something the library got wrong.
 */
final readonly class ParsedMessage
{
    /**
     * @param array<string, string|list<string>> $headers    every header, decoded,
     *                                                       keyed by its name as
     *                                                       first written; a list
     *                                                       when it repeats
     * @param list<ParsedAddress>                $to
     * @param list<ParsedAddress>                $cc
     * @param list<ParsedAddress>                $bcc
     * @param list<ParsedPart>                   $attachments
     * @param list<string>                       $flags      as the server sent them, in
     *                                                       the app's spelling; empty
     *                                                       where there is no server
     */
    public function __construct(
        public string             $raw,
        public array              $headers,
        /** As written, angle brackets and all; '' when there is none. */
        public string             $messageId,
        public string             $subject,
        public ?ParsedAddress     $from,
        public array              $to,
        public array              $cc,
        public array              $bcc,
        /**
         * In UTC, or null when there is no Date header or nothing in it can
         * be read as a date. Which of the two is $hasDateHeader.
         */
        public ?DateTimeImmutable $date,
        /**
         * Whether a Date header with something in it was there at all,
         * readable or not. A fetch with no Date, no sender and no Message-ID
         * is no message (MessageSyncer::isUsableFetch()); one with an
         * unreadable Date is a message without a usable date.
         */
        public bool               $hasDateHeader,
        /** As written; '' when absent. */
        public string             $inReplyTo,
        /** As written; '' when absent. */
        public string             $references,
        public string             $textBody,
        public string             $htmlBody,
        public array              $attachments,
        /** The IMAP UID the message was fetched under; null for every other source. */
        public ?int               $uid = null,
        public array              $flags = [],
    ) {}

    /**
     * The same message as the server holds it: under that UID, with those
     * flags. The parse does not change, so it is not repeated.
     *
     * @param list<string> $flags
     */
    public function withEnvelope(int $uid, array $flags): self
    {
        return new self(
            $this->raw,
            $this->headers,
            $this->messageId,
            $this->subject,
            $this->from,
            $this->to,
            $this->cc,
            $this->bcc,
            $this->date,
            $this->hasDateHeader,
            $this->inReplyTo,
            $this->references,
            $this->textBody,
            $this->htmlBody,
            $this->attachments,
            $uid,
            $flags,
        );
    }

    /**
     * The first value of a header, decoded, or null when it is absent. The
     * name is matched without regard to case, as RFC 5322 has it.
     */
    public function header(string $name): ?string
    {
        foreach ($this->headers as $written => $value) {
            if (0 === strcasecmp($written, $name)) {
                return true === is_array($value) ? ($value[0] ?? null) : $value;
            }
        }

        return null;
    }

    /**
     * Whether the message is a multipart one at its top, rather than a single
     * part with its body right after the header.
     */
    public function isMultipart(): bool
    {
        return true === str_starts_with(strtolower(ltrim((string) $this->header('Content-Type'))), 'multipart/');
    }

    /**
     * The header block as it arrived, without the blank line that ends it.
     */
    public function rawHeader(): string
    {
        return self::split($this->raw)[0];
    }

    /**
     * Everything after the blank line that ends the header block.
     */
    public function rawBody(): string
    {
        return self::split($this->raw)[1];
    }

    /**
     * Where the header block ends. Mail on the wire separates it with CRLF,
     * a stored or hand-written .eml often with LF alone; whichever comes
     * first is the end, and a message without a blank line is all header.
     *
     * @return array{string, string}
     */
    private static function split(string $raw): array
    {
        $crlf = strpos($raw, "\r\n\r\n");
        $lf   = strpos($raw, "\n\n");

        if (false === $crlf && false === $lf) {
            return [rtrim($raw, "\r\n"), ''];
        }

        if (false !== $crlf && (false === $lf || $crlf < $lf)) {
            return [substr($raw, 0, $crlf), substr($raw, $crlf + 4)];
        }

        return [rtrim(substr($raw, 0, (int) $lf), "\r"), substr($raw, (int) $lf + 2)];
    }
}
