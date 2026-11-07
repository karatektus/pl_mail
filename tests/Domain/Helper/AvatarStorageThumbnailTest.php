<?php

declare(strict_types=1);

namespace App\Tests\Domain\Helper;

use App\Domain\Helper\AvatarStorage;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * An avatar is kept small and upright, whichever way it arrives, and one kept
 * whole before that is served from a small copy.
 *
 * What prompted it: a 2544×3392, 1.7 MB phone photo, drawn at 32px in the top
 * bar and downloaded whole on every page load.
 */
final class AvatarStorageThumbnailTest extends TestCase
{
    private string $root;

    private AvatarStorage $avatars;

    protected function setUp(): void
    {
        $this->root    = sys_get_temp_dir() . '/avatar-test-' . bin2hex(random_bytes(4));
        $this->avatars = new AvatarStorage($this->root, 'storage');
    }

    protected function tearDown(): void
    {
        $this->avatars->deleteAllFor('7');
        @rmdir($this->root . '/storage/uploads/avatars/7');
    }

    /** From a connected service: AvatarFromIntegration hands over the bytes. */
    public function testAPortraitPhotoFromAServiceIsKeptSmallAndUpright(): void
    {
        // Stored landscape with "turn it clockwise", as a phone stores a portrait.
        $filename = $this->avatars->storeContents('7', 'me.jpg', JpegOrientationTest::jpeg(1200, 800, 6));

        $this->assertKeptSmall($filename, [171, 256]);
    }

    /** From the upload form, in Settings or during onboarding: ProfileUpdater hands over the upload. */
    public function testAnUploadedPhotoIsKeptSmallAndUpright(): void
    {
        $upload = $this->root . '/upload.jpg';
        @mkdir($this->root, 0755, true);
        file_put_contents($upload, JpegOrientationTest::jpeg(1600, 1200, 6));

        $filename = $this->avatars->store('7', new UploadedFile($upload, 'me.jpg', 'image/jpeg', test: true));

        $this->assertKeptSmall($filename, [192, 256]);
    }

    public function testASmallPictureIsNotEnlarged(): void
    {
        $filename = $this->avatars->storeContents('7', 'tiny.png', $this->png(48, 40));

        [$width, $height] = (array) getimagesize($this->avatars->pathFor('7', $filename));

        self::assertSame([48, 40], [$width, $height]);
    }

    /**
     * Stored whole before avatars were scaled on the way in: a small copy is
     * made the first time it is served, made once, and goes with the original.
     */
    public function testAnAvatarKeptWholeBeforeIsServedFromASmallCopy(): void
    {
        $directory = dirname($this->avatars->pathFor('7', 'x'));
        @mkdir($directory, 0755, true);
        file_put_contents($directory . '/0123456789abcdef.jpg', JpegOrientationTest::jpeg(2400, 1800, 6));

        $served = $this->avatars->thumbnailFor('7', '0123456789abcdef.jpg');

        self::assertStringEndsWith('.thumb.webp', $served);
        [$width, $height] = (array) getimagesize($served);
        self::assertSame([192, 256], [$width, $height]);

        touch($served, time() - 3600);
        clearstatcache();
        $made = filemtime($served);

        self::assertSame($served, $this->avatars->thumbnailFor('7', '0123456789abcdef.jpg'));
        clearstatcache();
        self::assertSame($made, filemtime($served), 'not made again');

        $this->avatars->storeContents('7', 'new.png', $this->png(10, 10));

        self::assertFileDoesNotExist($served, 'replaced with the picture it was made from');
    }

    public function testWhatGdCannotReadIsKeptAndServedAsItIs(): void
    {
        $filename = $this->avatars->storeContents('7', 'odd.jpg', 'not an image at all');

        self::assertStringEndsWith('.jpg', $filename);
        self::assertSame($this->avatars->pathFor('7', $filename), $this->avatars->thumbnailFor('7', $filename));
    }

    /** @param array{int, int} $size */
    private function assertKeptSmall(string $filename, array $size): void
    {
        self::assertStringEndsWith('.webp', $filename);

        $stored = $this->avatars->pathFor('7', $filename);
        [$width, $height, $type] = (array) getimagesize($stored);

        self::assertSame(IMAGETYPE_WEBP, $type);
        self::assertSame($size, [$width, $height], 'upright, longest edge 256');
        self::assertLessThan(20_000, filesize($stored));
        self::assertSame($stored, $this->avatars->thumbnailFor('7', $filename), 'served as it was stored');
        self::assertCount(1, glob(dirname($stored) . '/*') ?: [], 'nothing kept beside it');
    }

    private function png(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);

        ob_start();
        imagepng($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }
}
