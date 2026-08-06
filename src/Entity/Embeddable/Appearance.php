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
    /** Paper's clay, since Paper is what an account starts on — see $theme. */
    public const string DEFAULT_ACCENT = '#7d6b4f';

    /**
     * The clamps the numeric setters below apply, named rather than inlined
     * because the JMAP session publishes them (`SessionBuilder`) so a client
     * can bound its own sliders. Two copies of these numbers is how a phone
     * offers a blur of 80 and reports 80 while the server stores 60.
     *
     * Keyed min/max rather than a tuple: the pairs go over the wire as-is.
     */
    public const array RANGE_PANE_ALPHA = ['min' => 0.15, 'max' => 1.0];
    public const array RANGE_PANE_BLUR = ['min' => 0, 'max' => 60];
    public const array RANGE_RADIUS = ['min' => 0.0, 'max' => 2.0];
    public const array RANGE_SCRIM_ALPHA = ['min' => 0.0, 'max' => 0.7];
    public const array RANGE_MAIN_ALPHA = ['min' => 0.15, 'max' => 1.0];

    /**
     * Paper rather than System. A new install should look like something
     * somebody chose, and "follow the OS" resolves to whichever of plain white
     * or plain dark the machine happens to prefer — the two least considered
     * palettes here. Anyone who wants the OS to decide can still pick System;
     * this only changes what an account starts as.
     */
    #[ORM\Column(type: 'string', length: 16, enumType: Theme::class, options: ['default' => 'paper'])]
    public Theme $theme = Theme::Paper;

    #[ORM\Column(type: 'string', length: 16, enumType: Layout::class, options: ['default' => 'flat'])]
    public Layout $layout = Layout::Flat;

    #[ORM\Column(type: 'string', length: 7, options: ['default' => self::DEFAULT_ACCENT])]
    public string $accent = self::DEFAULT_ACCENT {
        set {
            $this->accent = 1 === preg_match('/^#[0-9a-fA-F]{6}$/', $value)
                ? strtolower($value)
                : self::DEFAULT_ACCENT;
        }
    }

    // The knob defaults below mirror Layout::Flat's preset — the default
    // layout and the default appearance have to agree, or a fresh account
    // renders in a state no layout would produce.
    #[ORM\Column(type: 'float', options: ['default' => 1.0])]
    public float $paneAlpha = 1.0 {
        set {
            $this->paneAlpha = max(self::RANGE_PANE_ALPHA['min'], min(self::RANGE_PANE_ALPHA['max'], $value));
        }
    }

    #[ORM\Column(type: 'smallint', options: ['default' => 0])]
    public int $paneBlur = 0 {
        set {
            $this->paneBlur = max(self::RANGE_PANE_BLUR['min'], min(self::RANGE_PANE_BLUR['max'], $value));
        }
    }

    /** Corner radius in rem. */
    #[ORM\Column(type: 'float', options: ['default' => 0.75])]
    public float $radius = 0.75 {
        set {
            $this->radius = max(self::RANGE_RADIUS['min'], min(self::RANGE_RADIUS['max'], $value));
        }
    }

    #[ORM\Column(type: 'string', length: 16, enumType: Density::class, options: ['default' => 'comfortable'])]
    public Density $density = Density::Comfortable;

    #[ORM\Column(type: 'string', length: 16, enumType: BackgroundKind::class, options: ['default' => 'theme'])]
    public BackgroundKind $backgroundKind = BackgroundKind::Theme;

    #[ORM\Column(type: 'string', length: 32, nullable: true, enumType: BackgroundPreset::class)]
    public ?BackgroundPreset $backgroundPreset = null;

    /** Filename only, relative to the per-user upload directory. */
    #[ORM\Column(type: 'string', length: 128, nullable: true)]
    public ?string $backgroundFile = null;

    #[ORM\Column(type: 'string', length: 7, nullable: true)]
    public ?string $backgroundSolid = null;

    #[ORM\Column(type: 'float', options: ['default' => 0.0])]
    public float $scrimAlpha = 0.0 {
        set {
            $this->scrimAlpha = max(self::RANGE_SCRIM_ALPHA['min'], min(self::RANGE_SCRIM_ALPHA['max'], $value));
        }
    }

    #[ORM\Column(type: 'string', length: 7, nullable: true)]
    public ?string $inkColor = null {
        set {
            $this->inkColor = self::normaliseHex($value);
        }
    }

    #[ORM\Column(type: 'string', length: 7, nullable: true)]
    public ?string $inkMuted = null {
        set {
            $this->inkMuted = self::normaliseHex($value);
        }
    }

    #[ORM\Column(type: 'string', length: 7, nullable: true)]
    public ?string $inkFaint = null {
        set {
            $this->inkFaint = self::normaliseHex($value);
        }
    }

    #[ORM\Column(type: 'string', length: 7, nullable: true)]
    public ?string $mainTint = null {
        set {
            $this->mainTint = self::normaliseHex($value);
        }
    }

    #[ORM\Column(type: 'float', nullable: true)]
    public ?float $mainAlpha = null {
        set {
            $this->mainAlpha = null === $value
                ? null
                : max(self::RANGE_MAIN_ALPHA['min'], min(self::RANGE_MAIN_ALPHA['max'], $value));
        }
    }

    /**
     * Switch layout and seed its knob defaults. The settings pane sends the
     * seeded values itself (so the sliders and the payload stay in step); this
     * is for callers that only have a layout — importing an export written
     * before a knob existed, for instance.
     */
    public function applyLayout(Layout $layout): static
    {
        $this->layout = $layout;

        return $this->applyArray($layout->defaults());
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
            $this->layout = Layout::tryFrom($data['layout']) ?? $this->layout;
        }

        if (true === isset($data['theme'])) {
            $this->theme = Theme::tryFrom($data['theme']) ?? $this->theme;
        }

        if (true === isset($data['accent'])) {
            $this->accent = $data['accent'];
        }

        if (true === isset($data['paneAlpha'])) {
            $this->paneAlpha = floatval($data['paneAlpha']);
        }

        if (true === isset($data['paneBlur'])) {
            $this->paneBlur = intval($data['paneBlur']);
        }

        if (true === isset($data['radius'])) {
            $this->radius = floatval($data['radius']);
        }

        if (true === isset($data['density'])) {
            $this->density = Density::tryFrom($data['density']) ?? $this->density;
        }

        if (true === isset($data['backgroundKind'])) {
            $this->backgroundKind = BackgroundKind::tryFrom($data['backgroundKind']) ?? $this->backgroundKind;
        }

        if (true === array_key_exists('backgroundPreset', $data)) {
            // Both "no preset" and "a preset we don't know" mean null, but
            // tryFrom() only accepts a string — reset and import both send the
            // key with a null value.
            $preset = $data['backgroundPreset'];
            $this->backgroundPreset = is_string($preset) ? BackgroundPreset::tryFrom($preset) : null;
        }

        if (true === array_key_exists('backgroundSolid', $data)) {
            $solid = $data['backgroundSolid'];
            $this->backgroundSolid = is_string($solid) && 1 === preg_match('/^#[0-9a-fA-F]{6}$/', $solid) ? strtolower($solid) : null;
        }

        if (true === isset($data['scrimAlpha'])) {
            $this->scrimAlpha = floatval($data['scrimAlpha']);
        }

        if (true === array_key_exists('inkColor', $data)) {
            $this->inkColor = is_string($data['inkColor']) ? $data['inkColor'] : null;
        }

        if (true === array_key_exists('inkMuted', $data)) {
            $this->inkMuted = is_string($data['inkMuted']) ? $data['inkMuted'] : null;
        }

        if (true === array_key_exists('inkFaint', $data)) {
            $this->inkFaint = is_string($data['inkFaint']) ? $data['inkFaint'] : null;
        }

        if (true === array_key_exists('mainTint', $data)) {
            $this->mainTint = is_string($data['mainTint']) ? $data['mainTint'] : null;
        }

        if (true === array_key_exists('mainAlpha', $data)) {
            $raw = $data['mainAlpha'];
            $this->mainAlpha = '' === $raw || null === $raw ? null : floatval($raw);
        }
        return $this;
    }
}
