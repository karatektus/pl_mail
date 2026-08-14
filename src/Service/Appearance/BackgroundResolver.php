<?php

declare(strict_types=1);

namespace App\Service\Appearance;

use App\Domain\Enum\Theme\BackgroundKind;
use App\Domain\Enum\Theme\BackgroundPreset;
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

    /** What a solid background paints when no colour has been chosen. */
    public const string DEFAULT_SOLID = '#f1f5f9';

    /** Returns a CSS <image> value, or null to fall through to the theme default. */
    public function cssValue(Appearance $appearance, ?int $userId = null): ?string
    {
        return match ($appearance->backgroundKind) {
            BackgroundKind::Theme => null,
            BackgroundKind::Solid => $this->solid($appearance->backgroundSolid),
            BackgroundKind::Preset => null === $appearance->backgroundPreset
                ? null
                : $this->preset($appearance->backgroundPreset),
            BackgroundKind::Custom => null === $appearance->backgroundFile || null === $userId
                ? null
                : $this->url($this->urlGenerator->generate('app_appearance_background_show', [
                    'filename' => $appearance->backgroundFile,
                ])),
        };
    }

    /**
     * One preset's `--app-bg`, addressable without an Appearance to hang it on.
     *
     * Public because the settings panel writes this exact string onto each
     * preset tile, and the controller applies what it finds there verbatim. A
     * live preview that composed its own URL would be a second renderer of the
     * same value in a second language, which is how "it looks right until you
     * reload" happens. See settings--appearance's docblock.
     */
    public function preset(BackgroundPreset $preset): string
    {
        return $this->url($this->packages->getUrl($preset->file()));
    }

    /**
     * A flat colour, as a gradient.
     *
     * `.app-bg` paints through `background-image`, which has no way to take a
     * colour — so the colour is a gradient from itself to itself. The themes in
     * app.css spell their flat backgrounds the same way.
     */
    public function solid(?string $hex): string
    {
        $hex ??= self::DEFAULT_SOLID;

        return sprintf('linear-gradient(%s, %s)', $hex, $hex);
    }

    private function url(string $url): string
    {
        return sprintf('url("%s")', str_replace(['"', '\\'], '', $url));
    }
}
