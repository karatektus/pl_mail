<?php

declare(strict_types=1);

namespace App\Tests\Domain\Helper;

use App\Domain\Helper\MessageParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A multipart message is read whatever way its boundary is legally written.
 *
 * RFC 2045 lets whitespace stand around the equals sign of a parameter, and
 * some mailers put it there: `boundary = "XYZ"`. webklex's pattern wanted the
 * sign hard against the word, found no boundary, and called the message one
 * with "no content" — an exception thrown while a page of mail was fetched,
 * which stopped the whole folder's sync on every poll until somebody deleted
 * the message on the server (#45). It took a pattern of our own in the
 * library's configuration to read it.
 *
 * MessageParser reads every form below without one, which is what let that
 * configuration go with the library. Kept, through the parser a sync uses,
 * so a parser that stops reading one of them fails here and not in a folder.
 */
final class BoundaryParsingTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function waysToWriteABoundary(): iterable
    {
        yield 'hard against the sign, as most mailers write it' => ['boundary="XYZ"'];
        yield 'spaced on both sides — the report'               => ['boundary = "XYZ"'];
        yield 'spaced before only'                              => ['boundary ="XYZ"'];
        yield 'spaced after only'                               => ['boundary= "XYZ"'];
        yield 'unquoted'                                        => ['boundary=XYZ'];
        yield 'unquoted and spaced'                             => ['boundary = XYZ'];
        yield 'with a parameter after it'                       => ['boundary = "XYZ"; charset=utf-8'];
        // The trap in the obvious smaller fix: a pattern that runs to the
        // semicolon keeps the space before it, and "XYZ " matches no line.
        yield 'with a space before the next parameter'          => ['boundary = "XYZ" ; charset=utf-8'];
        yield 'unquoted, with a space before the next one'      => ['boundary = XYZ ; charset=utf-8'];
        yield 'folded onto the next line'                       => ["\r\n boundary = \"XYZ\""];
        yield 'in capitals'                                     => ['BOUNDARY = "XYZ"'];
    }

    #[DataProvider('waysToWriteABoundary')]
    public function testTheBodyIsReadHoweverTheBoundaryIsWritten(string $parameter): void
    {
        self::assertSame('hello', trim(MessageParser::parse($this->multipart($parameter, 'XYZ'))->textBody));
    }

    /** Quoting is how a boundary is allowed to contain a space, so the space is part of it. */
    public function testAQuotedBoundaryKeepsTheSpacesInsideIt(): void
    {
        $message = MessageParser::parse($this->multipart('boundary = "part one"', 'part one'));

        self::assertSame('hello', trim($message->textBody));
    }

    /** A part that is itself multipart reads its own boundary the same way. */
    public function testANestedMultipartWithASpacedBoundaryIsReadToo(): void
    {
        $raw = "From: sender@example.com\r\n"
            . "To: rcpt@example.com\r\n"
            . "Subject: nested\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: multipart/mixed; boundary = \"OUTER\"\r\n"
            . "\r\n"
            . "--OUTER\r\n"
            . "Content-Type: multipart/alternative; boundary = \"INNER\"\r\n"
            . "\r\n"
            . "--INNER\r\n"
            . "Content-Type: text/plain; charset=utf-8\r\n"
            . "\r\n"
            . "hello\r\n"
            . "--INNER\r\n"
            . "Content-Type: text/html; charset=utf-8\r\n"
            . "\r\n"
            . "<p>hello</p>\r\n"
            . "--INNER--\r\n"
            . "--OUTER--\r\n";

        $message = MessageParser::parse($raw);

        self::assertSame('hello', trim($message->textBody));
        self::assertSame('<p>hello</p>', trim($message->htmlBody));
    }

    /** The message of the report: synthetic, and CRLF as on the wire. */
    private function multipart(string $boundaryParameter, string $boundary): string
    {
        return "From: sender@example.com\r\n"
            . "To: rcpt@example.com\r\n"
            . "Subject: boundary whitespace repro\r\n"
            . "MIME-Version: 1.0\r\n"
            . sprintf("Content-Type: multipart/mixed; %s\r\n", $boundaryParameter)
            . "\r\n"
            . sprintf("--%s\r\n", $boundary)
            . "Content-Type: text/plain; charset=utf-8\r\n"
            . "\r\n"
            . "hello\r\n"
            . sprintf("--%s--\r\n", $boundary);
    }
}
