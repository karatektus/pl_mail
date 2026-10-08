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
}
