<?php

declare(strict_types=1);

namespace App\Tests\Entity\User;

use App\Entity\User\TrustedDevice;
use App\Entity\User\User;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * The trusted-device grant.
 *
 * The point of holding these in a table rather than in a signed cookie is that
 * they can be withdrawn, so the tests that matter are the ones asserting a
 * grant stops counting as live.
 */
final class TrustedDeviceTest extends TestCase
{
    private static function future(): DateTimeImmutable
    {
        return new DateTimeImmutable('+30 days');
    }

    public function testMintingReturnsASecretThatIsNotStored(): void
    {
        ['device' => $device, 'secret' => $secret] = TrustedDevice::create(
            new User(),
            'main',
            'Firefox on macOS',
            self::future(),
        );

        self::assertNotSame($secret, $device->tokenHash);
        self::assertSame(TrustedDevice::hash($secret), $device->tokenHash);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $device->tokenHash);
    }

    public function testEachGrantGetsItsOwnSecret(): void
    {
        $first = TrustedDevice::create(new User(), 'main', 'A', self::future());
        $second = TrustedDevice::create(new User(), 'main', 'B', self::future());

        self::assertNotSame($first['secret'], $second['secret']);
    }

    public function testAFreshGrantIsActive(): void
    {
        ['device' => $device] = TrustedDevice::create(new User(), 'main', 'Firefox', self::future());

        self::assertTrue($device->isActive());
    }

    public function testRevokingStopsTheGrantCounting(): void
    {
        ['device' => $device] = TrustedDevice::create(new User(), 'main', 'Firefox', self::future());

        $device->revoke();

        self::assertFalse($device->isActive());
        self::assertNotNull($device->revokedAt);
    }

    /**
     * Revoking twice must not move the timestamp — it is the record of when
     * access was withdrawn, not of the last time somebody clicked the button.
     */
    public function testRevokingTwiceKeepsTheFirstTimestamp(): void
    {
        ['device' => $device] = TrustedDevice::create(new User(), 'main', 'Firefox', self::future());

        $device->revoke();
        $first = $device->revokedAt;
        $device->revoke();

        self::assertSame($first, $device->revokedAt);
    }

    public function testAnExpiredGrantIsNotActive(): void
    {
        ['device' => $device] = TrustedDevice::create(
            new User(),
            'main',
            'Firefox',
            new DateTimeImmutable('-1 second'),
        );

        self::assertTrue($device->isExpired());
        self::assertFalse($device->isActive());
    }

    public function testExtendingOnlyEverMovesTheExpiryForward(): void
    {
        $expires = self::future();
        ['device' => $device] = TrustedDevice::create(new User(), 'main', 'Firefox', $expires);

        $device->extendTo(new DateTimeImmutable('+1 day'));

        self::assertEquals($expires, $device->expiresAt);

        $later = new DateTimeImmutable('+60 days');
        $device->extendTo($later);

        self::assertEquals($later, $device->expiresAt);
    }

    public function testAnEmptyLabelFallsBackRatherThanRenderingBlank(): void
    {
        ['device' => $device] = TrustedDevice::create(new User(), 'main', '   ', self::future());

        self::assertSame('Unknown device', $device->label);
    }
}
