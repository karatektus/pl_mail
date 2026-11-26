<?php

declare(strict_types=1);

namespace App\Tests\Service\Task;

use App\Service\Task\TextLinkifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Text in a task is not markup, and only an address becomes a link.
 *
 * Marked safe in Twig, so this is the only thing between a title from a CalDAV
 * server and the application's own document. The first two cases are the ones
 * that matter: everything is escaped, and no scheme but the two it names can ever
 * reach an href.
 */
final class TextLinkifierTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function cases(): iterable
    {
        yield 'markup is escaped rather than rendered' => [
            '<img src=x onerror=alert(1)>',
            '&lt;img src=x onerror=alert(1)&gt;',
        ];
        yield 'another scheme stays text' => ['javascript:alert(1)', 'javascript:alert(1)'];
        yield 'a quote cannot leave the attribute' => [
            'https://example.org/"onmouseover="x',
            '<a href="https://example.org/" target="_blank" rel="noopener noreferrer" class="text-accent underline">https://example.org/</a>&quot;onmouseover=&quot;x',
        ];
        yield 'a bare www gets a scheme in the href alone' => [
            'see www.example.org now',
            'see <a href="https://www.example.org" target="_blank" rel="noopener noreferrer" class="text-accent underline">www.example.org</a> now',
        ];
        yield 'sentence punctuation stays outside' => [
            'https://example.org.',
            '<a href="https://example.org" target="_blank" rel="noopener noreferrer" class="text-accent underline">https://example.org</a>.',
        ];
        yield 'an unbalanced bracket stays outside' => [
            '(https://example.org)',
            '(<a href="https://example.org" target="_blank" rel="noopener noreferrer" class="text-accent underline">https://example.org</a>)',
        ];
        yield 'a balanced bracket stays in' => [
            'https://example.org/Foo_(bar)',
            '<a href="https://example.org/Foo_(bar)" target="_blank" rel="noopener noreferrer" class="text-accent underline">https://example.org/Foo_(bar)</a>',
        ];
        yield 'an ampersand is one address, escaped once' => [
            'https://example.org/?a=1&b=2',
            '<a href="https://example.org/?a=1&amp;b=2" target="_blank" rel="noopener noreferrer" class="text-accent underline">https://example.org/?a=1&amp;b=2</a>',
        ];
    }

    #[DataProvider('cases')]
    public function testItEscapesTheTextAndLinksOnlyAddresses(string $text, string $expected): void
    {
        self::assertSame($expected, (new TextLinkifier())->linkify($text));
    }

    public function testNothingIsNothing(): void
    {
        self::assertSame('', (new TextLinkifier())->linkify(null));
    }
}
