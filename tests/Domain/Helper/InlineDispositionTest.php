<?php

declare(strict_types=1);

namespace App\Tests\Domain\Helper;

use App\Domain\Helper\InlineDisposition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\HeaderUtils;

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

    /**
     * A sender-chosen `text/javascript` served under its own label satisfies
     * `script-src 'self'`; only the inline raster types keep their type.
     */
    public function testOnlyInlineTypesAreServedUnderTheirOwnLabel(): void
    {
        self::assertSame('application/octet-stream', InlineDisposition::servedType('text/javascript'));
        self::assertSame('application/octet-stream', InlineDisposition::servedType('text/css'));
        self::assertSame('application/octet-stream', InlineDisposition::servedType(null));
        self::assertSame('image/png', InlineDisposition::servedType('IMAGE/PNG; name=x.png'));
    }

    #[DataProvider('labels')]
    public function testWhichLabelsLetTheBytesDecide(?string $contentType, bool $expected): void
    {
        self::assertSame($expected, InlineDisposition::isUnlabelled($contentType));
    }

    /** @return iterable<string, array{?string, bool}> */
    public static function labels(): iterable
    {
        yield 'octet-stream'       => ['application/octet-stream', true];
        // Not a registered type, and what Vaultwarden's mails and plenty of
        // others put on their inline logos (#213).
        yield 'image/octet-stream' => ['image/octet-stream', true];
        yield 'binary'             => ['binary/octet-stream', true];
        yield 'with a name'        => ['Application/Octet-Stream; name="logo.png"', true];
        yield 'empty'              => ['', true];
        yield 'null'               => [null, true];

        // A type the sender did state is taken at its word — sniffing these
        // would let a declared `text/html` be re-read as something else.
        yield 'png'                => ['image/png', false];
        yield 'svg'                => ['image/svg+xml', false];
        yield 'html'               => ['text/html', false];
        yield 'pdf'                => ['application/pdf', false];
    }

    #[DataProvider('contents')]
    public function testSniffingOnlyEverYieldsATypeOnTheList(string $bytes, ?string $expected): void
    {
        self::assertSame($expected, InlineDisposition::sniff($bytes));
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function contents(): iterable
    {
        yield 'png' => [(string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true), 'image/png'];
        yield 'gif' => [(string) base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', true), 'image/gif'];

        // The reason sniff() exists as a gate and not a lookup: an unlabelled
        // part that turns out to be markup is still a download.
        yield 'svg with a script' => ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', null];
        yield 'html'              => ["<!DOCTYPE html>\n<html><body><script>alert(1)</script></body></html>", null];
        yield 'pdf'               => ["%PDF-1.4\n%\xe2\xe3\xcf\xd3\n1 0 obj\n<<>>\nendobj\n", null];
        yield 'empty'             => ['', null];
    }

    /** A path in an attachment name used to be a 500 out of makeDisposition(). */
    public function testAFilenameWithAPathStillMakesAValidHeader(): void
    {
        $name = InlineDisposition::headerFilename("../Bericht/Q3\\Übersicht\r\n.pdf");

        self::assertSame('.._Bericht_Q3_Übersicht.pdf', $name);
        self::assertStringContainsString(
            "filename*=utf-8''",
            HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $name, 'attachment.pdf'),
        );
        self::assertSame('attachment', InlineDisposition::headerFilename("\x00\r\n "), 'nothing left is the default, not an empty name');
    }
}
