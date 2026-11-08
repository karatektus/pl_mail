<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Security\Csp\CspNonce;
use App\Twig\ImportMapOnceExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\AssetMapper\ImportMap\ImportMapGenerator;
use Symfony\Component\AssetMapper\ImportMap\ImportMapRenderer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The import map kept from the first render must be, request after request,
 * exactly what the stock renderer would have produced with that request's
 * nonce — on every script tag, and nowhere the previous request's.
 */
final class ImportMapOnceExtensionTest extends TestCase
{
    public function testEachRenderIsTheStockOneWithThatRequestsNonce(): void
    {
        $generator = $this->createMock(ImportMapGenerator::class);
        $generator->expects(self::once())->method('getImportMapData')->willReturn([
            'app'           => ['path' => '/assets/app-abc123.js', 'type' => 'js', 'preload' => true],
            '@hotwired/turbo' => ['path' => '/assets/vendor/turbo-def456.js', 'type' => 'js', 'preload' => true],
        ]);

        $stockGenerator = $this->createStub(ImportMapGenerator::class);
        $stockGenerator->method('getImportMapData')->willReturn([
            'app'           => ['path' => '/assets/app-abc123.js', 'type' => 'js', 'preload' => true],
            '@hotwired/turbo' => ['path' => '/assets/vendor/turbo-def456.js', 'type' => 'js', 'preload' => true],
        ]);
        $stock = new ImportMapRenderer($stockGenerator);

        $requests = new RequestStack();
        $requests->push(Request::create('/mail/inbox'));
        $nonce     = new CspNonce();
        $extension = new ImportMapOnceExtension(new ImportMapRenderer($generator), $nonce, $requests, false);

        foreach ([1, 2] as $request) {
            $nonce->reset();
            $value = $nonce->value();
            $html  = $extension->render('app');

            self::assertSame($stock->render('app', ['nonce' => $value]), $html, "request {$request}");
            // The map and the entry module here; the app's renderer adds the
            // polyfill loader as a third.
            self::assertSame(2, substr_count($html, 'nonce="' . $value . '"'), "request {$request}");
            self::assertStringNotContainsString('importmap-nonce', $html);
        }
    }

    public function testWithDebugOnEveryRequestIsRenderedAfresh(): void
    {
        $generator = $this->createMock(ImportMapGenerator::class);
        $generator->expects(self::exactly(2))->method('getImportMapData')->willReturn([]);

        $extension = new ImportMapOnceExtension(new ImportMapRenderer($generator), new CspNonce(), new RequestStack(), true);

        $extension->render('app');
        $extension->render('app');
    }
}
