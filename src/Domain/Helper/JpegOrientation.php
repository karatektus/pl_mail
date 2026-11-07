<?php

declare(strict_types=1);

namespace App\Domain\Helper;

use GdImage;

/**
 * The EXIF orientation of a JPEG, and how to apply it to a GD image.
 *
 * A phone stores a portrait photo as a landscape image with a tag saying "turn
 * this 90° to show it". Browsers honour the tag when they draw the file; GD does
 * not, so anything that decodes a photo and writes a new image out of it — a
 * thumbnail — writes it sideways unless it applies the tag itself.
 *
 * Read here by hand rather than through exif_read_data(): the image does not
 * ship the exif extension, and the one value needed is two bytes at a known
 * place in the first segment of the file. Everything that is not a well-formed
 * APP1 Exif segment with an orientation tag answers 1, "as stored".
 */
final class JpegOrientation
{
    /** The orientation tag in the first IFD (TIFF tag 0x0112). */
    private const int TAG = 0x0112;

    /**
     * 1–8 as EXIF numbers them, 1 when the bytes are not a JPEG or carry no tag.
     */
    public static function of(string $bytes): int
    {
        if (false === str_starts_with($bytes, "\xFF\xD8")) {
            return 1;
        }

        $length = strlen($bytes);
        $offset = 2;

        while ($offset + 4 <= $length) {
            if ("\xFF" !== $bytes[$offset]) {
                return 1;
            }

            $marker = ord($bytes[$offset + 1]);

            // Start of scan or end of image: the metadata segments are behind us.
            if (0xDA === $marker || 0xD9 === $marker) {
                return 1;
            }

            $size = unpack('n', substr($bytes, $offset + 2, 2))[1];

            if (0xE1 === $marker && "Exif\0\0" === substr($bytes, $offset + 4, 6)) {
                return self::fromTiff($bytes, $offset + 10, min($length, $offset + 2 + $size));
            }

            $offset += 2 + $size;
        }

        return 1;
    }

    /**
     * Turn and flip a decoded image so it stands the way the tag says it should.
     *
     * imagerotate() turns anticlockwise, so a quarter turn clockwise is 270.
     * Answers the image to use, which is a new one when it was turned.
     */
    public static function apply(GdImage $image, int $orientation): GdImage
    {
        $turn = match ($orientation) {
            3       => 180,
            5, 6    => 270,
            7, 8    => 90,
            default => 0,
        };

        if (0 !== $turn) {
            $turned = imagerotate($image, $turn, 0);

            if (false !== $turned) {
                imagedestroy($image);
                $image = $turned;
            }
        }

        match ($orientation) {
            2, 5, 7 => imageflip($image, IMG_FLIP_HORIZONTAL),
            4       => imageflip($image, IMG_FLIP_VERTICAL),
            default => null,
        };

        return $image;
    }

    /**
     * The tag's value in the TIFF structure that starts at $tiff, looked up in
     * the first IFD and never read past $end.
     */
    private static function fromTiff(string $bytes, int $tiff, int $end): int
    {
        $order = substr($bytes, $tiff, 2);

        if ('II' !== $order && 'MM' !== $order) {
            return 1;
        }

        $little = 'II' === $order;
        $short  = static fn (int $at): ?int => $at + 2 <= $end ? unpack($little ? 'v' : 'n', substr($bytes, $at, 2))[1] : null;
        $long   = static fn (int $at): ?int => $at + 4 <= $end ? unpack($little ? 'V' : 'N', substr($bytes, $at, 4))[1] : null;

        $first = $long($tiff + 4);

        if (null === $first) {
            return 1;
        }

        $ifd     = $tiff + $first;
        $entries = $short($ifd) ?? 0;

        for ($index = 0; $index < $entries; ++$index) {
            $entry = $ifd + 2 + $index * 12;

            if (self::TAG === $short($entry)) {
                $value = $short($entry + 8) ?? 1;

                return $value >= 1 && $value <= 8 ? $value : 1;
            }
        }

        return 1;
    }
}
