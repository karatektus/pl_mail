<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Entity\Mail\Message;
use App\Service\Mail\MailBodySanitizer;
use Dom\HTMLDocument;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * What a form in a mail leaves behind is its content, not nothing.
 *
 * A BAUHAUS review request, sent through Bazaarvoice, puts its whole first
 * product card inside a `<form>`: photo, name and star links, with the review
 * fields beside them. The sanitizer dropped the form with all of it, and the
 * card's photo never reached the image proxy (#271).
 *
 * Through the real sanitizer, because the claim is about what is stored.
 */
final class FormsInMailTest extends KernelTestCase
{
    private MailBodySanitizer $sanitizer;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->sanitizer = self::getContainer()->get(MailBodySanitizer::class);
    }

    public function testTheContentOfAFormSurvivesAndTheFormDoesNot(): void
    {
        $document = $this->sanitize(
            '<html><head></head><body><form method="get" action="https://reviews.example.test/r">'
            . '<img src="https://media.example.test/product.jpg" alt="Drahtbürste">'
            . '<a href="https://reviews.example.test/r?rating=5">5 Sterne</a>'
            . '<textarea name="text">Vorausgefüllt</textarea>'
            . '<select name="x"><option>Auswahl</option></select>'
            . '<input type="text" name="title">'
            . '</form></body></html>',
        );

        self::assertNull($document->querySelector('form'));
        self::assertSame('https://media.example.test/product.jpg', $document->querySelector('img')?->getAttribute('src'));
        self::assertSame('https://reviews.example.test/r?rating=5', $document->querySelector('a')?->getAttribute('href'));
        self::assertNull($document->querySelector('[action]'), 'the form\'s target does not ride along on anything');

        // The controls go, and with them what they hold: a field's value is
        // not part of the letter.
        self::assertNull($document->querySelector('input, textarea, select'));
        self::assertStringNotContainsString('Vorausgefüllt', (string) $document->body?->textContent);
        self::assertStringNotContainsString('Auswahl', (string) $document->body?->textContent);
    }

    /**
     * The shape of the review request, cut down to the rules that pick a
     * variant. `.a` is the star-link card and `.b` the interactive form; the
     * sender hides `.a` only where the form survives. Inside each, `.c` is the
     * Outlook table and `.d` the card every other client shows, switched on
     * with `!important` over its own inline `display:none`.
     */
    public function testTheReviewRequestShowsTheCardMeantForClientsWithoutForms(): void
    {
        $document = $this->sanitize(
            '<html><head><style>'
            . 'form .a {display:none !important} form .b {display:block !important}'
            . '.c {display:none !important} .d {display:block !important}'
            . '</style></head><body>'
            . '<form method="get" action="https://reviews.example.test/r">'
            . '<div class="a" style="display: block;">'
            . '<div class="c"><table><tr><td><img src="https://media.example.test/outlook.jpg" alt=""></td></tr></table></div>'
            . '<div class="d" style="display: none;"><img src="https://media.example.test/card.jpg" alt=""></div>'
            . '</div>'
            . '<div class="b" style="display: none;"><span>Bewertungsformular</span></div>'
            . '</form></body></html>',
        );

        // img → div.d → div.a
        self::assertStringContainsString('display: block', $this->styleAbove($document, 'img[src$="card.jpg"]', 1), 'the card is shown');
        self::assertStringContainsString('display: block', $this->styleAbove($document, 'img[src$="card.jpg"]', 2), 'and so is the variant it is in');
        // img → td → tr → tbody → table → div.c
        self::assertStringContainsString('display: none', $this->styleAbove($document, 'img[src$="outlook.jpg"]', 5), 'its Outlook twin is not');
        // span → div.b
        self::assertStringContainsString('display: none', $this->styleAbove($document, 'span', 1), 'nor is the interactive variant');
    }

    private function styleAbove(HTMLDocument $document, string $selector, int $levels): string
    {
        $element = $document->querySelector($selector);

        for ($i = 0; $i < $levels && null !== $element; $i++) {
            $element = $element->parentElement;
        }

        return (string) $element?->getAttribute('style');
    }

    private function sanitize(string $html): HTMLDocument
    {
        $message           = new Message();
        $message->bodyHtml = $html;

        $this->sanitizer->sanitize($message);

        return HTMLDocument::createFromString((string) $message->bodyHtmlSafe, LIBXML_NOERROR, 'UTF-8');
    }
}
