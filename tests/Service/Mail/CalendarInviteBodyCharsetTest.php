<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Entity\Mail\Message;
use App\Infrastructure\Imap\Utf8AwareMessageDecoder;
use App\Service\Mail\MailBodySanitizer;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Message as ImapMessage;

/**
 * The invite from a booking system that rendered as "Ã¼ber", end to end.
 *
 * The shape matters, because it is what made the bug look like the one already
 * fixed: multipart/mixed, a German body in quoted-printable, a text/calendar
 * part alongside it. The invite widget rendered perfectly and the body did not,
 * which pointed at the calendar part and at the MIME charset — and neither was
 * at fault. Both halves are asserted here so the next reader does not have to
 * re-run that elimination.
 *
 * The first half is the decode: bytes off the wire through the same decoder
 * ImapConnectionFactory installs, which is where "Stop believing a charset
 * label the bytes disprove" put its fix. It was already right. The second half
 * is the render, where the body was mangled by the sender's own
 * `<meta charset>` — a declaration no longer true of bytes the sync had
 * already converted, and which the sanitizer's parser believed anyway.
 */
final class CalendarInviteBodyCharsetTest extends KernelTestCase
{
    private const string SENTENCE = 'Diese Besprechung wurde über die Buchungsseite von Nenad Schobar geplant.';

    private const string MOJIBAKE = 'Ã¼ber';

    private const string ICS = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nMETHOD:REQUEST\r\n"
        . "BEGIN:VEVENT\r\nUID:booking-1@vergleich.org\r\nDTSTART:20260812T090000Z\r\nDTEND:20260812T093000Z\r\n"
        . "SUMMARY:Beratungsgespräch\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

    private MailBodySanitizer $sanitizer;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->sanitizer = self::getContainer()->get(MailBodySanitizer::class);
    }

    /**
     * The half that was already fixed, kept as a guard: a part that stamps
     * ISO-8859-1 over UTF-8 is read as UTF-8 even in a multipart tree, and
     * even with a text/calendar part beside it.
     */
    public function testTheMislabelledBodyPartStillDecodesAsUtf8(): void
    {
        $message = $this->parse($this->rawMail('ISO-8859-1'));

        self::assertStringContainsString(self::SENTENCE, (string) $message->getTextBody());
        self::assertStringContainsString(self::SENTENCE, (string) $message->getHTMLBody());
    }

    /** The calendar part is carried through untouched, widget and all. */
    public function testTheCalendarPartSurvivesIntact(): void
    {
        $message = $this->parse($this->rawMail('ISO-8859-1'));

        $calendar = null;

        foreach ($message->getAttachments() as $attachment) {
            if (true === str_contains((string) $attachment->getContentType(), 'calendar')) {
                $calendar = (string) $attachment->getContent();
            }
        }

        self::assertNotNull($calendar, 'The invite must reach the app as a text/calendar part.');
        self::assertStringContainsString('SUMMARY:Beratungsgespräch', $calendar);
    }

    /**
     * The half that was still broken. A correctly declared UTF-8 part, decoded
     * correctly, and still mojibake on screen — because bodyHtmlSafe is what
     * the app renders and the parser that produces it read the meta tag.
     */
    public function testTheRenderedBodyIsNotMangledByTheDocumentsOwnCharset(): void
    {
        $message = $this->sanitize(
            '<html><head><meta http-equiv="Content-Type" content="text/html; charset=iso-8859-1"></head>'
            . '<body><p>' . self::SENTENCE . '</p></body></html>',
        );

        self::assertStringContainsString(self::SENTENCE, (string) $message->bodyHtmlSafe);
        self::assertStringNotContainsString(self::MOJIBAKE, (string) $message->bodyHtmlSafe);
    }

    /** The short spelling is just as common and just as believed. */
    public function testTheShortMetaSpellingIsHandledToo(): void
    {
        $message = $this->sanitize('<html><head><meta charset="ISO-8859-1"></head><body><p>Grüße</p></body></html>');

        self::assertStringContainsString('Grüße', (string) $message->bodyHtmlSafe);
    }

    /**
     * The raw body is the extractor's input and must keep saying what the
     * sender said — the re-tag is for the copy that gets parsed.
     */
    public function testTheRawBodyKeepsTheSendersDeclaration(): void
    {
        $html    = '<html><head><meta charset="ISO-8859-1"></head><body><p>Grüße</p></body></html>';
        $message = $this->sanitize($html);

        self::assertSame($html, $message->bodyHtml);
    }

    /** A body that never declared anything must come out exactly as before. */
    public function testAnUndeclaredBodyIsUnaffected(): void
    {
        $message = $this->sanitize('<p>' . self::SENTENCE . '</p>');

        self::assertStringContainsString(self::SENTENCE, (string) $message->bodyHtmlSafe);
    }

    private function sanitize(string $html): Message
    {
        $message           = new Message();
        $message->bodyHtml = $html;

        $this->sanitizer->sanitize($message);

        return $message;
    }

    /** Parsed through the decoder ImapConnectionFactory installs, not the default. */
    private function parse(string $raw): ImapMessage
    {
        return ImapMessage::fromString($raw, Config::make([
            'decoding' => ['decoder' => ['message' => Utf8AwareMessageDecoder::class]],
        ]));
    }

    private function rawMail(string $declaredCharset): string
    {
        $body = quoted_printable_encode(self::SENTENCE);
        $html = quoted_printable_encode('<p>' . self::SENTENCE . '</p>');

        return "From: booking@vergleich.org\r\n"
            . "To: user@example.test\r\n"
            . "Subject: Terminbestaetigung\r\n"
            . "Date: Mon, 10 Aug 2026 10:00:00 +0200\r\n"
            . "Message-ID: <booking-1@vergleich.org>\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: multipart/mixed; boundary=\"OUTER\"\r\n\r\n"
            . "--OUTER\r\n"
            . "Content-Type: multipart/alternative; boundary=\"INNER\"\r\n\r\n"
            . "--INNER\r\n"
            . "Content-Type: text/plain; charset={$declaredCharset}\r\n"
            . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
            . $body . "\r\n"
            . "--INNER\r\n"
            . "Content-Type: text/html; charset={$declaredCharset}\r\n"
            . "Content-Transfer-Encoding: quoted-printable\r\n\r\n"
            . $html . "\r\n"
            . "--INNER--\r\n"
            . "--OUTER\r\n"
            . "Content-Type: text/calendar; method=REQUEST; charset=UTF-8; name=\"invite.ics\"\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . self::ICS
            . "--OUTER--\r\n";
    }
}
