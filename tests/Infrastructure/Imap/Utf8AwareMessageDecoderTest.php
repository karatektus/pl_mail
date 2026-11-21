<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Imap;

use App\Domain\Helper\AddressHelper;
use App\Domain\Helper\MessageParser;
use PHPUnit\Framework\TestCase;
use Webklex\PHPIMAP\Message;

/**
 * Mail in a directional Hebrew or Arabic charset keeps its text and its
 * sender's name (#714).
 *
 * Through MessageParser, the way every caller parses mail, because the body was
 * lost inside the library: webklex handed the declared ISO-8859-8-I to iconv,
 * iconv does not know the name, and the part came back empty. The iconv warning
 * that went with it is a failure here as well, by failOnWarning.
 */
final class Utf8AwareMessageDecoderTest extends TestCase
{
    /**
     * The shape Outlook sends Hebrew in: both parts and the sender's name in
     * ISO-8859-8-I, the text quoted-printable.
     */
    public function testHebrewMailFromOutlookKeepsItsTextAndItsSenderName(): void
    {
        $message = $this->parse(
            '=?iso-8859-8-i?B?6fn44Owg6fn44Ozp?=',
            "Content-Type: multipart/alternative; boundary=\"b\"\r\n\r\n"
            . "--b\r\n"
            . "Content-Type: text/plain; charset=\"iso-8859-8-i\"\r\n"
            . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
            . "=F9=EC=E5=ED, =E6=E4 =EE=EB=FA=E1 =F0=E9=F1=E9=E5=EF.\r\n"
            . "--b\r\n"
            . "Content-Type: text/html; charset=\"iso-8859-8-i\"\r\n"
            . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
            . "<p dir=3D\"rtl\">=F9=EC=E5=ED</p>\r\n"
            . "--b--\r\n",
        );

        self::assertSame('ישראל ישראלי', $this->senderName($message));
        self::assertSame('שלום, זה מכתב ניסיון.', trim((string) $message->getTextBody()));
        self::assertStringContainsString('<p dir="rtl">שלום</p>', (string) $message->getHTMLBody());
    }

    /**
     * The Arabic counterpart, in its explicit-direction variant and in base64:
     * the same family of labels, through the other transfer encoding.
     */
    public function testArabicMailLabelledIso88596EKeepsItsTextAndItsSenderName(): void
    {
        $message = $this->parse(
            sprintf('=?ISO-8859-6-E?B?%s?=', base64_encode("\xc3\xcd\xe5\xcf")),
            "Content-Type: text/plain; charset=ISO-8859-6-E\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . base64_encode("\xe5\xd1\xcd\xc8\xc7 \xc8\xe3\xe5") . "\r\n",
        );

        self::assertSame('أحمد', $this->senderName($message));
        self::assertSame('مرحبا بكم', trim((string) $message->getTextBody()));
    }

    private function parse(string $fromName, string $mime): Message
    {
        return MessageParser::fromString(
            sprintf("From: %s <sender@example.test>\r\n", $fromName)
            . "To: reader@example.test\r\n"
            . "Subject: Directional charset\r\n"
            . "Date: Sat, 21 Nov 2026 16:00:00 +0100\r\n"
            . "Message-ID: <directional@example.test>\r\n"
            . "MIME-Version: 1.0\r\n"
            . $mime,
            MessageParser::config(),
        );
    }

    /** The sender's name as MessageSyncer stores it in from_name. */
    private function senderName(Message $message): string
    {
        $from = $message->getFrom()->first();

        self::assertIsObject($from, 'the message has no sender');

        return AddressHelper::name($from->personal ?? '');
    }
}
