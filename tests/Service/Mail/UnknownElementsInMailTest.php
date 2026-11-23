<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Entity\Mail\Message;
use App\Service\Mail\MailBodySanitizer;
use Dom\HTMLDocument;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * An element the allow-list does not name is unwrapped, not dropped.
 *
 * Nayax wraps every mail it sends in `<message id="mail_body">`. Symfony's
 * sanitizer drops an element it does not know together with everything inside
 * it, so the whole letter went and plMail showed a blank body: the user
 * invitations, the refund requests and confirmations, every one of them.
 *
 * The elements whose content is not meant to be read are still dropped by name,
 * and the last test is the positive control for that: unwrapping by default
 * must not turn a script or an Outlook settings block into text.
 */
final class UnknownElementsInMailTest extends KernelTestCase
{
    private MailBodySanitizer $sanitizer;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->sanitizer = self::getContainer()->get(MailBodySanitizer::class);
    }

    public function testALetterWrappedInAnUnknownElementIsKept(): void
    {
        $document = $this->sanitize(
            '<!DOCTYPE html PUBLIC " -//W3C//DTD XHTML 1.0 Transitional//EN" '
            . '"http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">'
            . '<message id="mail_body"><table bgcolor="#FAFAFA" width="100%"><tbody><tr><td>'
            . '<p style="color: #262626;">You have been invited to join Nayax.</p>'
            . '<a href="https://my.nayax.example/invite?t=1">Accept invitation</a>'
            . '</td></tr></tbody></table></message>',
        );

        $text = (string) $document->body?->textContent;

        self::assertStringContainsString('You have been invited to join Nayax.', $text);
        self::assertSame('https://my.nayax.example/invite?t=1', $document->querySelector('a')?->getAttribute('href'));
        self::assertSame('#FAFAFA', $document->querySelector('table')?->getAttribute('bgcolor'), 'the layout inside comes too');
        self::assertNull($document->querySelector('message'), 'the wrapper itself does not survive');
        self::assertNull($document->querySelector('[id]'), 'nor does its id');
    }

    public function testAnUnknownElementInsideTheLetterKeepsItsText(): void
    {
        $document = $this->sanitize(
            '<html><body><p>Hello <x-name data-x="1">James</x-name>, your refund is <o:p>approved</o:p>.</p></body></html>',
        );

        self::assertStringContainsString('Hello James, your refund is approved.', (string) $document->body?->textContent);
        self::assertNull($document->querySelector('[data-x]'));
    }

    public function testWhatWasNeverMeantToBeReadIsStillDropped(): void
    {
        $document = $this->sanitize(
            '<html><body><message>'
            . '<p>Visible</p>'
            . '<script>alert("script")</script>'
            . '<style>.a{color:red}</style>'
            . '<xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml>'
            . '<template><p>Template</p></template>'
            . '<noscript><p>Noscript</p></noscript>'
            . '<iframe src="https://evil.example/"></iframe>'
            . '<textarea>Prefilled</textarea>'
            . '</message></body></html>',
        );

        $text = (string) $document->body?->textContent;

        self::assertStringContainsString('Visible', $text, 'positive control: the readable part is there');

        foreach (['alert', 'color:red', '96', 'Template', 'Noscript', 'Prefilled'] as $hidden) {
            self::assertStringNotContainsString($hidden, $text);
        }

        self::assertNull($document->querySelector('script, style, iframe, textarea, template'));
    }

    /** A signature or a composed body goes through the same allow-list. */
    public function testAStylesheetInASignatureIsNotShownAsText(): void
    {
        $clean = $this->sanitizer->sanitizeFragment('<style>.sig{color:red}</style><p>James</p><custom-tag>Director</custom-tag>');

        self::assertStringContainsString('James', $clean);
        self::assertStringContainsString('Director', $clean, 'positive control: an unknown element is unwrapped here too');
        self::assertStringNotContainsString('color:red', $clean);

        $composed = $this->sanitizer->sanitizeComposedBody('<title>Draft</title><p>Hello</p><style>p{margin:0}</style>');

        self::assertStringContainsString('Hello', $composed);
        self::assertStringNotContainsString('Draft', $composed);
        self::assertStringNotContainsString('margin', $composed);
    }

    /**
     * The check the repair uses to choose which stored mail to sanitise
     * again: yes for an element the allow-list does not name, no for an
     * ordinary letter, whose `<meta>` and document tags must not count.
     */
    public function testTheRepairCheckFindsAnElementTheAllowListDoesNotName(): void
    {
        $known = $this->sanitizer->knownElements();

        self::assertTrue(MailBodySanitizer::containsElementOutside('<message id="mail_body"><p>Hi</p></message>', $known));
        self::assertTrue(MailBodySanitizer::containsElementOutside('<p>Hi<o:p>&nbsp;</o:p></p>', $known));
        self::assertTrue(MailBodySanitizer::containsElementOutside('<P>Hi <X-Name>James</X-Name></P>', $known), 'case does not hide one');

        self::assertFalse(MailBodySanitizer::containsElementOutside(
            '<!DOCTYPE html><html><head><meta charset="utf-8"><link rel="stylesheet" href="a.css"><title>T</title>'
            . '<style>td{color:red}</style></head><body><table><tr><td><a href="https://example.test/">Hi</a><br>'
            . '<img src="https://example.test/a.png"></td></tr></table><!-- a comment --></body></html>',
            $known,
        ));
        self::assertFalse(MailBodySanitizer::containsElementOutside('just text, 1 < 2', $known));
    }

    private function sanitize(string $html): HTMLDocument
    {
        $message           = new Message();
        $message->bodyHtml = $html;

        $this->sanitizer->sanitize($message);

        return HTMLDocument::createFromString((string) $message->bodyHtmlSafe, LIBXML_NOERROR, 'UTF-8');
    }
}
