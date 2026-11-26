<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\Mail\SenderPicture\SenderPictures;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * What templates need to draw sender pictures (#639) and their settings.
 *
 * The functions that read anything live in SenderPictureRuntime, for the
 * reason AccountServicesExtension gives: an extension is built on every
 * request that renders anything, a runtime only when one of its functions is
 * first called.
 */
final class SenderPictureExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('sender_picture', [SenderPictureRuntime::class, 'picture']),
            new TwigFunction('sender_picture_keys', static fn (?string $address): array => SenderPictures::markupKeys($address)),
            new TwigFunction('sender_picture_choices', [SenderPictureRuntime::class, 'choices']),
            new TwigFunction('sender_picture_lookups_enabled', [SenderPictureRuntime::class, 'lookupsEnabled']),
            new TwigFunction('libravatar_serving', [SenderPictureRuntime::class, 'libravatarServing']),
        ];
    }
}
