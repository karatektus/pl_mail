<?php

declare(strict_types=1);

namespace App\Service\User\TwoFactor;

/**
 * Turns a user agent into something a person can recognise in a list —
 * "Firefox on macOS".
 *
 * Deliberately crude, and not a device-detection library. The string exists so
 * somebody scanning their trusted devices can tell the work laptop from the
 * phone; it is never used to decide anything, so a browser it does not know
 * costs a vaguer label and nothing else. The full user agent is stored beside
 * it for the cases where the label is not enough.
 */
final readonly class DeviceLabeller
{
    /** Longest match first: Edge claims Chrome, and Chrome claims Safari. */
    private const array BROWSERS = [
        'Edg/'     => 'Edge',
        'OPR/'     => 'Opera',
        'Chrome/'  => 'Chrome',
        'Firefox/' => 'Firefox',
        'Safari/'  => 'Safari',
    ];

    private const array PLATFORMS = [
        'iPhone'      => 'iPhone',
        'iPad'        => 'iPad',
        'Android'     => 'Android',
        'Macintosh'   => 'macOS',
        'Mac OS X'    => 'macOS',
        'Windows'     => 'Windows',
        'CrOS'        => 'ChromeOS',
        'Linux'       => 'Linux',
    ];

    public function label(?string $userAgent): string
    {
        if (null === $userAgent || '' === trim($userAgent)) {
            return 'Unknown device';
        }

        $browser = $this->firstMatch($userAgent, self::BROWSERS);
        $platform = $this->firstMatch($userAgent, self::PLATFORMS);

        return match (true) {
            null !== $browser && null !== $platform => $browser.' on '.$platform,
            null !== $browser                       => $browser,
            null !== $platform                      => $platform,
            default                                 => 'Unknown device',
        };
    }

    /**
     * @param array<string, string> $needles
     */
    private function firstMatch(string $haystack, array $needles): ?string
    {
        foreach ($needles as $needle => $name) {
            if (true === str_contains($haystack, $needle)) {
                return $name;
            }
        }

        return null;
    }
}
