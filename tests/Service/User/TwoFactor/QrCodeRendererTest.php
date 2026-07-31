<?php

declare(strict_types=1);

namespace App\Tests\Service\User\TwoFactor;

use App\Service\User\TwoFactor\QrCodeRenderer;
use PHPUnit\Framework\TestCase;

/**
 * The QR renderer, pinned directly rather than only through the page that uses
 * it.
 *
 * endroid/qr-code changed its builder API between major versions with no
 * deprecation shim, and the failure mode is a 500 on the one page nobody opens
 * until they are already enrolling.
 */
final class QrCodeRendererTest extends TestCase
{
    private const string OTPAUTH = 'otpauth://totp/you%40example.com?secret=JBSWY3DPEHPK3PXP&issuer=plMail';

    public function testRendersAnInlineSvgDataUri(): void
    {
        $uri = (new QrCodeRenderer())->dataUri(self::OTPAUTH);

        self::assertStringStartsWith('data:image/svg+xml', $uri);
    }

    /**
     * A data URI, never a URL: the image *is* the TOTP secret, and an endpoint
     * would put it in access logs and the browser cache.
     */
    public function testTheResultIsSelfContained(): void
    {
        $uri = (new QrCodeRenderer())->dataUri(self::OTPAUTH);

        self::assertStringNotContainsString('http://', $uri);
        self::assertStringNotContainsString('https://', $uri);
    }

    public function testTheSecretIsNotReadableInTheMarkup(): void
    {
        $uri = (new QrCodeRenderer())->dataUri(self::OTPAUTH);

        // Encoded into the modules, not written out beside them — a label would
        // put the secret in the DOM as text.
        self::assertStringNotContainsString('JBSWY3DPEHPK3PXP', base64_decode(
            substr($uri, (int) strpos($uri, ',') + 1),
        ) ?: $uri);
    }
}
