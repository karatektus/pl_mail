<?php

declare(strict_types=1);

namespace App\Domain\DTO\Integration;

/**
 * A file fetched from a service, ready to become a MessagePart.
 *
 * Contents are held in memory as a string rather than streamed to a temp file,
 * matching GmailApiClient::getAttachment() and the AttachmentStorageHelper
 * signature it feeds. That is safe here because the only caller is the compose
 * picker, which refuses to copy anything above ComposeController's 25 MB cap.
 * The other direction never loads bytes at all — upload() takes a path, so
 * saving a large attachment to a service streams from disk.
 */
final readonly class RemoteFile
{
    public function __construct(
        public string $filename,
        public string $mime,
        public string $contents,
    ) {
    }

    public function size(): int
    {
        return strlen($this->contents);
    }
}
