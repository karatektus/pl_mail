<?php

declare(strict_types=1);

namespace App\Domain\Helper;

use App\Domain\DTO\Mail\ParsedAddress;
use App\Domain\DTO\Mail\ParsedMessage;
use App\Domain\DTO\Mail\ParsedPart;
use DateTimeImmutable;
use DateTimeZone;
use ZBateson\MailMimeParser\Header\AddressHeader;
use ZBateson\MailMimeParser\Header\DateHeader;
use ZBateson\MailMimeParser\IMessage;
use ZBateson\MailMimeParser\MailMimeParser;
use ZBateson\MailMimeParser\Message\IMessagePart;
use ZBateson\MailMimeParser\Message\IMimePart;

/**
 * Turns the bytes of an RFC 822 message into a ParsedMessage.
 *
 * Every message plMail reads as bytes comes through here: an IMAP fetch, a
 * POP3 RETR, a JMAP Email/import blob, a decrypted message, a stored .eml a
 * backfill re-reads. One parser and one set of rules, so the same bytes make
 * the same row whichever protocol delivered them.
 *
 * ── Why zbateson/mail-mime-parser (#82) ──────────────────────────────────────
 * The parser was webklex/php-imap's until #82, and its parsing was where most
 * IMAP bugs came from: a repeated Date header threw (#357), `boundary = "…"`
 * with spaces stopped a folder (#45), a single-child multipart/related was
 * handed over as one attachment and the message had no body, a part named
 * only in Content-Type was stored under a hash (#718). Each had a correction
 * of its own here, and the library had stopped being maintained. zbateson
 * reads all of those correctly as they are, which is what removed the
 * corrections; the tests that held each bug are what proved it.
 *
 * Writing a parser of our own was the other option #82 left open. The
 * malformed mail is the hard part of a MIME parser, and zbateson is a library
 * whose whole subject is that, kept up by people who see more of it than one
 * mail client does.
 *
 * ── What is still ours: the charset of a body ────────────────────────────────
 * zbateson converts a body from the charset it declares, as any parser does.
 * The declaration is the sender's claim, and two lies are common: UTF-8 under
 * `charset=ISO-8859-1`, which converted reads "GrÃ¼ÃŸe", and 8-bit Latin-1
 * under `us-ascii` or `utf-8`, which Postgres refuses outright and takes the
 * whole batch with it (#81). So the body is taken as bytes and converted by
 * CharsetHelper::toUtf8(), the rule the Gmail builder already uses — which
 * keeps one judgement about charsets for every provider rather than one per
 * parser.
 *
 * ── What the parser's types do not leave ─────────────────────────────────────
 * Nothing of zbateson crosses this class's boundary: callers get ParsedMessage,
 * ParsedPart and ParsedAddress. webklex's Message and Attribute objects had
 * reached a dozen services, and each had learned a fact about the library
 * (an empty Attribute's first() is false, an Attribute is not Traversable)
 * that had nothing to do with mail.
 */
final class MessageParser
{
    /**
     * One parser for the process. It is a dependency-injection container of
     * its own underneath, and building that for every message of a first
     * sync would cost more than the parse.
     */
    private static ?MailMimeParser $parser = null;

    /**
     * @throws \Throwable whatever the parser could not get past; the caller
     *                    decides what an unreadable message costs (see
     *                    ImapPagedFetch, where it costs only that message)
     */
    public static function parse(string $raw): ParsedMessage
    {
        $message = (self::$parser ??= new MailMimeParser())->parse($raw, false);

        $date = $message->getHeader('Date');

        [$text, $html, $attachments] = self::reclaimNamelessBodies(
            self::bodyOf($message, 'text/plain'),
            self::bodyOf($message, 'text/html'),
            array_map(self::partOf(...), $message->getAllAttachmentParts()),
        );

        return new ParsedMessage(
            raw:           $raw,
            headers:       self::headersOf($message),
            messageId:     self::raw($message, 'Message-ID'),
            subject:       CharsetHelper::ensureUtf8((string) $message->getSubject()),
            from:          self::addressesOf($message, 'From')[0] ?? null,
            to:            self::addressesOf($message, 'To'),
            cc:            self::addressesOf($message, 'Cc'),
            bcc:           self::addressesOf($message, 'Bcc'),
            date:          self::dateOf($date),
            hasDateHeader: null !== $date && '' !== trim($date->getRawValue()),
            inReplyTo:     self::raw($message, 'In-Reply-To'),
            references:    self::raw($message, 'References'),
            textBody:      $text,
            htmlBody:      $html,
            attachments:   $attachments,
        );
    }

    /**
     * A text part that names no file, marked as an attachment, in a message
     * that has no body of its type: that part is the body.
     *
     * By the letter of RFC 2183 a part marked `attachment` is one, and the
     * parser says so. Senders mean otherwise — transactional mail stamps
     * `Content-Disposition: attachment` on its only HTML part — and a message
     * shown blank with one chip called "attachment.html" is a message lost.
     * The rule is about what senders do, not about any parser, which is why
     * it outlived webklex (see MisfiledBodyDetector for the mail it stored
     * that way).
     *
     * Only into an empty slot, and only without a name. A body of that type
     * already there means the tree was read right and this is something else;
     * a name means a person attached a file, and `Rechnung.html` stays a file.
     *
     * @param list<ParsedPart> $attachments
     *
     * @return array{string, string, list<ParsedPart>}
     */
    private static function reclaimNamelessBodies(string $text, string $html, array $attachments): array
    {
        $kept = [];

        foreach ($attachments as $part) {
            $nameless = null === $part->filename;

            if (true === $nameless && 'text/html' === $part->contentType && '' === $html) {
                $html = CharsetHelper::toUtf8($part->content, $part->charset);

                continue;
            }

            if (true === $nameless && 'text/plain' === $part->contentType && '' === $text) {
                $text = CharsetHelper::toUtf8($part->content, $part->charset);

                continue;
            }

            $kept[] = $part;
        }

        return [$text, $html, $kept];
    }

