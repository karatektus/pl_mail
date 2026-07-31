<?php

declare(strict_types=1);

namespace App\Tests\Service\User\TwoFactor;

use App\Service\User\TwoFactor\DeviceLabeller;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The label is cosmetic — nothing is decided by it — so these cover the
 * ordering traps rather than aiming for real device detection: every
 * Chromium browser claims to be Chrome, and Chrome claims to be Safari.
 */
final class DeviceLabellerTest extends TestCase
{
    #[DataProvider('userAgents')]
    public function testLabels(string $userAgent, string $expected): void
    {
        self::assertSame($expected, (new DeviceLabeller())->label($userAgent));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function userAgents(): iterable
    {
        yield 'chrome on windows' => [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36',
            'Chrome on Windows',
        ];

        // Edge sends "Chrome/… Safari/… Edg/…" — matching in listed order is
        // what keeps this from coming out as "Chrome on Windows".
        yield 'edge is not chrome' => [
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0',
            'Edge on Windows',
        ];

        // Safari has no token of its own beyond the one Chrome also sends.
        yield 'safari on macos' => [
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15',
            'Safari on macOS',
        ];

        yield 'firefox on macos' => [
            'Mozilla/5.0 (Macintosh; Intel Mac OS X 10.15; rv:135.0) Gecko/20100101 Firefox/135.0',
            'Firefox on macOS',
        ];

        yield 'safari on iphone' => [
            'Mozilla/5.0 (iPhone; CPU iPhone OS 18_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1',
            'Safari on iPhone',
        ];

        yield 'chrome on android' => [
            'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36',
            'Chrome on Android',
        ];

        yield 'unknown agent' => ['SomeScript/1.0', 'Unknown device'];
        yield 'empty' => ['', 'Unknown device'];
    }

    public function testNullUserAgent(): void
    {
        self::assertSame('Unknown device', (new DeviceLabeller())->label(null));
    }
}
