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

    /**
     * The write a config-backup import performs, in one call.
     *
     * All three behaviours at once, because they are one behaviour and testing
     * them apart would let an implementation pass that appends a second
     * APP_SECRET= line below the first: an existing name is updated in place, a
     * missing one is added, and anything the caller said nothing about is still
     * there afterwards. That last part is the one with teeth — an import
     * carries the names the backup happened to hold, and a rewrite that dropped
     * MERCURE_JWT_SECRET because this backup had none would silently
     * disconnect the hub at the next start.
     */
    public function testSetManyUpdatesAddsAndLeavesEverythingElseAlone(): void
    {
        $file = new GeneratedSecretsFile($this->path);

        $file->ensure('APP_SECRET', static fn (): string => 'the-old-secret');
        $file->ensure('MERCURE_JWT_SECRET', static fn (): string => 'the-hub-secret');

        $file->setMany([
            'APP_SECRET'        => 'the-restored-secret',
            'VAPID_PRIVATE_KEY' => 'a-key-this-file-never-had',
        ]);

        self::assertSame(
            [
                // In place, not appended: order is the file's original order,
                // with the additions at the end.
                'APP_SECRET'         => 'the-restored-secret',
                'MERCURE_JWT_SECRET' => 'the-hub-secret',
                'VAPID_PRIVATE_KEY'  => 'a-key-this-file-never-had',
            ],
            (new GeneratedSecretsFile($this->path))->read(),
        );

        // One line per name, so nothing is shadowing anything: the shell in
        // frankenphp/docker-entrypoint.sh exports every line it reads, and a
        // duplicate would have the stale value win or lose depending on which
        // reader looked.
        $lines = file($this->path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        self::assertIsArray($lines);
        self::assertCount(3, $lines);
    }

    /**
     * The mode has to survive a rewrite, not just the first creation. The whole
     * file is truncated and written again, and a rewrite that ended up 0644
     * would put every secret the install has where any process on the host can
     * read it — with nothing anywhere saying it had happened.
     */
    public function testSetManyLeavesTheFileUnreadableByAnyoneElse(): void
    {
        $file = new GeneratedSecretsFile($this->path);
        $file->ensure('APP_SECRET', static fn (): string => 'secret');

        $file->setMany(['APP_SECRET' => 'a-restored-secret']);

        clearstatcache();

        self::assertSame('0600', substr(sprintf('%o', fileperms($this->path)), -4));
    }

    /** A file that does not exist yet is writable if its directory can be made. */
    public function testWritabilityIsMeasuredAgainstTheNearestExistingAncestor(): void
    {
        self::assertTrue(
            (new GeneratedSecretsFile($this->path))->isWritable(),
            'a path under a directory that can be created must count as writable',
        );

        // /sys is read-only even to uid 0, which this container is — so a
        // chmod-ed temporary directory would prove nothing.
        self::assertFalse((new GeneratedSecretsFile('/sys/plmail-nowhere/generated.env'))->isWritable());
    }
}
