<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Service\Mail\Html5CssInliner;
use Dom\HTMLDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The stylesheet meets an inline style the way the cascade says it does.
 *
 * The library keeps an inline property whatever the rule says. Mail that picks
 * a variant with `!important` — a Bazaarvoice review request switches each
 * product card on with `.d {display:block !important}` over an inline
 * `display:none` — came out with every card hidden (#271).
 */
final class Html5CssInlinerTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function cascade(): iterable
    {
        yield 'an important rule beats a plain inline style' => [
            '.d { display: block !important }',
            'display: none',
            'display: block !important;',
        ];

        yield 'a plain rule loses to the inline style' => [
            '.d { display: block }',
            'display: none',
            'display: none;',
        ];

        yield 'an important inline style beats an important rule' => [
            '.d { display: block !important }',
            'display: none !important',
            'display: none !important;',
        ];
    }

    #[DataProvider('cascade')]
    public function testTheCascadeDecides(string $css, string $inline, string $expected): void
    {
        self::assertSame($expected, $this->styleOf(
            '<style>' . $css . '</style><div id="t" class="d" style="' . $inline . '">x</div>',
        ));
    }

    /**
     * Everything else on the element stays where it was. Only the one
     * property the rule outranks is replaced.
     */
    public function testAnOutrankedPropertyIsReplacedInPlace(): void
    {
        self::assertSame(
            'color: red; display: block !important; margin: 0;',
            $this->styleOf(
                '<style>.d { display: block !important; color: blue }</style>'
                . '<div id="t" class="d" style="color: red; display: none; margin: 0">x</div>',
            ),
        );
    }

    /**
     * A form is matched as the div the reader will see. `form .a` is the
     * sender's rule for clients that can submit the form; plMail cannot, and
     * takes the fallback the sender wrote for that (#271).
     */
    public function testFormRulesDoNotMatchBecauseTheFormIsADiv(): void
    {
        $html = (new Html5CssInliner())->convert(
            '<html><head><style>form .a { display: none !important } .f { color: green }</style></head><body>'
            . '<form class="f" method="get" action="https://example.test/r"><div id="t" class="a" style="display: block">x</div></form>'
            . '</body></html>',
        );

        $document = HTMLDocument::createFromString($html, LIBXML_NOERROR, 'UTF-8');
        $wrapper  = $document->getElementById('t')?->parentElement;

        self::assertNull($document->querySelector('form'));
        self::assertSame('display: block', $document->getElementById('t')?->getAttribute('style'), 'no rule matched it at all');
        self::assertSame('DIV', $wrapper?->tagName);
        self::assertSame('color: green;', $wrapper->getAttribute('style'), 'rules on the form\'s class still apply to it');
        self::assertFalse($wrapper->hasAttribute('action'));
        self::assertFalse($wrapper->hasAttribute('method'));
    }

    private function styleOf(string $body): ?string
    {
        $html = (new Html5CssInliner())->convert('<html><head></head><body>' . $body . '</body></html>');

        return HTMLDocument::createFromString($html, LIBXML_NOERROR, 'UTF-8')->getElementById('t')?->getAttribute('style');
    }
}
