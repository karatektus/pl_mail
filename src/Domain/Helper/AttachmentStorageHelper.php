<?php

namespace App\Domain\Helper;

readonly class AttachmentStorageHelper
{
    public function __construct(
        private string $projectDir,
    )
    {
    }

    public function store(int $accountId, int $mailboxId, int $messageUid, string $filename, string $content): string
    {
        $directory = sprintf(
            '%s/var/attachments/%d/%d/%d',
            $this->projectDir,
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
            'var/attachments/%d/%d/%d/%s',
            $accountId,
            $mailboxId,
            $messageUid,
            $safeName,
        );
    }

    public function getAbsolutePath(string $relativePath): string
    {
        return $this->projectDir . '/' . $relativePath;
    }

    private function sanitizeFilename(string $filename): string
    {
        // Decode encoded filenames (e.g. =?UTF-8?B?...?=)
        $decoded = iconv_mime_decode($filename);

        // Strip directory traversal and dangerous characters
        $safe = basename($decoded);
        $safe = preg_replace('/[^\w.\-]/', '_', $safe);

        if ($safe === '' || $safe === '.') {
            $safe = 'attachment_' . uniqid();
        }

        return $safe;
    }
}
