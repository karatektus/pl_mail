<?php

declare(strict_types=1);

namespace App\Tests\Command\Maintenance;

use App\Command\Maintenance\PruneBlobsCommand;
use App\Domain\Helper\UploadStorage;
use App\Repository\Mail\MessagePartRepository;
use App\Repository\Mail\MessageRepository;
use App\Repository\Mail\UploadedBlobRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

/**
 * The orphan sweep against a project directory of its own, so the files it is
 * allowed to delete and the ones it must not can both be laid out exactly.
 *
 * #214: the sweep used to walk all of var/uploads, var/attachments and var/raw
 * and delete every week-old file no column named. Profile pictures, signature
 * images and appearance backgrounds live under var/uploads with no column of
 * the uploads store pointing at them, and went every Sunday. These tests hold
 * those files, and a directory nobody has written yet, across a run, and check
 * that the sweep still does its job in the account trees.
 *
 * The repositories are stubbed: what each column holds is their business and
 * is covered where they are; what is under test here is which files the
 * command even considers.
 */
final class PruneBlobsCommandTest extends TestCase
{
    private const string OLD = '-30 days';

    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/prune-blobs-'.bin2hex(random_bytes(6));
        mkdir($this->projectDir);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->projectDir);
    }

    public function testFilesOtherOwnersKeepUnderTheRootsSurviveARun(): void
    {
        $others = [
            'var/uploads/avatars/3/3f9c1e.png',
            'var/uploads/signatures/3/logo-a1b2c3.png',
            'var/uploads/backgrounds/7d2e.jpg',
            // A thumbnail of a part that exists: the thumbnail pass's, and kept.
            'var/attachments/thumbs/42.webp',
            // A store nobody has written yet, in the shape the sealed messages
            // of v0.4.0 take: it is left alone without anyone listing it here.
            'var/raw/sealed/3/0/17.bin',
            'var/uploads/someday/3/'.str_repeat('a', 32).'.blob',
            // Loose files at the root and inside a path that only looks like
            // an account tree further down are not ours either.
            'var/uploads/README',
            'var/raw/backup/3/0/1.eml',
        ];

        foreach ($others as $path) {
            $this->file($path, self::OLD);
        }

        $exit = $this->command(existingParts: [42])->execute([]);

        self::assertSame(Command::SUCCESS, $exit);

        foreach ($others as $path) {
            self::assertFileExists($this->projectDir.'/'.$path, $path.' is not one of the sweep\'s files.');
        }
    }

    public function testOrphansInTheAccountTreesAreStillDeleted(): void
    {
        $upload     = 'var/uploads/3/'.str_repeat('b', 32).'.blob';
        $attachment = 'var/attachments/3/12/4711/invoice.pdf';
        $raw        = 'var/raw/3/1/1042.eml';

        foreach ([$upload, $attachment, $raw] as $path) {
            $this->file($path, self::OLD);
        }

        $this->command()->execute([]);

        self::assertFileDoesNotExist($this->projectDir.'/'.$upload);
        self::assertFileDoesNotExist($this->projectDir.'/'.$attachment);
        self::assertFileDoesNotExist($this->projectDir.'/'.$raw);

        // The emptied fan-out directories go with them, account included.
        self::assertDirectoryDoesNotExist($this->projectDir.'/var/attachments/3');
        self::assertDirectoryDoesNotExist($this->projectDir.'/var/raw/3');
    }

    public function testReferencedAndRecentFilesInTheAccountTreesAreKept(): void
    {
        $referencedUpload = 'var/uploads/3/'.str_repeat('c', 32).'.blob';
        $referencedPart   = 'var/attachments/3/12/4711/kept.pdf';
        $referencedRaw    = 'var/raw/3/0/7.eml';
        $recentOrphan     = 'var/raw/3/0/8.eml';

        foreach ([$referencedUpload, $referencedPart, $referencedRaw] as $path) {
            $this->file($path, self::OLD);
        }

        $this->file($recentOrphan, '-1 day');

        $this->command(
            uploads: [$referencedUpload => true],
            parts: [$referencedPart => true],
            raw: [$referencedRaw => true],
        )->execute([]);

        self::assertFileExists($this->projectDir.'/'.$referencedUpload);
        self::assertFileExists($this->projectDir.'/'.$referencedPart);
        self::assertFileExists($this->projectDir.'/'.$referencedRaw);
        self::assertFileExists($this->projectDir.'/'.$recentOrphan, 'Inside the window, its row may not be committed yet.');
    }

    public function testADirectoryOfAnotherOwnerThatIsEmptyIsNotRemoved(): void
    {
        // An orphan elsewhere makes the run tidy up empty directories; the
        // owner of this one may be about to write into it.
        mkdir($this->projectDir.'/var/uploads/avatars/3', 0755, true);
        $this->file('var/uploads/3/'.str_repeat('d', 32).'.blob', self::OLD);

        $this->command()->execute([]);

        self::assertDirectoryExists($this->projectDir.'/var/uploads/avatars/3');
    }

    public function testADryRunDeletesNothing(): void
    {
        $orphan = 'var/raw/3/1/1042.eml';
        $this->file($orphan, self::OLD);

        $tester = $this->command();
        $tester->execute(['--dry-run' => true]);

        self::assertFileExists($this->projectDir.'/'.$orphan);
        self::assertStringContainsString('Orphans in var/raw: 1', $tester->getDisplay());
    }

    /**
     * #233: the stores are under APP_STORAGE_DIR, not a hardcoded var/. On an
     * install that moved them, the sweep looked where nothing was written and
     * reclaimed nothing.
     */
    public function testTheStoresAreUnderTheStorageDirectory(): void
    {
        $orphan     = 'var/data/raw/3/1/1042.eml';
        $referenced = 'var/data/attachments/3/12/4711/kept.pdf';
        $elsewhere  = 'var/raw/3/1/1042.eml';

        foreach ([$orphan, $referenced, $elsewhere] as $path) {
            $this->file($path, self::OLD);
        }

        $this->command(parts: [$referenced => true], storageDir: 'var/data')->execute([]);

        self::assertFileDoesNotExist($this->projectDir.'/'.$orphan, 'swept where the writers write');
        self::assertFileExists($this->projectDir.'/'.$referenced, 'the keep-list is asked with the storage dir\'s prefix');
        self::assertFileExists($this->projectDir.'/'.$elsewhere, 'and var/ is not this install\'s store');
    }

    /**
     * #232: a thumbnail is named by its part id, and nothing deletes it with
     * the part. It goes once the part is gone, and not before.
     */
    public function testThumbnailsOfPartsThatAreGoneAreDeleted(): void
    {
        $gone     = 'var/attachments/thumbs/41.webp';
        $existing = 'var/attachments/thumbs/42.webp';
        $recent   = 'var/attachments/thumbs/43.webp';
        $foreign  = 'var/attachments/thumbs/notes.txt';
        // Larger than any message_part.id can be: not ours, and it must not
        // turn the existence query into an error that stops the run.
        $tooLarge = 'var/attachments/thumbs/99999999999.webp';

        foreach ([$gone, $existing, $foreign, $tooLarge] as $path) {
            $this->file($path, self::OLD);
        }

        $this->file($recent, '-1 day');

        $tester = $this->command(existingParts: [42]);
        $tester->execute([]);

        self::assertFileDoesNotExist($this->projectDir.'/'.$gone);
        self::assertFileExists($this->projectDir.'/'.$existing, 'its part still exists');
        self::assertFileExists($this->projectDir.'/'.$recent, 'inside the window');
        self::assertFileExists($this->projectDir.'/'.$foreign, 'not a thumbnail');
        self::assertFileExists($this->projectDir.'/'.$tooLarge, 'not a part id');
        self::assertStringContainsString('Orphaned thumbnails: 1', $tester->getDisplay());
    }

    public function testADryRunKeepsThumbnailsToo(): void
    {
        $gone = 'var/attachments/thumbs/41.webp';
        $this->file($gone, self::OLD);

        $this->command()->execute(['--dry-run' => true]);

        self::assertFileExists($this->projectDir.'/'.$gone);
    }

    private function file(string $relativePath, string $age): void
    {
        $path = $this->projectDir.'/'.$relativePath;

        if (false === is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, 'x');
        touch($path, new \DateTimeImmutable($age)->getTimestamp());
    }

    /**
     * @param array<string, true> $uploads
     * @param array<string, true> $parts
     * @param array<string, true> $raw
     * @param list<int>           $existingParts part ids that still exist
     */
    private function command(
        array $uploads = [],
        array $parts = [],
        array $raw = [],
        array $existingParts = [],
        string $storageDir = 'var',
    ): CommandTester {
        $blobs = $this->createStub(UploadedBlobRepository::class);
        $blobs->method('findOlderThan')->willReturn([]);
        $blobs->method('findReferencedPaths')->willReturn($uploads);

        $messageParts = $this->createStub(MessagePartRepository::class);
        $messageParts->method('findReferencedStoragePaths')->willReturn($parts);
        $messageParts->method('findExistingIds')->willReturnCallback(
            static fn (array $ids): array => array_fill_keys(array_values(array_intersect($ids, $existingParts)), true),
        );

        $messages = $this->createStub(MessageRepository::class);
        $messages->method('findReferencedRawPaths')->willReturn($raw);

        return new CommandTester(new PruneBlobsCommand(
            $blobs,
            $messageParts,
            $messages,
            new UploadStorage($this->projectDir, $storageDir),
            $this->createStub(EntityManagerInterface::class),
            $this->projectDir,
            $storageDir,
        ));
    }
}
