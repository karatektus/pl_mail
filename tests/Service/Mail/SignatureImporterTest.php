<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail;

use App\Service\Mail\ImageProxyFetcher;
use App\Service\Mail\MailBodySanitizer;
use App\Service\Mail\SignatureImporter;
use App\Service\Mail\SignatureProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * A signature pasted as HTML: what is kept, what is reported, what is fetched.
 *
 * The real sanitiser and the real image fetcher, over a mock HTTP client. The
 * report is a claim about what the sanitiser did, so a stubbed sanitiser would
 * only test that the report agrees with the stub.
 */
final class SignatureImporterTest extends TestCase
{
    /** A one-pixel PNG. */
    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    /** A literal public address: passes the fetcher's range check without a resolver. */
    private const string HOST = 'https://93.184.216.34';

    /** @var list<string> */
    private array $requested = [];

    // ── the report ───────────────────────────────────────────────────────────

    public function testASignatureTheSanitiserLeavesAloneReportsNothingRemoved(): void
    {
        $imported = $this->importer()->import(
            '<p style="color: #333">Paul <b>Joder</b><br><a href="https://example.test">example.test</a></p>',
        );

        self::assertSame([], $imported->removedElements);
        self::assertSame([], $imported->removedAttributes);
        self::assertFalse($imported->changedAnything());
        self::assertStringContainsString('<b>Joder</b>', $imported->html);
    }

    public function testWhatTheSanitiserDropsIsNamed(): void
    {
        $imported = $this->importer()->import(
            '<style>.sig { color: red }</style>'
            . '<div class="sig" id="s" onclick="track()"><p>Paul</p><script>alert(1)</script></div>',
        );

        self::assertSame(['script', 'style'], $imported->removedElements);
        // `id` is not in the list because the sanitiser keeps it: the report
        // is of what happened, not of what one might expect to.
        self::assertSame(['class', 'onclick'], $imported->removedAttributes);
        self::assertTrue($imported->changedAnything());
        self::assertStringNotContainsString('alert', $imported->html);
        self::assertStringContainsString('<p>Paul</p>', $imported->html);
    }

    /** A whole page pasted as a signature loses its scaffolding; that is not news. */
    public function testTheScaffoldingOfAFullDocumentIsNotReported(): void
    {
        $imported = $this->importer()->import(
            '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Sig</title></head><body><p>Paul</p></body></html>',
        );

        self::assertSame([], $imported->removedElements);
        self::assertStringContainsString('<p>Paul</p>', $imported->html);
    }

    public function testAnEmptySignatureIsEmptyAndReportsNothing(): void
    {
        $imported = $this->importer()->import("  \n ");

        self::assertSame('', $imported->html);
        self::assertFalse($imported->changedAnything());
        self::assertSame(0, $imported->embeddedImages + $imported->remoteImages);
    }

    // ── images ───────────────────────────────────────────────────────────────

    public function testARemoteImageIsFetchedAndStoredInTheSignature(): void
    {
        $imported = $this->importer()->import(
            '<p>Paul</p><img src="' . self::HOST . '/logo.png?v=1&amp;s=2" alt="Acme" width="80">',
        );

        self::assertSame([self::HOST . '/logo.png?v=1&s=2'], $this->requested, 'the URL is fetched decoded, once');
        self::assertSame(1, $imported->embeddedImages);
        self::assertSame(0, $imported->remoteImages);
        self::assertStringContainsString('src="data:image/png;base64,' . self::PNG . '"', $imported->html);
        self::assertStringContainsString('alt="Acme"', $imported->html);
        self::assertStringNotContainsString('93.184.216.34', $imported->html);
        self::assertSame([], $imported->removedAttributes, 'a rewritten src is not a removed one');
    }

    /** Stored once, a data image must come through the next save unchanged and unfetched. */
    public function testAnAlreadyEmbeddedImageIsKeptAsItIs(): void
    {
        $html     = '<img src="data:image/png;base64,' . self::PNG . '" alt="Acme">';
        $imported = $this->importer()->import($html);

        self::assertSame([], $this->requested);
        // Compared decoded: the sanitiser writes the padding as `&#61;`.
        self::assertStringContainsString('data:image/png;base64,' . self::PNG, html_entity_decode($imported->html));
        self::assertSame(0, $imported->embeddedImages);
        self::assertSame(0, $imported->remoteImages);
    }

    public function testAnImageThatCannotBeFetchedStaysRemoteAndIsCounted(): void
    {
        $imported = $this->importer(status: 404)->import('<img src="' . self::HOST . '/gone.png">');

        self::assertSame(0, $imported->embeddedImages);
        self::assertSame(1, $imported->remoteImages);
        self::assertStringContainsString('src="' . self::HOST . '/gone.png"', $imported->html);
    }

    /** The fetcher refuses plain http and private addresses; neither is asked for. */
    public function testAnImageTheFetcherRefusesStaysRemoteWithoutARequest(): void
    {
        $imported = $this->importer()->import(
            '<img src="http://93.184.216.34/logo.png"><img src="https://192.168.1.10/logo.png">',
        );

        self::assertSame([], $this->requested);
        self::assertSame(2, $imported->remoteImages);
    }

    public function testAnImageTooLargeForASignatureStaysRemote(): void
    {
        $imported = $this->importer(body: random_bytes(300 * 1024))->import('<img src="' . self::HOST . '/photo.png">');

        self::assertSame(0, $imported->embeddedImages);
        self::assertSame(1, $imported->remoteImages);
        self::assertStringNotContainsString('data:image', $imported->html);
    }

    /** No mail client shows an attached SVG, so embedding one would only hide the failure. */
    public function testAnSvgStaysRemote(): void
    {
        $imported = $this->importer(body: '<svg xmlns="http://www.w3.org/2000/svg"/>', type: 'image/svg+xml')
            ->import('<img src="' . self::HOST . '/logo.svg">');

        self::assertSame(0, $imported->embeddedImages);
        self::assertSame(1, $imported->remoteImages);
    }

    private function importer(?string $body = null, string $type = 'image/png', int $status = 200): SignatureImporter
    {
        $body ??= (string) base64_decode(self::PNG);

        $client = new MockHttpClient(function (string $method, string $url) use ($body, $type, $status): MockResponse {
            $this->requested[] = $url;

            return new MockResponse($body, [
                'http_code'        => $status,
                'response_headers' => ['content-type' => $type],
            ]);
        });

        $sanitizer = new MailBodySanitizer(self::createStub(UrlGeneratorInterface::class), new NullLogger());

        return new SignatureImporter(
            new SignatureProvider($sanitizer),
            new ImageProxyFetcher(
                $client,
                new NullLogger(),
                // A directory of its own: the fetcher caches by URL, and a
                // cached answer from another case would be this case's result.
                sys_get_temp_dir() . '/plmail-signature-import-test-' . bin2hex(random_bytes(4)),
            ),
        );
    }
}
