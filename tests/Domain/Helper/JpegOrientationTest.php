<?php

declare(strict_types=1);

namespace App\Tests\Domain\Helper;

use App\Domain\Helper\JpegOrientation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The orientation tag is read without the exif extension, so the reader is
 * pinned against JPEGs built here with the tag written in both byte orders.
 */
final class JpegOrientationTest extends TestCase
{
    /**
     * A JPEG of the given size, with an APP1 Exif segment carrying the
     * orientation tag when one is given.
     */
    public static function jpeg(int $width, int $height, ?int $orientation = null, bool $littleEndian = true): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, intdiv($width, 2), $height, (int) imagecolorallocate($image, 255, 0, 0));

        ob_start();
        imagejpeg($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        if (null === $orientation) {
            return $bytes;
        }

        $short = static fn (int $value): string => pack($littleEndian ? 'v' : 'n', $value);
        $long  = static fn (int $value): string => pack($littleEndian ? 'V' : 'N', $value);

        $tiff = ($littleEndian ? 'II' : 'MM') . $short(42) . $long(8)
            . $short(1)
            . $short(0x0112) . $short(3) . $long(1) . $short($orientation) . $short(0)
            . $long(0);

        $payload = "Exif\0\0" . $tiff;
        $segment = "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;

        return substr($bytes, 0, 2) . $segment . substr($bytes, 2);
    }

    /** @return iterable<string, array{int, bool}> */
    public static function tags(): iterable
    {
        foreach ([1, 3, 6, 8] as $orientation) {
            yield "{$orientation}, little-endian" => [$orientation, true];
            yield "{$orientation}, big-endian" => [$orientation, false];
        }
    }

    #[DataProvider('tags')]
    public function testTheTagIsReadInEitherByteOrder(int $orientation, bool $littleEndian): void
    {
        self::assertSame($orientation, JpegOrientation::of(self::jpeg(8, 4, $orientation, $littleEndian)));
    }

    public function testNoTagAndNoJpegReadAsStored(): void
    {
        self::assertSame(1, JpegOrientation::of(self::jpeg(8, 4)));
        self::assertSame(1, JpegOrientation::of("\x89PNG\r\n\x1a\n"));
        self::assertSame(1, JpegOrientation::of(''));
        self::assertSame(1, JpegOrientation::of("\xFF\xD8\xFF\xE1\x00\x08Exif\0\0"));
    }

    public function testAQuarterTurnSwapsTheEdges(): void
    {
        foreach ([5, 6, 7, 8] as $orientation) {
            $turned = JpegOrientation::apply(imagecreatetruecolor(40, 20), $orientation);

            self::assertSame([20, 40], [imagesx($turned), imagesy($turned)], "orientation {$orientation}");
        }

        foreach ([1, 2, 3, 4] as $orientation) {
            $kept = JpegOrientation::apply(imagecreatetruecolor(40, 20), $orientation);

            self::assertSame([40, 20], [imagesx($kept), imagesy($kept)], "orientation {$orientation}");
        }
    }

    /**
     * 6 is how a phone stores a portrait photo: the left half of the stored
     * image (red here) is the TOP of the picture once turned.
     */
    public function testSixTurnsClockwise(): void
    {
        $image = imagecreatefromstring(self::jpeg(40, 20));
        self::assertInstanceOf(\GdImage::class, $image);

        $turned = JpegOrientation::apply($image, 6);

        $top    = imagecolorsforindex($turned, imagecolorat($turned, 10, 2));
        $bottom = imagecolorsforindex($turned, imagecolorat($turned, 10, 37));

        self::assertGreaterThan(200, $top['red'], 'the stored left half is on top');
        self::assertLessThan(60, $bottom['red'], 'and the right half at the bottom');
    }
}
