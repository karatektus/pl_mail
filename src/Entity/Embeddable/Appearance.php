<?php

declare(strict_types=1);

namespace App\Entity\Embeddable;

use App\Domain\Enum\Theme\BackgroundKind;
use App\Domain\Enum\Theme\BackgroundPreset;
use App\Domain\Enum\Theme\Density;
use App\Domain\Enum\Theme\Layout;
use App\Domain\Enum\Theme\Theme;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Embeddable]
final class Appearance
{
    public const string DEFAULT_ACCENT = '#2563eb';

    #[ORM\Column(type: 'string', length: 16, enumType: Theme::class, options: ['default' => 'system'])]
    public private(set) Theme $theme = Theme::System;

    #[ORM\Column(type: 'string', length: 16, enumType: Layout::class, options: ['default' => 'boxed'])]
    public private(set) Layout $layout = Layout::Boxed;

    #[ORM\Column(type: 'string', length: 7, options: ['default' => self::DEFAULT_ACCENT])]
    public private(set) string $accent = self::DEFAULT_ACCENT {
        set {
            $this->accent = 1 === preg_match('/^#[0-9a-fA-F]{6}$/', $value)
                ? strtolower($value)
                : self::DEFAULT_ACCENT;
        }
    }

    #[ORM\Column(type: 'float', options: ['default' => 0.7])]
    public private(set) float $paneAlpha = 0.7 {
        set {
            $this->paneAlpha = max(0.15, min(1.0, $value));
        }
    }

    #[ORM\Column(type: 'smallint', options: ['default' => 24])]
    public private(set) int $paneBlur = 24 {
        set {
            $this->paneBlur = max(0, min(60, $value));
        }
    }

    /** Corner radius in rem. */
    #[ORM\Column(type: 'float', options: ['default' => 1.0])]
    public private(set) float $radius = 1.0 {
        set {
            $this->radius = max(0.0, min(2.0, $value));
        }
    }

    #[ORM\Column(type: 'string', length: 16, enumType: Density::class, options: ['default' => 'comfortable'])]
    public private(set) Density $density = Density::Comfortable;

    #[ORM\Column(type: 'string', length: 16, enumType: BackgroundKind::class, options: ['default' => 'theme'])]
    public private(set) BackgroundKind $backgroundKind = BackgroundKind::Theme;

    #[ORM\Column(type: 'string', length: 32, nullable: true, enumType: BackgroundPreset::class)]
    public private(set) ?BackgroundPreset $backgroundPreset = null;

    /** Filename only, relative to the per-user upload directory. */
    #[ORM\Column(type: 'string', length: 128, nullable: true)]
    public private(set) ?string $backgroundFile = null;

    #[ORM\Column(type: 'string', length: 7, nullable: true)]
    public private(set) ?string $backgroundSolid = null;

    #[ORM\Column(type: 'float', options: ['default' => 0.0])]
    public private(set) float $scrimAlpha = 0.0 {
        set {
            $this->scrimAlpha = max(0.0, min(0.7, $value));
        }
    }

    #[ORM\Column(type: 'string', length: 7, nullable: true)]
    public private(set) ?string $inkColor = null {
        set {
            $this->inkColor = self::normaliseHex($value);
        }
    }

    #[ORM\Column(type: 'string', length: 7, nullable: true)]
    public private(set) ?string $inkMuted = null {
        set {
            $this->inkMuted = self::normaliseHex($value);
        }
    }

    #[ORM\Column(type: 'string', length: 7, nullable: true)]
    public private(set) ?string $inkFaint = null {
        set {
            $this->inkFaint = self::normaliseHex($value);
        }
    }

    #[ORM\Column(type: 'string', length: 7, nullable: true)]
    public private(set) ?string $mainTint = null {
        set {
            $this->mainTint = self::normaliseHex($value);
        }
    }

    #[ORM\Column(type: 'float', nullable: true)]
    public private(set) ?float $mainAlpha = null {
        set {
            $this->mainAlpha = null === $value ? null : max(0.15, min(1.0, $value));
        }
    }


    public function setTheme(Theme $theme): static
    {
        $this->theme = $theme;

        return $this;
    }

    public function setLayout(Layout $layout): static
    {
        $this->layout = $layout;

        return $this;
    }

    /**
     * Switch layout and seed its knob defaults. The settings pane sends the
     * seeded values itself (so the sliders and the payload stay in step); this
     * is for callers that only have a layout — importing an export written
     * before a knob existed, for instance.
     */
    public function applyLayout(Layout $layout): static
    {
        return $this->setLayout($layout)->applyArray($layout->defaults());
    }

    public function setAccent(string $accent): static
    {
        $this->accent = $accent;

        return $this;
    }

    public function setPaneAlpha(float $paneAlpha): static
    {
        $this->paneAlpha = $paneAlpha;

        return $this;
    }

    public function setPaneBlur(int $paneBlur): static
    {
        $this->paneBlur = $paneBlur;

        return $this;
    }

    public function setRadius(float $radius): static
    {
        $this->radius = $radius;

        return $this;
    }

    public function setDensity(Density $density): static
    {
        $this->density = $density;

        return $this;
    }

