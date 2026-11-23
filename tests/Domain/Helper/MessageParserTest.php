<?php

declare(strict_types=1);

namespace App\Tests\Domain\Helper;

use App\Domain\DTO\Mail\ParsedPart;
use App\Domain\Helper\MessageParser;
use App\Domain\Helper\PdfAttachment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What MessageParser makes of the mail that each needed a correction of its
 * own while webklex parsed (#82). Every case below was a bug report, and each
 * is read here through the real parser, so the parser can be upgraded or
 * replaced with this as the check. Every header below is invented.
 */
final class MessageParserTest extends TestCase
{
    private const string ENVELOPE = "From: NoReply@example.test\r\n"
        . "To: someone@example.test\r\n"
        . "Subject: Statement\r\n"
        . "Date: Tue, 17 Nov 2026 03:15:55 +0000\r\n"
        . "Message-ID: <statement-1@example.test>\r\n"
        . "MIME-Version: 1.0\r\n";

    /**
     * Two lines were joined into a date Carbon refused; three or more became
     * an array preg_match() refused. Either way webklex threw and the message
     * was never stored (#357).
     *
     * @return array<string, array{int}>
     */
    public static function repeats(): array
    {
        return ['twice' => [2], 'three times' => [3]];
    }

    #[DataProvider('repeats')]
    public function testARepeatedDateIsReadAsTheFirst(int $times): void
    {
        $header = "From: a@example.test\r\nSubject: Dated " . $times . " times\r\n";

        for ($hour = 10; $hour < 10 + $times; ++$hour) {
            $header .= 'Date: Mon, 19 Oct 2026 ' . $hour . ":00:00 +0000\r\n";
        }

        $blob    = $header . "\r\nBody.\r\n";
        $message = MessageParser::parse($blob);

        self::assertSame('Dated ' . $times . ' times', $message->subject);
        self::assertSame('2026-10-19 10:00:00', $message->date?->format('Y-m-d H:i:s'));
        self::assertSame(rtrim($header, "\r\n"), $message->rawHeader(), 'every line the message arrived with');
        self::assertSame($blob, $message->raw, 'the original is kept byte for byte');
        self::assertStringContainsString('Body.', $message->textBody);
    }

    /**
     * The name given only in Content-Type, with a bare disposition (#718).
     * webklex used its checksum of the part instead: the chip read "57d5c7cb",
     * the download had no extension, and a PDF sent as octet-stream had no
     * preview.
     */
    public function testANameGivenOnlyInContentTypeIsTheName(): void
    {
        $part = $this->onlyAttachment(
            "Content-Type: application/octet-stream; name=TNNTST02_10561121.PDF\r\n"
            . "Content-Transfer-Encoding: base64\r\n"
            . "Content-Disposition: attachment\r\n",
        );

        self::assertSame('TNNTST02_10561121.PDF', $part->filename);
        self::assertTrue(PdfAttachment::matches($part->contentType, $part->filename), 'it previews as a PDF');
    }

    /**
     * Every way a name is written: RFC 2231, an RFC 2047 encoded word, and
     * the raw Latin-1 a Windows client sends, which Postgres refused (#81).
     * The disposition's name wins over the type's when both are given.
     *
     * @return iterable<string, array{string}>
     */
    public static function namings(): iterable
    {
        yield 'RFC 2231'        => ["Content-Type: application/pdf\r\nContent-Disposition: attachment; filename*=UTF-8''Erkl%C3%A4rung.pdf\r\n"];
        yield 'encoded word'    => ["Content-Type: application/pdf; name=\"=?UTF-8?B?RXJrbMOkcnVuZy5wZGY=?=\"\r\nContent-Disposition: attachment\r\n"];
        yield 'raw Latin-1'     => ["Content-Type: application/pdf\r\nContent-Disposition: attachment; filename=\"Erkl\xE4rung.pdf\"\r\n"];
        yield 'both, disposition first' => ["Content-Type: application/pdf; name=\"other.pdf\"\r\nContent-Disposition: attachment; filename=\"Erklärung.pdf\"\r\n"];
    }

    #[DataProvider('namings')]
    public function testANameIsReadHoweverItIsWritten(string $headers): void
    {
        self::assertSame('Erklärung.pdf', $this->onlyAttachment($headers . "Content-Transfer-Encoding: base64\r\n")->filename);
    }

    /** A part with no name anywhere has none, and the caller names it. */
    public function testANamelessPartHasNoName(): void
    {
        self::assertNull($this->onlyAttachment("Content-Type: application/octet-stream\r\nContent-Disposition: attachment\r\nContent-Transfer-Encoding: base64\r\n")->filename);
    }

    /**
     * The charset a body declares is the sender's claim, and three lies are
     * common. Each is read as the bytes are, not as the label says.
     *
     * @return iterable<string, array{string, string, string}>
     */
    public static function charsetLies(): iterable
    {
        // A sender composing in UTF-8 and stamping Latin-1: "GrÃ¼ÃŸe" if believed.
        yield 'UTF-8 under ISO-8859-1'      => ['ISO-8859-1', "Gr\xC3\xBC\xC3\x9Fe", 'Grüße'];
        // Latin-1 under us-ascii: a raw 0xFC that Postgres refuses (#81).
        yield 'Latin-1 under us-ascii'      => ['us-ascii', "f\xFCr", 'für'];
        // One Windows-1252 euro in a part that says UTF-8.
        yield 'Windows-1252 under UTF-8'    => ['utf-8', "f\xC3\xBCr 5\x80", 'für 5€'];
    }

    #[DataProvider('charsetLies')]
    public function testABodyIsReadAsItsBytesAreNotAsItsLabelSays(string $declared, string $bytes, string $expected): void
    {
        $message = MessageParser::parse(
            self::ENVELOPE
            . "Content-Type: text/plain; charset={$declared}\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $bytes . "\r\n",
        );

        self::assertSame($expected, trim($message->textBody));
    }

    /**
     * Apple Mail splits an HTML body around each inline image. Every piece is
     * the letter, so every piece is kept, in order.
     */
    public function testAnHtmlBodySplitAroundAnImageIsKeptWhole(): void
    {
        $message = MessageParser::parse(
            self::ENVELOPE
            . "Content-Type: multipart/mixed; boundary=\"B\"\r\n\r\n"
            . "--B\r\nContent-Type: text/html; charset=utf-8\r\n\r\n<p>one</p>\r\n"
            . "--B\r\nContent-Type: image/png\r\nContent-Disposition: inline; filename=a.png\r\nContent-ID: <img1@example.test>\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\niVBORw0KGgo=\r\n"
            . "--B\r\nContent-Type: text/html; charset=utf-8\r\n\r\n<p>two</p>\r\n"
            . "--B--\r\n",
        );

        self::assertSame("<p>one</p>\n<p>two</p>", $message->htmlBody);
        self::assertCount(1, $message->attachments);
        self::assertSame('img1@example.test', $message->attachments[0]->contentId, 'without its angle brackets');
        self::assertSame('inline', $message->attachments[0]->disposition);
    }

    /** An attached message is one attachment, whole, not its parts. */
    public function testAnAttachedMessageIsKeptWhole(): void
    {
        $part = $this->onlyAttachment("Content-Type: message/rfc822\r\nContent-Disposition: attachment\r\n", "From: a@example.test\r\nSubject: inner\r\n\r\nInner body.");

        self::assertSame('message/rfc822', $part->contentType);
        self::assertStringContainsString('Subject: inner', $part->content);
    }

    /** A hand-written .eml with bare LF is split where its header ends. */
    public function testAMessageWithBareLineFeedsIsReadToo(): void
    {
        $message = MessageParser::parse("From: a@example.test\nSubject: Plain\nDate: Mon, 19 Oct 2026 10:00:00 +0000\n\nBody.\n");

        self::assertSame('Plain', $message->subject);
        self::assertSame("From: a@example.test\nSubject: Plain\nDate: Mon, 19 Oct 2026 10:00:00 +0000", $message->rawHeader());
        self::assertSame("Body.\n", $message->rawBody());
        self::assertStringContainsString('Body.', $message->textBody);
    }

    /**
     * Headers keep their parameters, which is what the crypto detector reads,
     * and a repeated header keeps every value.
     */
    public function testTheHeaderBagKeepsParametersAndRepeats(): void
    {
        $message = MessageParser::parse(
            "From: a@example.test\r\nReceived: one\r\nReceived: two\r\n"
            . "Content-Type: multipart/signed; protocol=\"application/pgp-signature\"; boundary=\"S\"\r\n\r\n"
            . "--S\r\nContent-Type: text/plain\r\n\r\nSigned.\r\n--S--\r\n",
        );

        self::assertSame(['one', 'two'], $message->headers['Received']);
        self::assertStringContainsString('protocol="application/pgp-signature"', (string) $message->header('content-type'));
        self::assertTrue($message->isMultipart());
    }

    private function onlyAttachment(string $partHeaders, string $body = 'JVBERi0xLjcNCg=='): ParsedPart
    {
        $message = MessageParser::parse(
            self::ENVELOPE
            . "Content-Type: multipart/mixed; boundary=\"B\"\r\n\r\n"
            . "--B\r\nContent-Type: text/plain; charset=us-ascii\r\n\r\nPlease find it attached.\r\n"
            . "--B\r\n" . $partHeaders . "\r\n" . $body . "\r\n"
            . "--B--\r\n",
        );

        self::assertCount(1, $message->attachments);

        return $message->attachments[0];
    }
}
