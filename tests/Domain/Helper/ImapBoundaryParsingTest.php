<?php

declare(strict_types=1);

namespace App\Tests\Domain\Helper;

use App\Domain\Helper\ImapConnectionFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Exceptions\MessageContentFetchingException;
use Webklex\PHPIMAP\Message;

/**
 * A multipart message is read whatever way its boundary is legally written.
 *
 * RFC 2045 lets whitespace stand around the equals sign of a parameter, and
 * some mailers put it there: `boundary = "XYZ"`. The library's own pattern
 * wants the sign hard against the word, found no boundary, and called the
 * message one with "no content" — an exception thrown while a page of mail is
 * fetched, which stopped the whole folder's sync on every poll until somebody
 * deleted the message on the server (#45).
 *
 * Parsed through ImapConnectionFactory::config(), which is the configuration a
 * sync parses with. That is half the point: the pattern is a config value, and
 * a test that handed the library its own config would pass without the value
 * ever reaching the header that reads it.
 */
final class ImapBoundaryParsingTest extends TestCase
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
        $message = Message::fromString($this->multipart($parameter, 'XYZ'), ImapConnectionFactory::config());

        self::assertSame('hello', trim((string) $message->getTextBody()));
    }

    /** Quoting is how a boundary is allowed to contain a space, so the space is part of it. */
    public function testAQuotedBoundaryKeepsTheSpacesInsideIt(): void
    {
        $message = Message::fromString(
            $this->multipart('boundary = "part one"', 'part one'),
            ImapConnectionFactory::config(),
        );

        self::assertSame('hello', trim((string) $message->getTextBody()));
    }

    /** A part that is itself multipart reads its own boundary through the same pattern. */
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

        $message = Message::fromString($raw, ImapConnectionFactory::config());

        self::assertSame('hello', trim((string) $message->getTextBody()));
        self::assertSame('<p>hello</p>', trim((string) $message->getHTMLBody()));
    }

    /**
     * The contrast, and the reason the pattern is configured at all: the same
     * bytes through the library's defaults. If this ever stops throwing, the
     * library has fixed its pattern and BOUNDARY_PATTERN can be looked at
     * again — until then it is what stands between this header and a folder
     * that never syncs.
     */
    public function testTheLibrarysOwnPatternDoesNotReadTheSpacedForm(): void
    {
        $this->expectException(MessageContentFetchingException::class);
        $this->expectExceptionMessage('no content found');

        Message::fromString($this->multipart('boundary = "XYZ"', 'XYZ'), Config::make([]));
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
