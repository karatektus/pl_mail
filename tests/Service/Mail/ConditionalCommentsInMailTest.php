<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Entity\Mail\Message;
use App\Service\Mail\MailBodySanitizer;
use Dom\HTMLDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Outlook's conditional comments never reach the reader as text.
 *
 * Mail built for Outlook wraps markup in `<!--[if mso]> … <![endif]-->`, and
 * the content meant for every other client in the "downlevel-revealed" form,
 * `<!--[if !mso]><!-->` … `<![endif]-->`. In the second form the closing
 * marker is not inside a comment at all: an HTML5 parser reads it as a bogus
 * comment, which is what a browser does too. A bpost parcel notification sent
 * through Amazon SES came out of the sanitizer with `![endif]-->` as visible
 * text in it (#175).
 *
 * Through the real sanitizer, because the claim is about what is stored.
 */
final class ConditionalCommentsInMailTest extends KernelTestCase
{
    private MailBodySanitizer $sanitizer;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->sanitizer = self::getContainer()->get(MailBodySanitizer::class);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function conditionalComments(): iterable
    {
        yield 'downlevel-revealed, closed inside a styled paragraph' => [
            '<p style="font-size: 14px; display: block; margin: 0 !important">'
            .'<!--[if !mso]><!-->Je pakje komt eraan<![endif]--></p>',
            'Je pakje komt eraan',
        ];

        yield 'downlevel-revealed, closed inside a styled cell' => [
            '<table><tr><td style="padding: 0; display: block; margin: 0 !important">'
            .'<!--[if !mso]><!-->Track &amp; trace<![endif]--></td></tr></table>',
            'Track & trace',
        ];

        yield 'the closing marker alone' => [
            '<p style="margin: 0 !important">Bezorging op 16-10-2026<![endif]--></p>',
            'Bezorging op 16-10-2026',
        ];

        yield 'the SES form, with an empty comment after the opener' => [
            '<p style="margin: 0"><!--[if !mso]><!-- --><span style="color: #e2001a">x</span><!--<![endif]--></p>',
            'x',
        ];

        yield 'Outlook-only table fragments around real content' => [
            '<!--[if mso]><table role="presentation"><tr><td width="600"><![endif]-->'
            .'<div style="max-width: 600px">Inhoud</div>'
            .'<!--[if mso]></td></tr></table><![endif]-->',
            'Inhoud',
        ];

        yield 'whitespace and case' => [
            "<p style=\"margin:0\"><!--[if !mso]><!-->Ruimte<![ENDIF] -->\n<!--[if gte mso 9]>\n<xml><o:OfficeDocumentSettings/></xml>\n<![endif]--></p>",
            'Ruimte',
        ];
    }

    #[DataProvider('conditionalComments')]
    public function testNoMarkerLeaksAsText(string $body, string $kept): void
    {
        $safe = $this->sanitize($this->mail($body));
        $text = (string) HTMLDocument::createFromString($safe, LIBXML_NOERROR, 'UTF-8')->body?->textContent;

        self::assertStringNotContainsStringIgnoringCase('endif', $safe, 'no marker survives, as text or otherwise');
        self::assertStringNotContainsStringIgnoringCase('[if', $safe);
        self::assertStringContainsString($kept, $text, 'and the content meant for every client but Outlook stays');
    }

    /**
     * Outlook-only content stays out: it is what the sender wrote for a
     * client plMail is not, and the HTML5 parser has it inside a comment.
     */
    public function testOutlookOnlyContentIsNotShown(): void
    {
        $safe = $this->sanitize($this->mail(
            '<!--[if mso]><p>Alleen voor Outlook</p><![endif]--><p>Voor iedereen</p>',
        ));

        self::assertStringNotContainsString('Alleen voor Outlook', $safe);
        self::assertStringContainsString('Voor iedereen', $safe);
    }

    /**
     * The leak came from the step before the sanitizer falling back to the
     * HTML 4 parser for the whole body, which is also the parser that ends a
     * link where a table begins. One comment that XML cannot hold must cost
     * neither the markers nor the link.
     */
    public function testAMarkerDoesNotSendTheBodyBackToTheOldParser(): void
    {
        $safe = $this->sanitize($this->mail(
            '<p style="margin:0"><!--[if !mso]><!-->Volg je pakje<![endif]--></p>'
            .'<!-- a comment -- with a double hyphen -->'
            .'<a href="https://track.example.test/p/1"><table><tr><td>Bekijk de status</td></tr></table></a>',
        ));

        $link = HTMLDocument::createFromString($safe, LIBXML_NOERROR, 'UTF-8')->querySelector('a[href]');

        self::assertStringNotContainsString('endif', $safe);
        self::assertNotNull($link);
        self::assertStringContainsString('Bekijk de status', (string) $link->textContent, 'the table is still inside the link');
    }

    /**
     * A body XML cannot carry at all — a control character, here — still goes
     * to the HTML 4 parser, and the markers must not come back that way.
     */
    public function testABodyTheHandoverRefusesStillLosesItsMarkers(): void
    {
        $safe = $this->sanitize($this->mail(
            "<p style=\"margin:0\"><!--[if !mso]><!-->Steuerzeichen \x0B hier<![endif]--></p>",
        ));

        self::assertStringNotContainsString('endif', $safe);
        self::assertStringContainsString('hier', $safe);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function smugglingAttempts(): iterable
    {
        yield 'script in downlevel-revealed content' => [
            '<!--[if !mso]><!--><script>alert(1)</script><![endif]-->',
        ];

        yield 'script in an Outlook-only block' => [
            '<!--[if mso]><script>alert(1)</script><![endif]-->',
        ];

        yield 'event handler after a stray marker' => [
            '<p><![endif]--><img src="https://example.test/x.png" onerror="alert(1)"></p>',
        ];

        yield 'markup built across a marker' => [
            '<p><scr<![endif]-->ipt>alert(1)</scr<![endif]-->ipt></p>',
        ];

        yield 'javascript link in revealed content' => [
            '<!--[if !mso]><!-- --><a href="javascript:alert(1)">x</a><!--<![endif]-->',
        ];

        yield 'marker inside an attribute value' => [
            '<p title="<![endif]--><script>alert(1)</script>">x</p>',
        ];
    }

    /**
     * A conditional comment is never a way past the allow-list.
     */
    #[DataProvider('smugglingAttempts')]
    public function testConditionalCommentsCannotSmuggleMarkup(string $body): void
    {
        $safe = $this->sanitize($this->mail($body));
        $doc  = HTMLDocument::createFromString($safe, LIBXML_NOERROR, 'UTF-8');

        self::assertNull($doc->querySelector('script'), 'no script element');
        self::assertNull($doc->querySelector('[onerror]'), 'no event handler');
        self::assertStringNotContainsStringIgnoringCase('javascript:', (string) $doc->querySelector('a')?->getAttribute('href'));
    }

    private function mail(string $body): string
    {
        return '<html><head><style>p { line-height: 1.4 }</style></head><body>'.$body.'</body></html>';
    }

    private function sanitize(string $html): string
    {
        $message           = new Message();
        $message->bodyHtml = $html;

        $this->sanitizer->sanitize($message);

        return (string) $message->bodyHtmlSafe;
    }
}
