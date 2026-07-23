<?php

declare(strict_types=1);

namespace App\Service\Appearance;

use App\Domain\Enum\Theme\BackgroundKind;
use App\Entity\Embeddable\Appearance;
use Symfony\Component\Asset\Packages;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class BackgroundResolver
{
    public function __construct(
        private Packages              $packages,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /** Returns a CSS <image> value, or null to fall through to the theme default. */
    public function cssValue(Appearance $appearance, ?int $userId = null): ?string
    {
        return match ($appearance->backgroundKind) {
            BackgroundKind::Theme => null,
            BackgroundKind::Solid => sprintf(
                'linear-gradient(%s, %s)',
                $appearance->backgroundSolid ?? '#f1f5f9',
                $appearance->backgroundSolid ?? '#f1f5f9',
            ),
            BackgroundKind::Preset => null === $appearance->backgroundPreset
                ? null
                : $this->url($this->packages->getUrl($appearance->backgroundPreset->file())),
            BackgroundKind::Custom => null === $appearance->backgroundFile || null === $userId
                ? null
                : $this->url($this->urlGenerator->generate('app_appearance_background_show', [
                    'filename' => $appearance->backgroundFile,
                ])),
        };
    }

    private function url(string $url): string
    {
        return sprintf('url("%s")', str_replace(['"', '\\'], '', $url));
    }
}
