<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Domain\Helper\MessageParser;
use App\Service\Mail\MisfiledBodyDetector;
use App\Service\Mail\MisfiledBodyUnpacker;
use PHPUnit\Framework\TestCase;

/**
 * The Workday mail that arrived with no body and one attachment called
 * `09704cea`.
 *
 * Two halves. New mail: MessageParser reads each shape webklex misfiled as the
 * body it is, through the real parser, so a parser upgrade that changes its
 * mind fails here. Stored mail: what webklex already filed as an attachment is
 * recognised by the shape of its name and opened from the bytes on disk,
 * which is MisfiledBodyBackfillTask's half.
 */
final class MisfiledBodyPartTest extends TestCase
{
    private const string HEADERS = "From: Workday <resmed@myworkday.com>\r\n"
        . "To: mail@pluetzner.de\r\n"
        . "Subject: Aktualisierung der ResMed-Anwendung\r\n"
        . "Date: Mon, 21 Sep 2026 09:55:00 +0200\r\n"
        . "Message-ID: <workday-1@myworkday.com>\r\n"
        . "MIME-Version: 1.0\r\n";

    /** The reported shape: `inline` and a Content-ID on the only HTML part. */
    public function testAnInlineBodyPartWithAContentIdIsTheBody(): void
    {
        $parsed = MessageParser::parse(
            self::HEADERS
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Disposition: inline\r\n"
            . "Content-ID: <body@myworkday.com>\r\n\r\n"
            . '<p>Liebe(r) Paul,</p>',
        );

        self::assertSame('<p>Liebe(r) Paul,</p>', $parsed->htmlBody);
        self::assertSame([], $parsed->attachments);
    }

    /**
     * The other shape: marked `attachment`, with no name, and no other body.
     * By the letter of RFC 2183 an attachment; by what the sender meant, the
     * letter. Converted out of the charset it declared on the way, since a
     * body column refuses Latin-1 bytes outright.
     */
    public function testANamelessTextPartMarkedAsAnAttachmentIsTheBody(): void
    {
        $parsed = MessageParser::parse(
            self::HEADERS
            . "Content-Type: multipart/mixed; boundary=\"B\"\r\n\r\n"
            . "--B\r\n"
            . "Content-Type: text/html; charset=ISO-8859-1\r\n"
            . "Content-Disposition: attachment\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . "<p>Geb\xFChren f\xFCr die Pr\xFCfung</p>\r\n"
            . "--B--\r\n",
        );

        self::assertSame("<p>Gebühren für die Prüfung</p>", $parsed->htmlBody);
        self::assertSame([], $parsed->attachments);
    }

    /**
     * THE GUARD. A file somebody actually attached has a name of its own, and
     * an .html attachment is the case where getting this wrong would swallow
     * it into the body and lose it.
     */
    public function testAnAttachmentWithARealFilenameIsLeftAlone(): void
    {
        $parsed = MessageParser::parse(
            self::HEADERS
            . "Content-Type: multipart/mixed; boundary=\"B\"\r\n\r\n"
            . "--B\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Disposition: attachment; filename=\"Rechnung.html\"\r\n\r\n"
            . "<p>Rechnung</p>\r\n"
            . "--B--\r\n",
        );

        self::assertSame('', $parsed->htmlBody);
        self::assertCount(1, $parsed->attachments);
        self::assertSame('Rechnung.html', $parsed->attachments[0]->filename);
    }

    /**
     * THE SHAPE THAT WAS ACTUALLY REPORTED, and the one the first fix missed.
     *
     * A `multipart/related` holding one HTML part and nothing else, inside
     * another multipart: webklex handed over the whole block as a single
     * attachment named after its own checksum, and the message had no body.
     */
    public function testASingleChildRelatedIsReadAsTheBody(): void
    {
        $parsed = MessageParser::parse(
            self::HEADERS
            . "Content-Type: multipart/alternative; boundary=\"outer\"\r\n\r\n"
            . "--outer\r\nContent-Type: multipart/related; boundary=\"inner\"\r\n\r\n"
            . "--inner\r\nContent-Type: text/html; charset=ISO-8859-1\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n<p>Geb\xFChren f\xFCr die Pr\xFCfung</p>\r\n"
            . "--inner--\r\n--outer--\r\n",
        );

        self::assertStringContainsString('Gebühren für die Prüfung', $parsed->htmlBody);
        self::assertSame([], $parsed->attachments);
    }

    /**
     * Stored mail, after the fact: a part row keeps no hash to compare
     * against, so the shape of the name is all there is to go on.
     */
    public function testAStoredPartIsRecognisedByTheShapeOfItsName(): void
    {
        $detector = new MisfiledBodyDetector();

        self::assertTrue($detector->looksLikeStoredHash('09704cea'));
        self::assertFalse($detector->looksLikeStoredHash('Rechnung.html'), 'a real filename');
        self::assertFalse($detector->looksLikeStoredHash('09704CEA'), 'crc32c is written lowercase');
        self::assertFalse($detector->looksLikeStoredHash('09704ce'), 'seven characters is not a crc32c');
        self::assertFalse($detector->looksLikeStoredHash(null));
    }

    /**
     * A stored container, as webklex wrote it to disk: the block without the
     * boundary, which content_type does not keep. The unpacker takes the
     * boundary from the block's own first delimiter and converts the HTML out
     * of its declared charset.
     */
    public function testAStoredContainerCanBeOpened(): void
    {
        $stored = "--inner\r\nContent-Type: text/html; charset=ISO-8859-1\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n<p>Geb\xFChren f\xFCr die Pr\xFCfung</p>\r\n"
            . "--inner--\r\n";

        self::assertTrue(new MisfiledBodyDetector()->isContainer('multipart/related'));

        $opened = new MisfiledBodyUnpacker()->unpack('multipart/related', $stored);

        self::assertNotNull($opened, 'the container could not be opened');
        self::assertStringContainsString('Gebühren für die Prüfung', $opened['html']);
        self::assertTrue(mb_check_encoding($opened['html'], 'UTF-8'));
    }

    /**
     * THE GUARD on opening containers. The shape above holds one HTML part; a
     * container that also holds files is one this has not seen, and taking its
     * body while dropping them would make a blank message worse.
     */
    public function testAContainerCarryingFilesIsLeftClosed(): void
    {
        $raw = "--inner\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n<p>Hallo</p>\r\n"
            . "--inner\r\nContent-Type: application/pdf\r\n"
            . "Content-Disposition: attachment; filename=\"Rechnung.pdf\"\r\n\r\nPDFBYTES\r\n"
            . "--inner--\r\n";

        self::assertNull(new MisfiledBodyUnpacker()->unpack('multipart/related', $raw));
    }
}
