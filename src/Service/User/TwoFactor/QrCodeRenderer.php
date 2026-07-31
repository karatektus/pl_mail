<?php

declare(strict_types=1);

namespace App\Service\User\TwoFactor;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\SvgWriter;

/**
 * Renders an otpauth:// URI as an inline SVG data URI.
 *
 * SVG rather than PNG so nothing depends on GD being compiled in, and so the
 * code stays sharp on a phone held up to a high-DPI screen — which is the only
 * way anybody actually uses this.
 *
 * A data URI rather than an endpoint on purpose: the URI *is* the TOTP secret
 * in scannable form, and an image URL would put it in access logs, in the
 * browser cache, and one referrer header away from leaving the machine.
 */
final readonly class QrCodeRenderer
{
    public function dataUri(string $content): string
    {
        // endroid/qr-code 6 dropped the fluent Builder::create() chain for
        // named arguments; there is no deprecation shim, so this is the only
        // shape that works on it.
        $result = new Builder(
            writer: new SvgWriter(),
            data: $content,
            encoding: new Encoding('UTF-8'),
            // No logo sits over this code and it is scanned once, so Medium is
            // enough — and it keeps the code coarse enough to read off a laptop
            // screen with a phone camera, which High does not.
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: 220,
            margin: 8,
        )->build();

        return $result->getDataUri();
    }
}
