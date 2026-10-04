<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Entity\Mail\Message;
use App\Service\Mail\MailBodySanitizer;
use Dom\HTMLDocument;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A link wrapped around a table is still a link when the mail is shown.
 *
 * Newsletters and job alerts build each card as one `<a>` around a table, so
 * the whole card is the click target. The step that inlines the stylesheet
 * parsed with libxml's HTML 4 parser, which ends an `<a>` where a table starts:
 * the stored body had an empty link and, after it, a card that went nowhere.
 * The href was still there, so nothing looked stripped; the mail simply could
 * not be clicked, and the same mail worked in every other client.
 *
 * Through the real sanitizer rather than the inliner alone, because the claim
 * is about what is stored, and that is the product of both parsers in turn.
 */
final class TableInsideLinkTest extends KernelTestCase
{
    private MailBodySanitizer $sanitizer;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->sanitizer = self::getContainer()->get(MailBodySanitizer::class);
    }

    public function testTheLinkStillWrapsTheCard(): void
    {
        $safe = $this->sanitize(<<<'HTML'
            <html><head><style>p { margin: 0 }</style></head><body>
            <table><tr><td>
              <a href="https://jobs.example.test/listing?pos=101&amp;ja=414778998" style="display:block">
                <table width="100%"><tr><td><p>Senior System Engineer</p></td></tr></table>
                <table><tr><td><p>Frankfurt am Main</p></td></tr></table>
              </a>
            </td></tr></table>
            </body></html>
            HTML);

        $link = HTMLDocument::createFromString($safe, LIBXML_NOERROR, 'UTF-8')->querySelector('a[href]');

        self::assertNotNull($link, 'the link is in the stored body');
        self::assertStringContainsString('Senior System Engineer', (string) $link->textContent, 'and the card is inside it, not after it');
        self::assertStringContainsString('Frankfurt am Main', (string) $link->textContent);
        self::assertStringContainsString('pos=101', (string) $link->getAttribute('href'));
    }

    /**
     * The reason the step exists at all, on the new parser: a rule from the
     * mail's stylesheet still lands on the element it selects. The library
     * matches with XPath, and on a tree left in the XHTML namespace every
     * selector matches nothing — a body that renders, with no styling, and no
     * error anywhere.
     */
    public function testTheStylesheetIsStillInlined(): void
    {
        $safe = $this->sanitize(
            '<html><head><style>.title { color: #cc0000 }</style></head><body>'
            .'<a href="https://example.test/"><table><tr><td><p class="title">Grüße &amp; mehr</p></td></tr></table></a>'
            .'</body></html>',
        );

        $title = HTMLDocument::createFromString($safe, LIBXML_NOERROR, 'UTF-8')->querySelector('a p');

        self::assertNotNull($title);
        self::assertStringContainsString('#cc0000', (string) $title->getAttribute('style'));
        self::assertSame('Grüße & mehr', $title->textContent, 'and the text went through both parsers unharmed');
    }

    /**
     * What Outlook writes: elements and attributes with prefixes that were
     * never declared the way XML wants them. The tree is handed between the
     * two parsers as XML, so this is the markup most likely to be refused
     * there — and a refusal has to cost the nesting at worst, never the body.
     */
    public function testOfficeMarkupStillYieldsABody(): void
    {
        $safe = $this->sanitize(
            '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:v="urn:schemas-microsoft-com:vml"><body>'
            .'<p class="MsoNormal">Hallo<o:p></o:p></p>'
            .'<a href="https://example.test/"><table><tr><td>Angebot</td></tr></table></a>'
            ."<p>Steuerzeichen \x0B hier</p>"
            .'</body></html>',
        );

        self::assertStringContainsString('Hallo', $safe);
        self::assertStringContainsString('Angebot', $safe);
        self::assertStringContainsString('https://example.test/', $safe);
    }

    private function sanitize(string $html): string
    {
        $message           = new Message();
        $message->bodyHtml = $html;

        $this->sanitizer->sanitize($message);

        return (string) $message->bodyHtmlSafe;
    }
}
