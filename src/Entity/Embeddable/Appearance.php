<?php

declare(strict_types=1);

namespace App\Entity\Embeddable;

use App\Domain\Enum\Theme\BackgroundKind;
use App\Domain\Enum\Theme\BackgroundPreset;
use App\Domain\Enum\Theme\Density;
use App\Domain\Enum\Theme\FontFamily;
use App\Domain\Enum\Theme\Layout;
use App\Domain\Enum\Theme\Theme;
use App\Domain\Enum\Theme\UnreadEmphasis;
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
     * How many lines of the message preview a list row shows: none, one, two.
     *
     * Two is the ceiling because the row only has room for two. Wide, the
     * subject and the preview share a single line by design, so a second line
     * there would push the subject off its own row; the clamp is applied in the
     * stacked layout only and app.css says so. Three was tried and is a wall of
     * grey text at any density below Comfortable.
     */
    public const array RANGE_PREVIEW_LINES = ['min' => 0, 'max' => 2];

    /**
     * The interface text scale, as a multiplier on the 16px root.
     *
     * The ends are where the app was actually opened and looked at, not where
     * a round number fell. Both were driven at 1440×900 and 414×851, in a light
     * theme and a dark one, across the settings pane, the thread list and the
     * compose window — see tests/e2e/appearance-shots.spec.ts, which takes
     * those twelve screenshots and asserts the one thing that must not happen
     * at either end: the page never scrolls sideways. Nothing clips at 0.875
     * and nothing overflows at 1.25; the settings nav wraps to more lines at
     * the top end, which is the layout doing its job rather than breaking.
     *
     * Wider was not offered because it was not checked. A scale is a promise
     * about every screen in the app at once, and the honest range is the one
     * somebody has looked at.
     */
    public const array RANGE_FONT_SCALE = ['min' => 0.875, 'max' => 1.25];

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

    /* ── Mail list display ────────────────────────────────────────────────
       What a row in the message list shows. Every default below is what the
       list already did before these settings existed, so an install that
       upgrades looks identical until somebody changes something. */

    /**
     * The account corner: a coloured triangle clipped to a row's lower-left,
     * saying which account the row arrived on.
     *
     * Optional because it is identity vocabulary, and not everybody wants their
     * list carrying it — but ON by default, because a unified list across three
     * accounts is genuinely ambiguous without it (the same Message-ID delivered
     * to three addresses puts three identical rows on screen) and the person
     * who does not want it is one click from not having it. It is drawn only in
     * a unified list on a multi-account install anyway; on one account it has
     * never appeared at all, and this setting does not change that.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    public bool $accountCorner = true;

    /**
     * The sender disc — the coloured circle carrying an initial.
     *
     * "Off" does not remove the control, and cannot: the disc IS the row's
     * checkbox (the real input is sr-only behind it, and every bulk action in
     * the toolbar reads it). So off means the disc loses its colour and its
     * letter and becomes a plain outlined circle in the same slot — the
     * identity goes, the selection target and the row's geometry stay. See
     * app.css, which paints that over it rather than removing anything.
     */
    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    public bool $listAvatars = true;

    #[ORM\Column(type: 'smallint', options: ['default' => 1])]
    public int $previewLines = 1 {
        set {
            $this->previewLines = max(self::RANGE_PREVIEW_LINES['min'], min(self::RANGE_PREVIEW_LINES['max'], $value));
        }
    }

    #[ORM\Column(type: 'string', length: 16, enumType: UnreadEmphasis::class, options: ['default' => 'standard'])]
    public UnreadEmphasis $unreadEmphasis = UnreadEmphasis::Standard;

    /* ── Typography ───────────────────────────────────────────────────────── */

    #[ORM\Column(type: 'string', length: 16, enumType: FontFamily::class, options: ['default' => 'system'])]
    public FontFamily $fontFamily = FontFamily::System;

    #[ORM\Column(type: 'float', options: ['default' => 1.0])]
    public float $fontScale = 1.0 {
        set {
            $this->fontScale = max(self::RANGE_FONT_SCALE['min'], min(self::RANGE_FONT_SCALE['max'], $value));
        }
    }

    /* ── Per-surface density ──────────────────────────────────────────────
       Three nullable overrides on ONE existing knob, rather than three copies
       of the whole appearance.

       Null means "follow $density", and that is what makes this additive: the
       columns arrive null on every existing row, so nothing renders
       differently until somebody sets one. It is also what keeps the token
       surface finite — AppearanceRenderer resolves each surface to a concrete
       pair of values and emits six variables, instead of every knob gaining
       three scoped twins.

       Density and no other knob, for a reason that is structural rather than a
       shortage of time: the list and the reading pane are ONE painted surface.
       `.main-pane` in _layout/_mailbox.html.twig wraps both and carries the
       background, the blur and the border, so opacity, blur, radius and the
       scrim cannot differ between them without splitting that pane in two.
       Density can, because it is padding inside rows rather than a property of
       the surface they sit on. */

    #[ORM\Column(type: 'string', length: 16, nullable: true, enumType: Density::class)]
    public ?Density $sidebarDensity = null;

    #[ORM\Column(type: 'string', length: 16, nullable: true, enumType: Density::class)]
    public ?Density $listDensity = null;

    #[ORM\Column(type: 'string', length: 16, nullable: true, enumType: Density::class)]
    public ?Density $readingDensity = null;

    /** The density a surface actually renders at: its own, or the global one. */
    public function densityFor(string $surface): Density
    {
        return match ($surface) {
            'sidebar' => $this->sidebarDensity ?? $this->density,
            'list' => $this->listDensity ?? $this->density,
            'reading' => $this->readingDensity ?? $this->density,
            default => $this->density,
        };
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
            'accountCorner' => $this->accountCorner,
            'listAvatars' => $this->listAvatars,
            'previewLines' => $this->previewLines,
            'unreadEmphasis' => $this->unreadEmphasis->value,
            'fontFamily' => $this->fontFamily->value,
            'fontScale' => $this->fontScale,
            'sidebarDensity' => $this->sidebarDensity?->value,
            'listDensity' => $this->listDensity?->value,
            'readingDensity' => $this->readingDensity?->value,
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

        if (true === isset($data['accountCorner'])) {
            $this->accountCorner = self::boolean($data['accountCorner']);
        }

        if (true === isset($data['listAvatars'])) {
            $this->listAvatars = self::boolean($data['listAvatars']);
        }

        if (true === isset($data['previewLines'])) {
            $this->previewLines = intval($data['previewLines']);
        }

        if (true === isset($data['unreadEmphasis'])) {
            $this->unreadEmphasis = UnreadEmphasis::tryFrom((string) $data['unreadEmphasis']) ?? $this->unreadEmphasis;
        }

        if (true === isset($data['fontFamily'])) {
            $this->fontFamily = FontFamily::tryFrom((string) $data['fontFamily']) ?? $this->fontFamily;
        }

        if (true === isset($data['fontScale'])) {
            $this->fontScale = floatval($data['fontScale']);
        }

        // array_key_exists, not isset: null is the meaningful value here — it
        // is how a surface goes back to following the global density — and
        // isset() cannot tell "follow the global" from "not mentioned".
        foreach (['sidebarDensity', 'listDensity', 'readingDensity'] as $surface) {
            if (false === array_key_exists($surface, $data)) {
                continue;
            }

            $value = $data[$surface];
            $this->{$surface} = is_string($value) && '' !== $value ? Density::tryFrom($value) : null;
        }

        return $this;
    }

    /**
     * A checkbox over the wire.
     *
     * The settings pane posts every field as the string in its DOM node, so a
     * boolean arrives as "1"/"0"; JMAP and a theme-export file send a real
     * bool. "0" is truthy to PHP's cast, so both spellings are named here
     * rather than trusted to (bool).
     */
    private static function boolean(mixed $value): bool
    {
        if (true === is_bool($value)) {
            return $value;
        }

        return false === in_array($value, ['0', 0, 'false', '', null], true);
    }
}
