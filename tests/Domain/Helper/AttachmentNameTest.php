<?php

declare(strict_types=1);

namespace App\Tests\Domain\Helper;

use App\Domain\Helper\AttachmentName;
use App\Domain\Helper\MessageParser;
use App\Domain\Helper\PdfAttachment;
use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\Message as ImapMessage;

/**
 * The name stored for an attachment, read through the real webklex parser.
 *
 * The first case is the shape The Arch Company's statements arrive in: the
 * name only in Content-Type, a bare `Content-Disposition: attachment`, and
 * application/octet-stream for a PDF. webklex alone calls that part by its
 * hash; the test asserts that first, so it fails loudly if a webklex upgrade
 * ever starts reading `name` itself and this helper becomes redundant.
 */
final class AttachmentNameTest extends TestCase
{
    public function testANameGivenOnlyInContentTypeIsUsedInsteadOfTheHash(): void
    {
        $attachment = $this->attachment(
            "Content-Type: application/octet-stream; name=TNNTST02_10561121.PDF\r\n"
            . "Content-Transfer-Encoding: base64\r\n"
            . "Content-Disposition: attachment\r\n"
            . "Content-ID: <10557746>\r\n",
        );

        self::assertSame($attachment->getHash(), $attachment->getFilename(), 'webklex alone names it by its hash');
        self::assertSame('TNNTST02_10561121.PDF', AttachmentName::of($attachment));
        self::assertTrue(
            PdfAttachment::matches($attachment->getContentType(), AttachmentName::of($attachment)),
            'an octet-stream PDF previews once it has its name back',
        );
    }

    public function testTheDispositionFilenameStillWinsWhenBothAreGiven(): void
    {
        $attachment = $this->attachment(
            "Content-Type: application/pdf; name=\"from-type.pdf\"\r\n"
            . "Content-Disposition: attachment; filename=\"from-disposition.pdf\"\r\n"
            . "Content-Transfer-Encoding: base64\r\n",
        );

        self::assertSame('from-disposition.pdf', AttachmentName::of($attachment));
    }

    public function testAnEncodedNameInContentTypeIsDecoded(): void
    {
        $attachment = $this->attachment(
            "Content-Type: application/pdf; name=\"=?UTF-8?B?RXJrbMOkcnVuZy5wZGY=?=\"\r\n"
            . "Content-Disposition: attachment\r\n"
            . "Content-Transfer-Encoding: base64\r\n",
        );

        self::assertSame('Erklärung.pdf', AttachmentName::of($attachment));
    }

    public function testAPartThatNamesNothingKeepsTheHash(): void
    {
        // MisfiledBodyDetector finds a body webklex filed as an attachment by
        // exactly this hash name, so it must survive.
        $attachment = $this->attachment(
            "Content-Type: application/octet-stream\r\n"
            . "Content-Disposition: attachment\r\n"
            . "Content-Transfer-Encoding: base64\r\n",
        );

        self::assertSame($attachment->getHash(), AttachmentName::of($attachment));
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}$/', (string) AttachmentName::of($attachment));
    }

    public function testAnUnnamedAttachedMessageKeepsTheHashNotItsSubtype(): void
    {
        $raw = $this->envelope()
            . "--B\r\n"
            . "Content-Type: message/rfc822\r\n"
            . "Content-Disposition: attachment\r\n\r\n"
            . "From: a@example.test\r\nSubject: inner\r\n\r\nInner body.\r\n"
            . "--B--\r\n";

        $attachment = $this->only(ImapMessage::fromString($raw, MessageParser::config()));

        self::assertSame($attachment->getHash(), AttachmentName::of($attachment));
    }

    private function attachment(string $partHeaders): mixed
    {
        $raw = $this->envelope()
            . "--B\r\n"
            . $partHeaders
            . "\r\n"
            . "JVBERi0xLjcNCg==\r\n"
            . "--B--\r\n";

        return $this->only(ImapMessage::fromString($raw, MessageParser::config()));
    }

    private function envelope(): string
    {
        return "From: NoReply@example.test\r\n"
            . "To: someone@example.test\r\n"
            . "Subject: [00000001] Account Statement\r\n"
            . "Date: Tue, 17 Nov 2026 03:15:55 +0000\r\n"
            . "Message-ID: <statement-1@example.test>\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: multipart/mixed; boundary=\"B\"\r\n\r\n"
            . "--B\r\n"
            . "Content-Type: text/html; charset=us-ascii\r\n\r\n"
            . "<p>Please find your statement attached.</p>\r\n";
    }

    private function only(ImapMessage $message): mixed
    {
        $attachments = iterator_to_array($message->getAttachments());

        self::assertCount(1, $attachments);

        return array_values($attachments)[0];
    }
}
