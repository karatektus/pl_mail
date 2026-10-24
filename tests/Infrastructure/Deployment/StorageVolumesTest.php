<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Deployment;

use App\Domain\Helper\RawMessageStorage;
use App\Domain\Helper\SealedMessageStorage;
use App\Domain\Helper\UploadStorage;
use FilesystemIterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Yaml\Yaml;

/**
 * What the app keeps on disk has to land on a volume every container mounts.
 *
 * The shipped compose.yaml runs the web server and the workers as separate
 * containers from one image, and shares only the directories listed under
 * `x-app-storage`. A file written anywhere else stays in the container that
 * wrote it. That is how encrypted mail failed to send: the web container kept
 * the message the browser built in var/sealed/, the worker that sends it found
 * nothing ("the built message is missing"), and the mail stayed in Drafts. The
 * test stack mounts the whole checkout into every container, so no other test
 * could see it.
 *
 * Each storage is asked to write for real, into a scratch project directory,
 * and the file it made has to be under one of the shared directories.
 */
final class StorageVolumesTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/storage-volumes-' . bin2hex(random_bytes(4));
        mkdir($this->projectDir);
    }

    protected function tearDown(): void
    {
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->projectDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            true === $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($this->projectDir);
    }

    /**
     * @return iterable<string, array{\Closure(string): mixed}>
     */
    public static function storages(): iterable
    {
        foreach ([SealedMessageStorage::DRAFT, SealedMessageStorage::OUTGOING, SealedMessageStorage::DECRYPTED] as $kind) {
            yield 'sealed ' . $kind => [static fn (string $project) => new SealedMessageStorage($project, 'var')->write(1234, $kind, 'x')];
        }

        yield 'raw message' => [static fn (string $project) => new RawMessageStorage($project, 'var')->store(7, 1234, 'x')];
        yield 'upload'      => [static fn (string $project) => new UploadStorage($project, 'var')->store(7, 'x')];
    }

    /**
     * @param \Closure(string): mixed $write
     */
    #[DataProvider('storages')]
    public function testItWritesOntoAVolumeEveryContainerShares(\Closure $write): void
    {
        $write($this->projectDir);

        $written = $this->writtenFiles();
        self::assertNotEmpty($written, 'the storage wrote nothing');

        foreach ($written as $file) {
            self::assertTrue(
                $this->isShared($file),
                sprintf('%s is not under any directory compose.yaml shares (%s)', $file, implode(', ', self::sharedDirectories())),
            );
        }
    }

    /**
     * The container paths under /app/var that compose.yaml's `x-app-storage`
     * mounts, as paths relative to the project: `var/raw`, `var/uploads`, ….
     *
     * @return list<string>
     */
    private static function sharedDirectories(): array
    {
        $compose = Yaml::parseFile(dirname(__DIR__, 3) . '/compose.yaml');
        $mounts  = [];

        foreach ((array) ($compose['x-app-storage'] ?? []) as $mount) {
            $target = explode(':', (string) $mount)[1] ?? '';

            if (true === str_starts_with($target, '/app/')) {
                $mounts[] = substr($target, strlen('/app/'));
            }
        }

        return $mounts;
    }

    private function isShared(string $relative): bool
    {
        foreach (self::sharedDirectories() as $directory) {
            if (true === str_starts_with($relative, $directory . '/')) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> paths relative to the scratch project */
    private function writtenFiles(): array
    {
        $files   = [];
        $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->projectDir, FilesystemIterator::SKIP_DOTS));

        foreach ($entries as $entry) {
            if (true === $entry->isFile()) {
                $files[] = substr($entry->getPathname(), strlen($this->projectDir) + 1);
            }
        }

        return $files;
    }
}
