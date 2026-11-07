<?php

declare(strict_types=1);

namespace App\Domain\Helper;

use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Where a user's avatar lives on disk.
 *
 * Same disk-not-database reasoning as the other blob stores, and the same
 * shared-storage requirement: the web container writes it and also serves it,
 * but the directory sits under var/uploads, which is already bound across the
 * services that need it.
 *
 * One picture per user, and the small copy of it that is served (see
 * thumbnailFor()), both replaced rather than versioned — nothing links to an
 * old avatar once a new one is uploaded.
 */
readonly class AvatarStorage
{
    /** GD-native raster formats only, matching the attachment thumbnailer. */
    public const array ALLOWED_MIME = ['image/png', 'image/jpeg', 'image/gif', 'image/webp'];

    /** Generous for a face, small enough that nobody uploads a RAW file. */
    public const int MAX_BYTES = 4 * 1024 * 1024;

    /**
     * The longest edge an avatar is kept at.
     *
     * The largest the picture is ever drawn is the 80px preview in Settings, so
     * 256 covers it at a 3x screen. What is uploaded is usually a phone photo —
     * the one that prompted this was 2544×3392 and 1.7 MB, drawn at 32×32 in the
     * top bar and downloaded on every page load.
     */
    public const int EDGE = 256;

    /** Same decompression-bomb guard as AttachmentThumbnailer: read from the header, refused before decoding. */
    private const int MAX_PIXELS = 40_000_000;

    public function __construct(
        private string $projectDir,
        private string $storageDir,
    ) {
    }

    public function isAcceptable(UploadedFile $file): bool
    {
        return $file->getSize() <= self::MAX_BYTES
            && in_array($file->getMimeType(), self::ALLOWED_MIME, true);
    }

    /**
     * @return string the stored filename, to put on the user
     */
    public function store(string $userId, UploadedFile $file): string
    {
        $directory = $this->directory($userId);

        if (false === is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $this->deleteAllFor($userId);

        // Scaled down on the way in, whichever way the picture came — this and
        // storeContents() are the only two doors, and the form upload, the
        // onboarding step and a picture picked from a connected service all
        // come through one of them. Kept as it came only when GD cannot make a
        // copy (see scaled()), which thumbnailFor() then has another go at.
        $scaled = $this->scaled((string) file_get_contents($file->getPathname()));

        // Random rather than fixed: the filename is in the URL, and a stable
        // one would be served from cache after a change — it is now cached
        // for a year, so this matters more than it did.
        if (null !== $scaled) {
            $filename = bin2hex(random_bytes(8)).'.webp';
            file_put_contents($directory.'/'.$filename, $scaled);

            return $filename;
        }

        $filename = bin2hex(random_bytes(8)).'.'.($file->guessExtension() ?? 'img');

        $file->move($directory, $filename);

        return $filename;
    }

    /**
     * Same as store(), for bytes that arrived from somewhere other than an
     * upload — a file pulled out of a connected service, say.
     *
     * @return string the stored filename, to put on the user
     */
    public function storeContents(string $userId, string $originalName, string $contents): string
    {
        $directory = $this->directory($userId);

        if (false === is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $this->deleteAllFor($userId);

        $scaled = $this->scaled($contents);

        if (null !== $scaled) {
            $filename = bin2hex(random_bytes(8)).'.webp';
            file_put_contents($directory.'/'.$filename, $scaled);

            return $filename;
        }

        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $filename  = bin2hex(random_bytes(8)).'.'.('' === $extension ? 'img' : $extension);

        file_put_contents($directory.'/'.$filename, $contents);

        return $filename;
    }

    /**
     * The file to serve for an avatar.
     *
     * Avatars are scaled down when they are stored (store(), storeContents()),
     * so for any picture set since then this is the stored file itself. One
     * stored before that — or one GD could not scale at the time — is still
     * whatever was uploaded, often a whole phone photo; for those a small copy
     * is made the first time it is asked for and kept beside it, so they are
     * put right without a migration or a new upload. deleteAllFor() takes the
     * copy away with the original.
     *
     * Falls back to the stored file when no copy can be made — a format GD
     * cannot decode, an image too large to decode safely, a full disk. Serving
     * the big file is slow; serving nothing is broken.
     */
    public function thumbnailFor(string $userId, string $filename): string
    {
        $original = $this->pathFor($userId, $filename);
        $info     = @getimagesize($original);

        // Already small: served as it is. Not a JPEG, though — one stored
        // before scaling may be small and still carry an orientation tag, and
        // only the copy is turned.
        if (false !== $info && $info[0] <= self::EDGE && $info[1] <= self::EDGE && IMAGETYPE_JPEG !== $info[2]) {
            return $original;
        }

        $thumbnail = $original . '.thumb.webp';

        if (true === is_file($thumbnail)) {
            return $thumbnail;
        }

        $scaled = $this->scaled((string) file_get_contents($original));

        if (null === $scaled) {
            return $original;
        }

        // Write-then-rename, as the attachment thumbnailer does: two requests
        // for the same avatar at once must not serve a half-written file.
        $temporary = $thumbnail . '.' . bin2hex(random_bytes(4));

        if (false === @file_put_contents($temporary, $scaled) || false === @rename($temporary, $thumbnail)) {
            @unlink($temporary);

            return $original;
        }

        return $thumbnail;
    }

    public function pathFor(string $userId, string $filename): string
    {
        return $this->directory($userId).'/'.$filename;
    }

    public function deleteAllFor(string $userId): void
    {
        foreach (glob($this->directory($userId).'/*') ?: [] as $existing) {
            if (true === is_file($existing)) {
                unlink($existing);
            }
        }
    }

    /**
     * The picture as it is kept: no longer edge than EDGE, turned the way the
     * photo's EXIF orientation says, as WebP. Null when GD cannot make that.
     *
     * Turned because the browser honours the orientation tag of the original
     * and GD does not, so a copy made without it would stand sideways
     * (JpegOrientation). WebP because it keeps a PNG's transparency and a
     * photo's size down alike. A picture already smaller than EDGE is not
     * enlarged; it is still re-encoded, which also drops whatever metadata a
     * camera put in it — the location a photo was taken at among it.
     */
    private function scaled(string $bytes): ?string
    {
        $info = @getimagesizefromstring($bytes);

        if (false === $info || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::MAX_PIXELS) {
            return null;
        }

        $image = @imagecreatefromstring($bytes);

        if (false === $image) {
            return null;
        }

        $image  = JpegOrientation::apply($image, JpegOrientation::of($bytes));
        $width  = imagesx($image);
        $height = imagesy($image);
        $scale  = min(self::EDGE / $width, self::EDGE / $height, 1);
        $small  = imagescale($image, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));

        imagedestroy($image);

        if (false === $small) {
            return null;
        }

        imagealphablending($small, false);
        imagesavealpha($small, true);

        try {
            ob_start();
            $written = imagewebp($small, null, 82);
            $webp    = (string) ob_get_clean();
        } finally {
            imagedestroy($small);
        }

        return true === $written && '' !== $webp ? $webp : null;
    }

    private function directory(string $userId): string
    {
        return $this->projectDir.'/'.$this->storageDir.'/uploads/avatars/'.$userId;
    }
}
