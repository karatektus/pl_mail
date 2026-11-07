<?php

declare(strict_types=1);

namespace App\Domain\Enum\Mail;

/**
 * The size a draft's images go out at, when the person chose a smaller one
 * than they were taken at (#502). Stored on the draft (Message::$imageSize);
 * null there means Original, which is the default: nobody's photos get
 * smaller unless they ask.
 *
 * Measured on the longest side, so a portrait and a landscape photo of the
 * same camera come out alike. The browser does the scaling, not the server:
 * a sealed draft's files reach the server already encrypted, and without the
 * exif extension PHP could not read which way up a phone photo is.
 *
 * Three presets, and Custom for the Adjust panel, where size and quality are
 * set apart: Message::$imageEdge and $imageQuality hold its two numbers.
 */
enum ImageSize: string
{
    /** Still sharp on a large screen, a fifth of a phone photo's bytes. */
    case Large = 'large';

    /** Fills a laptop screen. */
    case Medium = 'medium';

    /** Enough to show what is in the picture. */
    case Small = 'small';

    /** The person's own longest side and quality, from the Adjust panel. */
    case Custom = 'custom';

    /** The quality every preset encodes JPEG and WebP at. */
    public const int PRESET_QUALITY = 85;

    /** What the Adjust panel's sliders allow; Message holds the values. */
    public const int MIN_EDGE = 640;
    public const int MAX_EDGE = 4096;
    public const int MIN_QUALITY = 40;
    public const int MAX_QUALITY = 100;

    /** @return list<self> the three a button in the row stands for */
    public static function presets(): array
    {
        return [self::Large, self::Medium, self::Small];
    }

    /**
     * The longest side of a preset, in pixels. Custom has none of its own —
     * it is Message::$imageEdge — and answers 0.
     */
    public function edge(): int
    {
        return match ($this) {
            self::Large  => 2048,
            self::Medium => 1280,
            self::Small  => 800,
            self::Custom => 0,
        };
    }
}
