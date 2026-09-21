<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Domain\Helper\CharsetHelper;
use App\Infrastructure\Imap\Utf8AwareMessageDecoder;
use App\Service\Mail\MisfiledBodyDetector;
use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Message as ImapMessage;

/**
 * The Workday mail that arrived with no body and one attachment called
 * `09704cea`.
 *
 * Parsed by the real library rather than described to a mock, because the
 * thing under test is a disagreement WITH the library: Part::isAttachment()
 * files a text part carrying `Content-Disposition: inline` and a Content-ID
 * under getAttachments(), and a hand-built fixture asserting that would only
 * be asserting what this test already believes.
 */
final class MisfiledBodyPartTest extends TestCase
{
    private const string HEADERS = "From: Workday <resmed@myworkday.com>\r\n"
        . "To: mail@pluetzner.de\r\n"
        . "Subject: Aktualisierung der ResMed-Anwendung\r\n"
        . "Date: Mon, 21 Sep 2026 09:55:00 +0200\r\n"
        . "Message-ID: <workday-1@myworkday.com>\r\n"
        . "MIME-Version: 1.0\r\n";

    /**
     * The reported shape, end to end: the library calls it an attachment, and
     * the detector calls it the body.
     */
    public function testAnInlineBodyPartWithAContentIdIsRecognisedAsTheBody(): void
    {
        $attachment = $this->onlyAttachment(
            "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Disposition: inline\r\n"
            . "Content-ID: <body@myworkday.com>\r\n\r\n"
            . '<p>Liebe(r) Paul,</p>'
        );

        // The symptom as the user sees it: a name that is not a name. Webklex
        // fills an absent filename with a crc32c of the part.
        self::assertSame($attachment->getHash(), $attachment->getFilename());

        $detector = new MisfiledBodyDetector();

        self::assertTrue($detector->isBody(
            (string) $attachment->getContentType(),
            $attachment->getFilename(),
            $attachment->getHash(),
        ));
        self::assertSame('html', $detector->slotFor((string) $attachment->getContentType()));
    }

    /**
     * THE GUARD. A file somebody actually attached has a name of its own, and
     * an .html attachment is the case where getting this wrong would swallow
     * it into the body and lose it.
     */
    public function testAnAttachmentWithARealFilenameIsLeftAlone(): void
    {
        $attachment = $this->onlyAttachment(
            "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Disposition: attachment; filename=\"Rechnung.html\"\r\n\r\n"
            . '<p>Rechnung</p>'
        );

        self::assertFalse(new MisfiledBodyDetector()->isBody(
            (string) $attachment->getContentType(),
            $attachment->getFilename(),
            $attachment->getHash(),
        ));
    }

    /**
     * The half that is not about classification at all.
     *
     * A reclaimed part has been through the transfer decoding and NOT through
     * the charset conversion — Message::fetchPart() only converts on its body
     * branch. So these bytes are still ISO-8859-1, and a body column that took
     * them unconverted would not render badly, it would fail the INSERT.
     */
    public function testAReclaimedBodyIsConvertedOutOfItsDeclaredCharset(): void
    {
        $attachment = $this->onlyAttachment(
            "Content-Type: text/html; charset=ISO-8859-1\r\n"
            . "Content-Disposition: inline\r\n"
            . "Content-ID: <body@myworkday.com>\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . "<p>Geb\xFChren f\xFCr die Pr\xFCfung</p>"
        );

        $raw = (string) $attachment->getContent();

        self::assertFalse(mb_check_encoding($raw, 'UTF-8'), 'the library handed over converted bytes after all');

        $converted = CharsetHelper::toUtf8($raw, (string) ($attachment->charset ?? ''));

        self::assertTrue(mb_check_encoding($converted, 'UTF-8'));
        self::assertStringContainsString('Gebühren für die Prüfung', $converted);
    }

    /**
     * The after-the-fact version of the same judgement, used by the repair:
     * a stored part keeps no hash to compare against, so the shape of the
     * name is all there is to go on.
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

    private function onlyAttachment(string $part): mixed
    {
        $message = ImapMessage::fromString(self::HEADERS . $part, Config::make([
            'decoding' => ['decoder' => ['message' => Utf8AwareMessageDecoder::class]],
        ]));

        $attachments = $message->getAttachments()->all();

        self::assertCount(1, $attachments, 'the library no longer files this part as an attachment');

        return array_values($attachments)[0];
    }
}
