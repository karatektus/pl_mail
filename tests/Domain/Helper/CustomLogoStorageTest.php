<?php

declare(strict_types=1);

namespace App\Tests\Domain\Helper;

use App\Domain\Helper\AppIconStorage;
use App\Domain\Helper\CustomLogoStorage;
use PHPUnit\Framework\TestCase;

/**
 * Where a user's own logo is kept (#600).
 *
 * It used to be var/custom-logos/, which compose.yaml has no volume for, so an
 * upgrade recreated the container and the logo was gone while the row still
 * named it. It is under uploads/ now, which every install keeps, and a logo
 * left in the old place is moved across the first time it is looked for.
 */
final class CustomLogoStorageTest extends TestCase
{
    private const string USER = '7';

    private const string SVG = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10"><circle cx="5" cy="5" r="4"/></svg>';

    private string $root;

    private AppIconStorage $appIcons;

    private CustomLogoStorage $logos;

    protected function setUp(): void
    {
        $this->root     = sys_get_temp_dir() . '/custom-logo-test-' . bin2hex(random_bytes(4));
        $this->appIcons = new AppIconStorage($this->root, 'storage', 'secret');
        $this->logos    = new CustomLogoStorage($this->appIcons, $this->root, 'storage');
    }

    protected function tearDown(): void
    {
        $this->logos->deleteAllFor(self::USER);

        foreach (['storage/uploads/custom-logos', 'storage/uploads', 'storage/custom-logos', 'storage', ''] as $directory) {
            @rmdir($this->root . '/' . $directory);
        }
    }

    /** Under uploads/, the tree compose.yaml and the NAS templates keep. */
    public function testALogoIsKeptUnderUploads(): void
    {
        $hash = $this->logos->store(self::USER, self::SVG);

        self::assertFileExists($this->newPath($hash));
        self::assertFileDoesNotExist($this->legacyPath($hash));
        self::assertSame($this->newPath($hash), $this->logos->pathFor($this->key(), $hash));
    }

    /** A logo from before the move is found, and moved, rather than lost. */
    public function testALogoInTheOldPlaceIsMovedAcross(): void
    {
        $hash = $this->putLegacy(self::SVG);

        self::assertTrue($this->logos->has(self::USER, $hash));
        self::assertFileExists($this->newPath($hash));
        self::assertStringEqualsFile($this->newPath($hash), self::SVG);
        self::assertDirectoryDoesNotExist(dirname($this->legacyPath($hash)), 'nothing reads the old directory after the move');
    }

    /** One uploaded since the move is the current one; an older file never replaces it. */
    public function testANewerLogoIsNotReplacedByAnOldOne(): void
    {
        $current = $this->logos->store(self::USER, self::SVG);
        $old     = $this->putLegacy('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1 1"/>');

        self::assertTrue($this->logos->has(self::USER, $current));
        self::assertFalse($this->logos->has(self::USER, $old));
        self::assertFileDoesNotExist($this->newPath($old));
        self::assertDirectoryDoesNotExist(dirname($this->legacyPath($old)));
    }

    public function testDeletingForgetsBothPlaces(): void
    {
        $legacy = $this->putLegacy(self::SVG);

        $this->logos->deleteAllFor(self::USER);

        self::assertFileDoesNotExist($this->legacyPath($legacy));
        self::assertFalse($this->logos->has(self::USER, $legacy));
    }

    /** Writes a logo where it was kept before #600 and returns its hash. */
    private function putLegacy(string $svg): string
    {
        $hash = substr(hash('sha256', $svg), 0, 16);
        $path = $this->legacyPath($hash);

        mkdir(dirname($path), 0755, true);
        file_put_contents($path, $svg);

        return $hash;
    }

    private function newPath(string $hash): string
    {
        return $this->root . '/storage/uploads/custom-logos/' . $this->key() . '/' . $hash . '.svg';
    }

    private function legacyPath(string $hash): string
    {
        return $this->root . '/storage/custom-logos/' . $this->key() . '/' . $hash . '.svg';
    }

    private function key(): string
    {
        return $this->appIcons->keyFor(self::USER);
    }
}
