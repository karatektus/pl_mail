<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Setup;

use App\Infrastructure\Setup\GeneratedSecretsFile;
use PHPUnit\Framework\TestCase;

/**
 * The file that holds a plMail install's generated secrets.
 *
 * What matters here is that a secret is minted exactly once. Four services boot
 * from the same image at the same time and all of them run this code; if two of
 * them generated their own APP_ENCRYPTION_KEY, the install would come up
 * looking fine and start writing credentials that half the fleet cannot read.
 */
final class GeneratedSecretsFileTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir().'/plmail-secrets-'.bin2hex(random_bytes(6)).'/generated.env';
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }

        if (is_dir(\dirname($this->path))) {
            rmdir(\dirname($this->path));
        }
    }

    public function testItGeneratesAValueOnceAndReturnsTheSameOneAfterwards(): void
    {
        $file = new GeneratedSecretsFile($this->path);

        $calls = 0;
        $generate = static function () use (&$calls): string {
            ++$calls;

            return 'value-'.$calls;
        };

        self::assertSame('value-1', $file->ensure('APP_SECRET', $generate));
        self::assertSame('value-1', $file->ensure('APP_SECRET', $generate));
        self::assertSame(1, $calls, 'the generator must not run again once a value is stored');
    }

    public function testASecondInstanceReadsWhatTheFirstWrote(): void
    {
        (new GeneratedSecretsFile($this->path))->ensure('APP_SECRET', static fn (): string => 'written-first');

        // Stands in for a second container starting against the same volume.
        $other = new GeneratedSecretsFile($this->path);

        self::assertTrue($other->has('APP_SECRET'));
        self::assertSame('written-first', $other->ensure('APP_SECRET', static fn (): string => 'must-not-be-used'));
    }

    public function testItKeepsSeparateNamesApartAndReportsMissingOnes(): void
    {
        $file = new GeneratedSecretsFile($this->path);

        $file->ensure('VAPID_PUBLIC_KEY', static fn (): string => 'public');
        $file->ensure('VAPID_PRIVATE_KEY', static fn (): string => 'private');

        self::assertSame(
            ['VAPID_PUBLIC_KEY' => 'public', 'VAPID_PRIVATE_KEY' => 'private'],
            $file->read(),
        );
        self::assertFalse($file->has('APP_SECRET'));
    }

    public function testValuesContainingAnEqualsSignSurviveTheRoundTrip(): void
    {
        // A base64 32-byte key ends in padding, which is exactly this case.
        $key = base64_encode(random_bytes(32));

        $file = new GeneratedSecretsFile($this->path);
        $file->ensure('APP_ENCRYPTION_KEY', static fn (): string => $key);

        self::assertSame($key, (new GeneratedSecretsFile($this->path))->read()['APP_ENCRYPTION_KEY']);
    }

    /**
     * `app:reset --full` takes an install back to first-run state, and the
     * database password is the one thing that must survive it: Postgres was
     * initialised with it and keeps its own copy, so a regenerated one locks
     * the app out of the database it just reset.
     */
    public function testRemoveDropsOnlyWhatItIsAskedFor(): void
    {
        $file = new GeneratedSecretsFile($this->path);

        $file->ensure('APP_SECRET', static fn (): string => 'app');
        $file->ensure('APP_ENCRYPTION_KEY', static fn (): string => 'key');
        $file->ensure('POSTGRES_PASSWORD', static fn (): string => 'db');

        $removed = $file->remove(['APP_SECRET', 'APP_ENCRYPTION_KEY', 'VAPID_PUBLIC_KEY']);

        self::assertSame(['APP_SECRET', 'APP_ENCRYPTION_KEY'], $removed, 'a name that was not there is not reported as removed');
        self::assertSame(['POSTGRES_PASSWORD' => 'db'], $file->read());
    }

    public function testTheFileIsNotReadableByAnyoneElse(): void
    {
        $file = new GeneratedSecretsFile($this->path);
        $file->ensure('APP_SECRET', static fn (): string => 'secret');

        clearstatcache();

        self::assertSame('0600', substr(sprintf('%o', fileperms($this->path)), -4));
    }
}