    /**
     * Every header, decoded and UTF-8, in the order it was written.
     *
     * Decoded with MimeHeaderHelper rather than taken from the parser's own
     * value, because the parser's value is the header's meaning — for a
     * Content-Type, the type without its parameters — and the stored header
     * bag is read for its parameters (MessageCryptoDetector reads `protocol`
     * and `smime-type` off it).
     *
     * @return array<string, string|list<string>>
     */
    private static function headersOf(IMessage $message): array
    {
        $headers = [];

        foreach ($message->getAllHeaders() as $header) {
            $name  = $header->getName();
            $value = CharsetHelper::ensureUtf8(MimeHeaderHelper::decode($header->getRawValue()));

            if (false === array_key_exists($name, $headers)) {
                $headers[$name] = $value;

                continue;
            }

            $headers[$name] = [...(array) $headers[$name], $value];
        }

        return $headers;
    }

    /**
     * A header's value as written, unfolded; '' when the message has none.
     */
    private static function raw(IMessage $message, string $name): string
    {
        return CharsetHelper::ensureUtf8(trim((string) $message->getHeader($name)?->getRawValue()));
    }

    /**
     * The mailboxes an address header names, members of a group included. A
     * group with nobody in it (`undisclosed-recipients:;`) names none.
     *
     * @return list<ParsedAddress>
     */
    private static function addressesOf(IMessage $message, string $name): array
    {
        $header = $message->getHeader($name);

        if (false === $header instanceof AddressHeader) {
            return [];
        }

        $addresses = [];

        foreach ($header->getAddresses() as $address) {
            $addresses[] = new ParsedAddress(
                CharsetHelper::ensureUtf8($address->getName()),
                CharsetHelper::ensureUtf8($address->getEmail()),
            );
        }

        return $addresses;
    }

    /**
     * The instant a Date header names, in UTC, or null when it names none.
     *
     * Null rather than a stand-in: the caller knows what to put in its place
     * (MessageSyncer dates the message by its arrival). A repeated Date is
     * read by its first line, which is what the parser hands back. webklex
     * threw on one, and a repair had to rewrite the header first (#357).
     */
    private static function dateOf(mixed $header): ?DateTimeImmutable
    {
        if (false === $header instanceof DateHeader) {
            return null;
        }

        return $header->getDateTimeImmutable()?->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * The message's body of one type, UTF-8.
     *
     * Every inline part of that type, joined by a line break, as webklex
     * joined them before: Apple Mail splits an HTML body around each inline
     * image, and a mailing list adds its footer as a text part of its own.
     * Taking the first would drop the rest of the letter.
     */
    private static function bodyOf(IMessage $message, string $type): string
    {
        $count = 'text/html' === $type ? $message->getHtmlPartCount() : $message->getTextPartCount();
        $parts = [];

        for ($index = 0; $index < $count; ++$index) {
            $part = 'text/html' === $type ? $message->getHtmlPart($index) : $message->getTextPart($index);

            if (null === $part) {
                continue;
            }

            $text = CharsetHelper::toUtf8(self::bytesOf($part), self::charsetOf($part));

            if ('' !== $text) {
                $parts[] = $text;
            }
        }

        return implode("\n", $parts);
    }

    private static function partOf(IMessagePart $part): ParsedPart
    {
        $filename    = $part->getFilename();
        $disposition = $part instanceof IMimePart ? $part->getHeader('Content-Disposition') : null;
        $contentId   = trim((string) $part->getContentId(), "<> \t\r\n");

        return new ParsedPart(
            contentType: CharsetHelper::ensureUtf8(strtolower(trim($part->getContentType()))),
            filename:    null === $filename || '' === trim($filename) ? null : CharsetHelper::ensureUtf8(trim($filename)),
            disposition: null === $disposition ? null : strtolower(trim((string) $disposition->getValue())),
            contentId:   '' === $contentId ? null : CharsetHelper::ensureUtf8($contentId),
            charset:     self::charsetOf($part),
            content:     self::bytesOf($part),
        );
    }

    /**
     * The part's bytes with the transfer encoding undone, and no charset
     * conversion: that is CharsetHelper's to do, or the caller's.
     */
    private static function bytesOf(IMessagePart $part): string
    {
        return (string) $part->getBinaryContentStream()?->getContents();
    }

    /**
     * The charset the part declared, or null when it declared none.
     *
     * Read off the header rather than through the parser's getCharset(),
     * which answers ISO-8859-1 for an undeclared text part. CharsetHelper
     * treats "undeclared" differently from "declared Latin-1", deliberately:
     * undeclared bytes that are valid UTF-8 are kept as UTF-8. A part that
     * is not MIME (uuencoded inside a plain body) has no header to declare
     * one in.
     */
    private static function charsetOf(IMessagePart $part): ?string
    {
        if (false === $part instanceof IMimePart) {
            return null;
        }

        $charset = trim((string) $part->getHeaderParameter('Content-Type', 'charset'));

        return '' === $charset ? null : $charset;
    }
}
