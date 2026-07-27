<?php

declare(strict_types=1);

namespace App\Jmap\Blob;

/**
 * A blob ready to be served: either a file on disk (attachments, which may have
 * just been fetched lazily from Gmail/Graph) or bytes held in memory (a
 * reconstructed message source).
 */
final class ResolvedBlob
{
    private function __construct(
        public readonly ?string $path,
        public readonly ?string $content,
        public readonly string $contentType,
        public readonly string $filename,
    ) {
    }

    public static function fromPath(string $path, string $contentType, string $filename): self
    {
        return new self($path, null, $contentType, $filename);
    }

    public static function fromContent(string $content, string $contentType, string $filename): self
    {
        return new self(null, $content, $contentType, $filename);
    }

    public function isFile(): bool
    {
        return null !== $this->path;
    }
}
