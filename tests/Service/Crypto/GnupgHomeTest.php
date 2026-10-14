<?php

declare(strict_types=1);

namespace App\Tests\Service\Crypto;

use App\Service\Crypto\GnupgHome;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * available() starts gpg once to find out whether it can, rather than only
 * asking whether the extension is there. The failure it guards against (a
 * FrankenPHP server in which gpgme cannot read gpgconf) cannot be made here;
 * what can be pinned down is that a working gpg is reported as one, and that
 * the probe cleans up after itself like any other home.
 */
final class GnupgHomeTest extends TestCase
{
    protected function setUp(): void
    {
        if (false === extension_loaded('gnupg')) {
            self::markTestSkipped('ext-gnupg is not installed.');
        }

        new ReflectionProperty(GnupgHome::class, 'works')->setValue(null, null);
    }

    public function testAWorkingGpgIsAvailable(): void
    {
        self::assertTrue(GnupgHome::available());
    }

    public function testTheProbeLeavesNoHomeBehind(): void
    {
        $before = glob(sys_get_temp_dir() . '/plmail-gnupg-*') ?: [];

        GnupgHome::available();

        self::assertSame($before, glob(sys_get_temp_dir() . '/plmail-gnupg-*') ?: []);
    }
}
