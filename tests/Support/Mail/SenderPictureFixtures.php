<?php

declare(strict_types=1);

namespace App\Tests\Support\Mail;

/**
 * Small real images for the sender picture tests, made with GD on the spot
 * rather than checked in as binary files nobody can read in a diff.
 */
final class SenderPictureFixtures
{
    public static function png(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 40, 40));

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    public static function svg(string $extra = ''): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10">'
            . '<rect width="10" height="10" fill="#c00"/>' . $extra . '</svg>';
    }
}
