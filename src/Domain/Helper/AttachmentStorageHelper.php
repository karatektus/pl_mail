<?php

declare(strict_types=1);

namespace App\Domain\Helper;

use Symfony\Component\Mime\MimeTypes;

readonly class AttachmentStorageHelper
{
    public function __construct(
        private string $projectDir,
        private string $storageDir,
    )
    {
    }

    public function store(int $accountId, int $mailboxId, int $messageUid, string $filename, string $content): string
    {
        $directory = sprintf(
            '%s/%s/attachments/%d/%d/%d',
            $this->projectDir,
            $this->storageDir,
            $accountId,
            $mailboxId,
            $messageUid,
        );
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $safeName = $this->sanitizeFilename($filename);
        $path = $directory . '/' . $safeName;

        // Avoid overwriting if the same filename appears twice in one message
        if (file_exists($path)) {
            $safeName = pathinfo($safeName, PATHINFO_FILENAME)
                . '_' . uniqid()
                . '.' . pathinfo($safeName, PATHINFO_EXTENSION);
            $path = $directory . '/' . $safeName;
        }

        file_put_contents($path, $content);

        // Return path relative to project root for storage in DB
        return sprintf(
            '%s/attachments/%d/%d/%d/%s',
            $this->storageDir,
            $accountId,
            $mailboxId,
            $messageUid,
            $safeName,
        );
    }

    /**
     * The name for a part its sender did not name: "attachment" with the
     * extension its type is known by — "attachment.pdf", "attachment.eml".
     *
     * The extension is the point. A file without one cannot be opened by the
     * operating system it is downloaded to, and a PDF is only offered a
     * preview by its name (PdfAttachment). webklex named these parts by a
     * checksum of their bytes ("57d5c7cb"), which carried neither (#718);
     * a type nobody has an extension for keeps the bare word.
     *
     * Static because it needs nothing of the store: it is a fact about the
     * type. Two such parts in one message are told apart by store(), which
     * suffixes a name that is already taken.
     */
    public static function fallbackName(string $contentType): string
    {
        $extension = MimeTypes::getDefault()->getExtensions(strtolower(trim($contentType)))[0] ?? null;

        return null === $extension ? 'attachment' : 'attachment.' . $extension;
    }

    public function getAbsolutePath(string $relativePath): string
    {
        return $this->projectDir . '/' . $relativePath;
    }

    /**
     * Remove a stored file. Anything that is not one of our own relative
     * storage paths — provider schemes like gmail:// live in the same column —
     * is ignored rather than resolved to a filesystem path.
     */
    public function delete(?string $relativePath): void
    {
        if (null === $relativePath || false === str_starts_with($relativePath, $this->storageDir.'/attachments/')) {
            return;
        }

        if (true === str_contains($relativePath, '..')) {
            return;
        }

        $path = $this->getAbsolutePath($relativePath);

        if (true === is_file($path)) {
            unlink($path);
        }
    }

    private function sanitizeFilename(string $filename): string
    {
        // Decode encoded filenames (e.g. =?UTF-8?B?...?=)
        $decoded = MimeHeaderHelper::decode($filename);

        // Strip directory traversal and dangerous characters
        $safe = basename($decoded);
        $safe = preg_replace('/[^\w.\-]/', '_', $safe);

        if ($safe === '' || $safe === '.') {
            $safe = 'attachment_' . uniqid();
        }

        return $safe;
    }
}
