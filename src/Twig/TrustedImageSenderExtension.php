<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * The image-sender allowlist for the settings card (#807). The reading half is
 * TrustedImageSenderRuntime, for the reason SenderPictureExtension gives.
 */
final class TrustedImageSenderExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('trusted_image_senders', [TrustedImageSenderRuntime::class, 'entries']),
        ];
    }
}
