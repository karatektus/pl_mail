<?php

declare(strict_types=1);

namespace App\Tests\Service\Crypto;

use App\Service\Crypto\GnupgHome;
use App\Tests\Support\Crypto\Gpg;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The throwaway home and the gpg runs in it: what SignatureVerifier,
 * PublicKeyReader and WkdKeyExport build on. The listing is gpg's colon
 * format read by hand, so it is checked against keys gpg made, with the user
 * IDs and states that the readers above decide on.
 */
final class GnupgHomeTest extends TestCase
{
    protected function setUp(): void
    {
        if (false === GnupgHome::available()) {
            self::markTestSkipped('gpg is not installed here.');
        }
    }

    public function testTheHomeIsGoneAfterTheWork(): void
    {
        $path = GnupgHome::with(static function (GnupgHome $home): string {
            $home->file('key', 'anything');

            return $home->path;
        });

        self::assertDirectoryDoesNotExist($path);
    }

    public function testTheHomeIsGoneWhenTheWorkFails(): void
    {
        $path = '';

        try {
            GnupgHome::with(static function (GnupgHome $home) use (&$path): never {
                $path = $home->path;

                throw new RuntimeException('failed');
            });
        } catch (RuntimeException) {
        }

        self::assertNotSame('', $path);
        self::assertDirectoryDoesNotExist($path);
    }

    public function testAKeyIsListedWithItsPrimaryFingerprintAndAddresses(): void
    {
        $key = new Gpg('anna@example.org', ['Anna.Work@Example.NET']);

        $keys = GnupgHome::with(static function (GnupgHome $home) use ($key): array {
            $home->output($key->armored, '--import');

            return $home->keys();
        });

        self::assertCount(1, $keys);
        self::assertSame($key->fingerprint, $keys[0]['fingerprint']);
        self::assertEqualsCanonicalizing(['anna@example.org', 'anna.work@example.net'], $keys[0]['addresses']);
        self::assertStringContainsString('E', $keys[0]['capabilities'], 'the encryption subkey makes the key usable for encryption');
        self::assertNotSame('r', $keys[0]['validity']);
    }

    public function testARevokedKeyIsListedAsRevoked(): void
    {
        $key = new Gpg('anna@example.org');

        $keys = GnupgHome::with(static function (GnupgHome $home) use ($key): array {
            $home->output($key->revoked(), '--import');

            return $home->keys();
        });

        self::assertSame('r', $keys[0]['validity']);
    }

    public function testAnExpiredKeyIsListedAsExpired(): void
    {
        $key = new Gpg('anna@example.org', expired: true);

        $keys = GnupgHome::with(static function (GnupgHome $home) use ($key): array {
            $home->output($key->armored, '--import');

            return $home->keys();
        });

        self::assertSame('e', $keys[0]['validity']);
        self::assertGreaterThan(0, $keys[0]['expires']);
    }

    public function testOneKeyGpgCannotReadDoesNotKeepTheOthersOut(): void
    {
        $key = new Gpg('anna@example.org');

        $keys = GnupgHome::with(static function (GnupgHome $home) use ($key): array {
            $home->output(null, '--import', $home->file('junk', 'not a key'), $home->file('key', $key->armored));

            return $home->keys();
        });

        self::assertSame([$key->fingerprint], array_column($keys, 'fingerprint'));
    }
}