    public function setBackgroundKind(BackgroundKind $backgroundKind): static
    {
        $this->backgroundKind = $backgroundKind;

        return $this;
    }

    public function setBackgroundPreset(?BackgroundPreset $backgroundPreset): static
    {
        $this->backgroundPreset = $backgroundPreset;

        return $this;
    }

    public function setBackgroundFile(?string $backgroundFile): static
    {
        $this->backgroundFile = $backgroundFile;

        return $this;
    }

    public function setBackgroundSolid(?string $backgroundSolid): static
    {
        $this->backgroundSolid = $backgroundSolid;

        return $this;
    }

    public function setScrimAlpha(float $scrimAlpha): static
    {
        $this->scrimAlpha = $scrimAlpha;

        return $this;
    }

    public function setInkColor(?string $inkColor): static
    {
        $this->inkColor = $inkColor;

        return $this;
    }

    public function setInkMuted(?string $inkMuted): static
    {
        $this->inkMuted = $inkMuted;

        return $this;
    }

    public function setInkFaint(?string $inkFaint): static
    {
        $this->inkFaint = $inkFaint;

        return $this;
    }

    public function setMainTint(?string $mainTint): static
    {
        $this->mainTint = $mainTint;

        return $this;
    }

    public function setMainAlpha(?float $mainAlpha): static
    {
        $this->mainAlpha = $mainAlpha;

        return $this;
    }

    private static function normaliseHex(?string $value): ?string
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return 1 === preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtolower($value) : null;
    }

    /** Export payload — background file is deliberately excluded, it is not portable. */
    public function toArray(): array
    {
        return [
            'version' => 1,
            'theme' => $this->theme->value,
            'layout' => $this->layout->value,
            'accent' => $this->accent,
            'paneAlpha' => $this->paneAlpha,
            'paneBlur' => $this->paneBlur,
            'radius' => $this->radius,
            'density' => $this->density->value,
            'backgroundKind' => BackgroundKind::Custom === $this->backgroundKind
                ? BackgroundKind::Theme->value
                : $this->backgroundKind->value,
            'backgroundPreset' => $this->backgroundPreset?->value,
            'backgroundSolid' => $this->backgroundSolid,
            'scrimAlpha' => $this->scrimAlpha,
            'inkColor' => $this->inkColor,
            'inkMuted' => $this->inkMuted,
            'inkFaint' => $this->inkFaint,
            'mainTint' => $this->mainTint,
            'mainAlpha' => $this->mainAlpha,
        ];
    }

    public function applyArray(array $data): static
    {
        // First: a layout seeds the knobs, so any explicit knob value in the
        // same payload has to be applied after it to win.
        if (true === isset($data['layout'])) {
            $this->setLayout(Layout::tryFrom($data['layout']) ?? $this->layout);
        }

        if (true === isset($data['theme'])) {
            $this->setTheme(Theme::tryFrom($data['theme']) ?? $this->theme);
        }

        if (true === isset($data['accent'])) {
            $this->setAccent($data['accent']);
        }

        if (true === isset($data['paneAlpha'])) {
            $this->setPaneAlpha(floatval($data['paneAlpha']));
        }

        if (true === isset($data['paneBlur'])) {
            $this->setPaneBlur(intval($data['paneBlur']));
        }

        if (true === isset($data['radius'])) {
            $this->setRadius(floatval($data['radius']));
        }

        if (true === isset($data['density'])) {
            $this->setDensity(Density::tryFrom($data['density']) ?? $this->density);
        }

        if (true === isset($data['backgroundKind'])) {
            $this->setBackgroundKind(BackgroundKind::tryFrom($data['backgroundKind']) ?? $this->backgroundKind);
        }

        if (true === array_key_exists('backgroundPreset', $data)) {
            $this->setBackgroundPreset(BackgroundPreset::tryFrom($data['backgroundPreset']));
        }

        if (true === array_key_exists('backgroundSolid', $data)) {
            $solid = $data['backgroundSolid'];
            $this->setBackgroundSolid(1 === preg_match('/^#[0-9a-fA-F]{6}$/', $solid) ? strtolower($solid) : null);
        }

        if (true === isset($data['scrimAlpha'])) {
            $this->setScrimAlpha(floatval($data['scrimAlpha']));
        }

        if (true === array_key_exists('inkColor', $data)) {
            $this->setInkColor(is_string($data['inkColor']) ? $data['inkColor'] : null);
        }

        if (true === array_key_exists('inkMuted', $data)) {
            $this->setInkMuted(is_string($data['inkMuted']) ? $data['inkMuted'] : null);
        }

        if (true === array_key_exists('inkFaint', $data)) {
            $this->setInkFaint(is_string($data['inkFaint']) ? $data['inkFaint'] : null);
        }

        if (true === array_key_exists('mainTint', $data)) {
            $this->setMainTint(is_string($data['mainTint']) ? $data['mainTint'] : null);
        }

        if (true === array_key_exists('mainAlpha', $data)) {
            $raw = $data['mainAlpha'];
            $this->setMainAlpha('' === $raw || null === $raw ? null : floatval($raw));
        }
        return $this;
    }
}
