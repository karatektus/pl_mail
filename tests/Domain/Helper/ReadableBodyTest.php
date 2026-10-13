<?php

declare(strict_types=1);

namespace App\Tests\Domain\Helper;

use App\Domain\Helper\ReadableBody;
use App\Entity\Mail\Message;
use PHPUnit\Framework\TestCase;

/**
 * What the extractors are handed when a mail has no plain-text part. Each test
 * is one way a mail's HTML is not its words.
 */
final class ReadableBodyTest extends TestCase
{
    public function testThePlainPartIsTakenWhenThereIsOne(): void
    {
        $message = new Message();
        $message->bodyText = "  Sendungsnummer: 123456789012\n";
        $message->bodyHtml = '<p>something else</p>';

        self::assertSame('Sendungsnummer: 123456789012', ReadableBody::of($message));
    }

    public function testAMailWithNoPlainPartIsReadFromItsHtml(): void
    {
        $message = new Message();
        $message->bodyText = " \r\n";
        $message->bodyHtml = '<p>Trackingnummer</p><p>00340434156079686499</p>';

        self::assertSame("Trackingnummer\n00340434156079686499", ReadableBody::of($message));
    }

    public function testAMailWithNeitherIsTheEmptyString(): void
    {
        self::assertSame('', ReadableBody::of(new Message()));
    }

    public function testStylesheetsScriptsAndCommentsAreNotProse(): void
    {
        $html = '<head><title>Betreff</title><style>h1 { font-size:40px } .a { width:123456789012px }</style></head>'
            . '<!--[if mso]><style>sup { font-size: 100% }</style><![endif]-->'
            . '<script>var id = "999999999999";</script>'
            . '<p>Guten Tag</p>';

        self::assertSame('Guten Tag', ReadableBody::fromHtml($html));
    }

    public function testCellsAndParagraphsBecomeLines(): void
    {
        $html = '<table><tr><td>Status</td><td>In Lieferung</td></tr></table><div>Eins<br>Zwei</div>';

        self::assertSame("Status\nIn Lieferung\nEins\nZwei", ReadableBody::fromHtml($html));
    }

    public function testInlineMarkupDoesNotSplitAWord(): void
    {
        self::assertSame('Sendungsnummer', ReadableBody::fromHtml('<p><strong>Sendungs</strong>nummer</p>'));
    }

    public function testALinkIsItsTextAndNotItsAddress(): void
    {
        $html = '<p><a href="https://click.example.test/t/00340434156079686499">Sendung verfolgen</a></p>';

        self::assertSame('Sendung verfolgen', ReadableBody::fromHtml($html));
    }

    public function testEntitiesAndInvisibleSpacesAreResolved(): void
    {
        $html = "<p>&Uuml;bergabe&nbsp;am&nbsp;24.9.2026 \u{200B}</p><p>\u{200B}</p>";

        self::assertSame('Übergabe am 24.9.2026', ReadableBody::fromHtml($html));
    }

    /**
     * Microsoft Graph has no plain-text part to hand over for an HTML mail: the
     * sync stores its `bodyPreview` as the text, and that preview is cut at 255
     * characters. A tracking number further down the mail is therefore not in
     * the "plain part" at all — it is only in the HTML — so for a Graph mail
     * the HTML is what is read, and the preview is the fallback.
     */
    public function testAGraphMailIsReadFromItsHtmlAndNotFromItsCutOffPreview(): void
    {
        $message = self::graphMail(html: '<p>' . self::PADDING . '</p><p>N° du colis ' . self::NUMBER . '</p>');

        // The preview ends inside the padding: 255 of its 335 characters. The
        // number sits after them, which is the whole point of the fixture.
        self::assertSame(255, mb_strlen((string) $message->bodyText));
        self::assertStringNotContainsString(self::NUMBER, (string) $message->bodyText);

        self::assertSame(self::PADDING . "\nN° du colis " . self::NUMBER, ReadableBody::of($message));
    }

    public function testAGraphMailWithoutHtmlKeepsItsFullTextBody(): void
    {
        $message = new Message();
        $message->graphId = 'AAMkAD-text-mail';
        $message->bodyText = self::PADDING . "\nN° du colis " . self::NUMBER . "\n";
        $message->bodyHtml = '';

        self::assertSame(self::PADDING . "\nN° du colis " . self::NUMBER, ReadableBody::of($message));
    }

    public function testAGraphMailWhoseHtmlHasNoWordsFallsBackToItsPreview(): void
    {
        $message = self::graphMail(html: '<div><img src="cid:logo"></div><style>.a { width: 1px }</style>');
        $message->bodyText = 'Votre colis arrive bientôt';

        self::assertSame('Votre colis arrive bientôt', ReadableBody::of($message));
    }

    public function testAMailThatIsNotFromGraphKeepsItsPlainPartHoweverLongItsHtml(): void
    {
        $message = self::graphMail(html: '<p>' . self::PADDING . '</p><p>N° du colis ' . self::NUMBER . '</p>');
        $message->graphId = null;

        // Same two bodies as the first test, minus the Graph origin: the plain
        // part is what the sender wrote for this purpose and still comes first.
        self::assertSame((string) $message->bodyText, ReadableBody::of($message));
        self::assertStringNotContainsString(self::NUMBER, ReadableBody::of($message));
    }

    /** 28 characters repeated twelve times, less the trailing space: 335. */
    private const string PADDING = 'Lorem ipsum dolor sit amet. Lorem ipsum dolor sit amet. Lorem ipsum dolor sit amet. '
        . 'Lorem ipsum dolor sit amet. Lorem ipsum dolor sit amet. Lorem ipsum dolor sit amet. '
        . 'Lorem ipsum dolor sit amet. Lorem ipsum dolor sit amet. Lorem ipsum dolor sit amet. '
        . 'Lorem ipsum dolor sit amet. Lorem ipsum dolor sit amet. Lorem ipsum dolor sit amet.';

    private const string NUMBER = '6A12345678901';

    /**
     * A Graph mail the way the sync stores one: the full HTML, and as its text
     * the first 255 characters of what that HTML reads as.
     */
    private static function graphMail(string $html): Message
    {
        $message = new Message();
        $message->graphId = 'AAMkAD-html-mail';
        $message->bodyHtml = $html;
        $message->bodyText = mb_substr(ReadableBody::fromHtml($html), 0, 255);

        return $message;
    }
}
