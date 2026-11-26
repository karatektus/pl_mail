<?php

declare(strict_types=1);

namespace App\Tests\Service\Mail\SenderPicture\Lookup;

use App\Domain\Helper\SvgSanitizer;
use App\Service\Mail\SenderPicture\Lookup\WebsitePictureLookup;
use App\Service\Mail\SenderPicture\SenderPictureHttp;
use App\Service\Mail\SenderPicture\SenderPictureImage;
use App\Tests\Support\Mail\SenderPictureFixtures;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * A website's own icon, the best one it declares (#639).
 *
 * Pinned: the order icons are tried in, that a favicon too small to be
 * anything but a blur is passed over for the next candidate, and that the
 * website behind a personal mail provider is never asked at all.
 */
final class WebsitePictureLookupTest extends TestCase
{
    private const string PAGE = <<<'HTML'
        <html><head>
          <link rel="icon" href="/favicon.ico">
          <link rel="icon" type="image/png" sizes="16x16 32x32" href="/small.png">
          <link rel="apple-touch-icon" href="/touch.png">
          <link rel="icon" sizes="192x192" href="https://cdn.shop.example/big.png">
        </head><body><link rel="icon" href="/in-body.png"></body></html>
        HTML;

    public function testDeclaredIconsAreRankedTouchIconFirstThenBySize(): void
    {
        self::assertSame(
            ['https://shop.example/touch.png', 'https://cdn.shop.example/big.png', 'https://shop.example/small.png'],
            WebsitePictureLookup::declaredIcons(self::PAGE, 'https://shop.example/'),
        );
    }

    public function testATooSmallIconIsPassedOverForTheNextOne(): void
    {
        $requests = [];
        $icons    = [
            'https://shop.example/touch.png'   => SenderPictureFixtures::png(16, 16),
            'https://cdn.shop.example/big.png' => SenderPictureFixtures::png(192, 192),
        ];

        $picture = $this->lookup($icons, $requests)->lookup('shop.example');

        self::assertSame('https://cdn.shop.example/big.png', $picture?->origin);
    }

    public function testAPersonalMailProviderIsNeverAsked(): void
    {
        $requests = [];

        self::assertNull($this->lookup([], $requests)->lookup('gmail.com'));
        self::assertSame([], $requests);
    }

    /**
     * @param array<string, string> $icons    URL => bytes
     * @param list<string>          $requests filled with every URL asked for
     */
    private function lookup(array $icons, array &$requests): WebsitePictureLookup
    {
        $client = new MockHttpClient(static function (string $method, string $url) use (&$requests, $icons): MockResponse {
            $requests[] = $url;

            if ('https://shop.example/' === $url) {
                return new MockResponse(self::PAGE, ['response_headers' => ['content-type' => 'text/html; charset=utf-8']]);
            }

            if (true === isset($icons[$url])) {
                return new MockResponse($icons[$url], ['response_headers' => ['content-type' => 'image/png']]);
            }

            return new MockResponse('', ['http_code' => 404]);
        });

        return new WebsitePictureLookup(
            new SenderPictureHttp($client, new NullLogger()),
            new SenderPictureImage(new SvgSanitizer()),
        );
    }
}
