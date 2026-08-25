<?php

declare(strict_types=1);

namespace App\Domain\Helper;

/**
 * Where a user's saved signature lives on disk.
 *
 * Modelled on AvatarStorage, and deliberately so: one file per user under
 * var/uploads, replaced in place rather than versioned, filename random so a
 * changed image is not served from cache. Same disk-not-database reasoning too
 * — a PNG has no business in a row that is read on every request.
 *
 * WHAT THIS IS NOT
 *
 * Not a credential, and not key material. It is a picture of a name, and the
 * signatures it stamps are visual ones: what a printed-signed-scanned copy is
 * worth, no more. Nothing here should ever be treated as proving who signed
 * something, and it is encrypted at rest by nothing, which is the honest
 * treatment for an image of a squiggle.
 *
 * It is still the user's own and is served only to them — see
 * ProfileController::avatar(), which this follows.
 *
 * WHY PNG ONLY, AND WHY THE CEILING IS SMALL
 *
 * The image is stamped onto somebody else's document, so it has to have a
 * transparent background — anything else lands as a white card over the text
 * beneath it. PNG is the only format the signing pad produces and the only one
 * that carries alpha reliably, so accepting JPEG would be accepting a bad
 * result rather than a broader one.
 *
 * A megabyte is enormous for a trimmed scribble: the pad's own output is a few
 * kilobytes. The ceiling is there for an uploaded scan, which is the case where
 * somebody hands over a phone photo.
 */
readonly class SignatureStorage
{
    public const array ALLOWED_MIME = ['image/png'];

    public const int MAX_BYTES = 1024 * 1024;

    public function __construct(
        private string $projectDir,
        private string $storageDir,
    ) {
    }

    public function isAcceptable(string $mime, int $bytes): bool
    {
        return $bytes <= self::MAX_BYTES && in_array($mime, self::ALLOWED_MIME, true);
    }

    /**
     * @return string the stored filename, to put on the user
     */
    public function store(string $userId, string $contents): string
    {
        $directory = $this->directory($userId);

        if (false === is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $this->deleteAllFor($userId);

        // Random rather than fixed: the filename is in the URL, and a stable
        // one would be served from cache after a change.
        $filename = bin2hex(random_bytes(8)) . '.png';

        file_put_contents($directory . '/' . $filename, $contents);

        return $filename;
    }

    public function pathFor(string $userId, string $filename): string
    {
        return $this->directory($userId) . '/' . $filename;
    }

    public function deleteAllFor(string $userId): void
    {
        foreach (glob($this->directory($userId) . '/*') ?: [] as $existing) {
            if (true === is_file($existing)) {
                unlink($existing);
            }
        }
    }

    private function directory(string $userId): string
    {
        return $this->projectDir . '/' . $this->storageDir . '/uploads/signatures/' . $userId;
    }
}
