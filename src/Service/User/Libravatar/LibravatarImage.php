<?php

declare(strict_types=1);

namespace App\Service\User\Libravatar;

use App\Domain\Helper\AvatarStorage;
use App\Entity\User\User;

/**
 * A user's profile picture as a Libravatar client asks for it (#642): a
 * square PNG of the requested size.
 *
 * Square because every client draws it in a square or a circle, and handing
 * back the picture's own proportions would have each of them crop it
 * differently. Cut from the middle of the small copy AvatarStorage already
 * keeps, rather than from the upload, which is whatever a camera made.
 * PNG because the spec's clients all read it and it keeps a transparent
 * background transparent.
 *
 * Made on each request, not stored: the copy it starts from is at most 256
 * pixels, scaling that is a few milliseconds, and the route is rate-limited
 * and cached publicly by everybody downstream.
 */
class LibravatarImage
{
    public const int DEFAULT_SIZE = 80;

    public const int MAX_SIZE = 512;

    public function __construct(
        private readonly AvatarStorage $avatars,
    ) {}

    /** PNG bytes, or null when the picture cannot be read. */
    public function render(User $user, int $size): ?string
    {
        if (null === $user->avatar || null === $user->id) {
            return null;
        }

        $path = $this->avatars->thumbnailFor((string) $user->id, $user->avatar);
        $data = @file_get_contents($path);
        $from = false === $data ? false : @imagecreatefromstring($data);

        if (false === $from) {
            return null;
        }

        $width  = imagesx($from);
        $height = imagesy($from);
        $edge   = min($width, $height);

        $square = imagecreatetruecolor($size, $size);
        imagealphablending($square, false);
        imagesavealpha($square, true);
        imagefill($square, 0, 0, (int) imagecolorallocatealpha($square, 0, 0, 0, 127));

        imagecopyresampled(
            $square,
            $from,
            0,
            0,
            intdiv($width - $edge, 2),
            intdiv($height - $edge, 2),
            $size,
            $size,
            $edge,
            $edge,
        );

        ob_start();
        imagepng($square, null, 9);
        $png = (string) ob_get_clean();

        return '' === $png ? null : $png;
    }

    /** The size the spec asks for, kept within what is worth drawing. */
    public static function size(mixed $requested): int
    {
        $size = is_numeric($requested) ? (int) $requested : self::DEFAULT_SIZE;

        return max(1, min(self::MAX_SIZE, $size));
    }
}
