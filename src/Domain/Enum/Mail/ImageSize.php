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
 * exif extension PHP could not read which way up a phone photo is. The three
 * steps are the ones people know from other mail programs; a free number
 * would be one more thing to decide for no visible gain.
 */
enum ImageSize: string
{
    /** Still sharp on a large screen, a fifth of a phone photo's bytes. */
    case Large = 'large';

    /** Fills a laptop screen. */
    case Medium = 'medium';

    /** Enough to show what is in the picture. */
    case Small = 'small';

    /** The longest side, in pixels. compose_controller reads it from the row's buttons. */
    public function edge(): int
    {
        return match ($this) {
            self::Large  => 2048,
            self::Medium => 1280,
            self::Small  => 800,
        };
    }
}
