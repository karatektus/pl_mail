<?php

declare(strict_types=1);

namespace App\Tests\Domain\Helper;

use App\Domain\Helper\SvgSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An uploaded logo is served publicly and drawn on every page its owner opens
 * (#48), so what survives the sanitiser is what anybody who opens its URL
 * gets. These pin both halves: a real logo comes through as drawn, and
 * nothing that runs or fetches does.
 */
final class SvgSanitizerTest extends TestCase
{
    private const string HEAD = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 48 48">';

    /** The shape of what Illustrator and Inkscape write: a style block, classes, a gradient, a clip. */
    public function testAnOrdinaryLogoComesThroughAsDrawn(): void
    {
        $svg = self::HEAD
            . '<defs><style>.a{fill:url(#g)}</style>'
            . '<linearGradient id="g" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="#f00"/><stop offset="1" stop-color="#00f"/></linearGradient>'
            . '<clipPath id="c"><circle cx="24" cy="24" r="20"/></clipPath></defs>'
            . '<g clip-path="url(#c)"><rect class="a" width="48" height="48" rx="8"/>'
            . '<path d="M8 8h32v32H8z" fill="none" stroke="#fff" stroke-width="2"/>'
            . '<use href="#g"/><use xlink:href="#g"/></g></svg>';

        $clean = $this->clean($svg);

        foreach (['<style>.a{fill:url(#g)}</style>', 'linearGradient', 'clip-path="url(#c)"', 'stroke-width="2"', 'href="#g"', 'xlink:href="#g"'] as $kept) {
            self::assertStringContainsString($kept, $clean);
        }
    }

    /** @return iterable<string, array{string, string}> what goes, and a fragment that must not survive */
    public static function hostile(): iterable
    {
        yield 'script element' => ['<script>alert(1)</script>', 'alert'];
        yield 'event handler' => ['<rect width="1" height="1" onload="alert(1)"/>', 'onload'];
        yield 'event handler on the root' => ['<g onclick="alert(1)"/>', 'onclick'];
        yield 'embedded HTML' => ['<foreignObject><div xmlns="http://www.w3.org/1999/xhtml">x</div></foreignObject>', 'foreignObject'];
        yield 'a link' => ['<a href="https://evil.example"><rect width="1" height="1"/></a>', 'evil.example'];
        yield 'an outside picture' => ['<image href="https://evil.example/x.png" width="1" height="1"/>', 'evil.example'];
        yield 'feImage' => ['<filter id="f"><feImage href="https://evil.example/x.png"/></filter>', 'feImage'];
        yield 'use pointing out' => ['<use href="https://evil.example/s.svg#x"/>', 'evil.example'];
        yield 'javascript href' => ['<use href="javascript:alert(1)"/>', 'javascript'];
        yield 'url() fill to the network' => ['<rect width="1" height="1" fill="url(https://evil.example/#g)"/>', 'evil.example'];
        yield 'url() in a style attribute' => ['<rect width="1" height="1" style="fill:url(https://evil.example/#g)"/>', 'evil.example'];
        yield 'import in a style block' => ['<style>@import url(https://evil.example/x.css);</style>', 'evil.example'];
        yield 'escaped url in a style block' => ['<style>.a{fill:\\75rl(https://evil.example/)}</style>', 'evil.example'];
        yield 'animation that rewrites an attribute' => ['<set attributeName="href" to="javascript:alert(1)"/>', 'javascript'];
        yield 'editor bookkeeping' => ['<g xmlns:inkscape="http://www.inkscape.org/namespaces/inkscape" inkscape:label="Layer 1"/>', 'inkscape:label'];
        yield 'a comment' => ['<!-- secret --><g/>', 'secret'];
    }

    #[DataProvider('hostile')]
    public function testWhatRunsOrFetchesIsRemoved(string $inner, string $gone): void
    {
        self::assertStringNotContainsString($gone, $this->clean(self::HEAD . $inner . '</svg>'));
    }

    /** Entities are where expansion bombs and external entities live; no drawing program needs them. */
    public function testADoctypeIsRefusedRatherThanParsed(): void
    {
        $bomb = '<?xml version="1.0"?><!DOCTYPE svg [<!ENTITY a "aaaaaaaaaa"><!ENTITY b "&a;&a;&a;&a;">]>'
            . self::HEAD . '<text>&b;</text></svg>';

        self::assertNull(new SvgSanitizer()->sanitize($bomb));
    }

    /** @return iterable<string, array{string}> */
    public static function notALogo(): iterable
    {
        yield 'empty' => [''];
        yield 'not XML' => ['this is not a drawing'];
        yield 'HTML' => ['<html><body><svg viewBox="0 0 1 1"></svg></body></html>'];
        yield 'an svg root outside the SVG namespace' => ['<svg viewBox="0 0 1 1"><rect/></svg>'];
        yield 'no size at all' => ['<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>'];
        yield 'only a relative size' => ['<svg xmlns="http://www.w3.org/2000/svg" width="100%" height="100%"><rect/></svg>'];
    }

    #[DataProvider('notALogo')]
    public function testWhatIsNotALogoIsRefused(string $svg): void
    {
        self::assertNull(new SvgSanitizer()->sanitize($svg));
    }

    /** Every consumer scales the logo, and only a viewBox says how. */
    public function testAWidthAndHeightBecomeTheViewBox(): void
    {
        $clean = $this->clean('<svg xmlns="http://www.w3.org/2000/svg" width="120px" height="80"><rect width="120" height="80"/></svg>');

        self::assertStringContainsString('viewBox="0 0 120 80"', $clean);
    }

    public function testAFileOverTheLimitIsRefused(): void
    {
        $svg = self::HEAD . '<desc>' . str_repeat('x', SvgSanitizer::MAX_BYTES) . '</desc></svg>';

        self::assertNull(new SvgSanitizer()->sanitize($svg));
    }

    private function clean(string $svg): string
    {
        $clean = new SvgSanitizer()->sanitize($svg);

        self::assertNotNull($clean, 'the drawing itself is acceptable');

        return $clean;
    }
}
