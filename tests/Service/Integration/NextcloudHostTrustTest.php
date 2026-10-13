<?php

declare(strict_types=1);

namespace App\Tests\Service\Integration;

use App\Domain\Exception\IntegrationException;
use App\Service\Calendar\Subscription\NextcloudPreset;
use App\Service\Integration\IntegrationUrlValidator;
use PHPUnit\Framework\TestCase;

/**
 * The host in NEXTCLOUD_URL may be on the LAN, and only that host.
 *
 * The shortcut is for a self-hosted install, which is exactly where the
 * household Nextcloud sits on a private address — so the private-range
 * refusal, kept for every address a user types, must not refuse the one the
 * administrator wrote. What must not happen is the exemption leaking: to the
 * neighbouring address, or to plain http, which is a separate decision with
 * its own variable.
 *
 * IP literals rather than names, because a literal is private without a
 * resolver — the claim holds on a machine with no DNS at all.
 */
final class NextcloudHostTrustTest extends TestCase
{
    public function testTheConfiguredHostIsReachableOnAPrivateAddress(): void
    {
        $validator = $this->validator('https://192.168.1.10');

        $validator->assertAllowed('https://192.168.1.10/remote.php/dav');

        self::assertTrue($validator->isTrusted('192.168.1.10'));
    }

    public function testWithoutNextcloudUrlThePrivateAddressIsRefusedAsBefore(): void
    {
        $validator = $this->validator(null);

        self::assertFalse($validator->isTrusted('192.168.1.10'));

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('private network');

        $validator->assertAllowed('https://192.168.1.10/remote.php/dav');
    }

    public function testTheNeighbouringAddressIsStillRefused(): void
    {
        $validator = $this->validator('https://192.168.1.10');

        self::assertFalse($validator->isTrusted('192.168.1.11'));

        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('private network');

        $validator->assertAllowed('https://192.168.1.11/remote.php/dav');
    }

    public function testPlainHttpStillNeedsItsOwnSwitch(): void
    {
        $this->expectException(IntegrationException::class);
        $this->expectExceptionMessage('https');

        $this->validator('http://192.168.1.10')->assertAllowed('http://192.168.1.10/remote.php/dav');
    }

    private function validator(?string $nextcloudUrl): IntegrationUrlValidator
    {
        return new IntegrationUrlValidator(false, '', null, new NextcloudPreset($nextcloudUrl));
    }
}
