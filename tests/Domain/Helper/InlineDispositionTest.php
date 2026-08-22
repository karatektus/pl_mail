<?php

declare(strict_types=1);

namespace App\Tests\Domain\Helper;

use App\Domain\Helper\InlineDisposition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An SVG attachment is not an image as far as a browser is concerned.
 *
 * Three controllers decided what may render inline with
 * `str_starts_with($contentType, 'image/')`, and `image/svg+xml` satisfies it
 * while executing script as a top-level document. `nosniff` does not help: the
 * type is not being mislabelled, it genuinely is what it says.
 *
 * The type comes out of the MIME headers of an incoming mail, and the path is
 * reachable from the mail itself — resolveCids() rewrites every `cid:` in the
 * document, links included, so `<a href="cid:logo">Rechnung ansehen</a>` gets
 * the part id filled in by the application and opens the attachment route
 * without the `?download=1` every template adds.
 */
final class InlineDispositionTest extends TestCase
{
    /**
     * The finding, in one case.
     *
     * Kept as its own test rather than a row in the table below, because if
     * this one ever goes green-by-deletion the table would still look
     * thorough.
     */
    public function testAnSvgIsNotServedInline(): void
    {
        self::assertFalse(InlineDisposition::allows('image/svg+xml'));
    }

    #[DataProvider('types')]
    public function testWhatMayRenderInline(?string $contentType, bool $expected): void
    {
        self::assertSame($expected, InlineDisposition::allows($contentType));
    }

    /** @return iterable<string, array{?string, bool}> */
    public static function types(): iterable
    {
        yield 'png'                => ['image/png', true];
        yield 'jpeg'               => ['image/jpeg', true];
        yield 'gif'                => ['image/gif', true];
        yield 'webp'               => ['image/webp', true];

        yield 'svg'                => ['image/svg+xml', false];
        yield 'html'               => ['text/html', false];
        yield 'xhtml'              => ['application/xhtml+xml', false];
        yield 'pdf'                => ['application/pdf', false];
        yield 'unknown'            => ['application/octet-stream', false];

        // The near-misses. A mail header spells a type however it likes, and an
        // exact comparison against an unnormalised value is how an allow-list
        // quietly turns back into a deny-list — or, worse, how `image/svg+xml;
        // charset=utf-8` walks past the one entry that was supposed to stop it.
        yield 'parameters'         => ['image/png; charset=utf-8', true];
        yield 'uppercase'          => ['IMAGE/PNG', true];
        yield 'padded'             => ["  image/png\t", true];
        yield 'svg with charset'   => ['image/svg+xml; charset=utf-8', false];
        yield 'svg uppercase'      => ['Image/SVG+XML', false];

        yield 'null'               => [null, false];
        yield 'empty'              => ['', false];

        // Not a type at all. The prefix test would have accepted the first of
        // these, which is a fair summary of the difference between the two
        // approaches.
        yield 'prefix lookalike'   => ['image/svg+xml-not-really', false];
        yield 'image alone'        => ['image/', false];
    }

    /**
     * The header that makes a future mistake survivable.
     *
     * Bare `sandbox` is the strong form — opaque origin, no scripts, no forms,
     * no top-level navigation — so widening the list above becomes a bug rather
     * than a vulnerability. Asserted because "there is a CSP" and "the CSP does
     * anything" are different claims, and a token accidentally added to
     * `sandbox` (`allow-scripts`, say) would undo the whole thing while still
     * reading as a policy.
     */
    public function testTheSandboxGrantsNothing(): void
    {
        self::assertSame("default-src 'none'; sandbox", InlineDisposition::SANDBOX_CSP);
        self::assertStringNotContainsString('allow-', InlineDisposition::SANDBOX_CSP);
    }
}
