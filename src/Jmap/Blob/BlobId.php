<?php

declare(strict_types=1);

namespace App\Jmap\Blob;

/**
 * A JMAP blobId, namespaced by what it points at.
 *
 * plMail has two independent sources of downloadable bytes — a whole Message
 * (its RFC822 source) and a single MessagePart (an attachment) — living in
 * different tables with independent autoincrement ids. Emitting a bare id makes
 * blob "239049" ambiguous, and the download endpoint has no way to resolve it.
 * A one-character namespace fixes that and stays opaque to clients, which the
 * spec requires anyway (RFC 8620 §1.6.3: blobIds are server-defined and MUST
 * NOT be parsed by the client).
 */
final class BlobId
{
    public const string MESSAGE = 'm';
    public const string PART = 'p';
    public const string UPLOAD = 'u';

    private function __construct(
        public readonly string $type,
        public readonly int $id,
    ) {
    }

    public static function forMessage(int $messageId): self
    {
        return new self(self::MESSAGE, $messageId);
    }

    public static function forPart(int $partId): self
    {
        return new self(self::PART, $partId);
    }

    public static function forUpload(int $uploadId): self
    {
        return new self(self::UPLOAD, $uploadId);
    }

    /**
     * Parse a client-supplied blobId. Returns null for anything malformed, so
     * callers answer with notFound rather than trusting the input.
     */
    public static function parse(string $value): ?self
    {
        if (1 !== preg_match('/^([mpu])-([1-9][0-9]*)$/', $value, $matches)) {
            return null;
        }

        return new self($matches[1], (int) $matches[2]);
    }

    public function isMessage(): bool
    {
        return self::MESSAGE === $this->type;
    }

    public function isPart(): bool
    {
        return self::PART === $this->type;
    }

    public function isUpload(): bool
    {
        return self::UPLOAD === $this->type;
    }

    public function __toString(): string
    {
        return sprintf('%s-%d', $this->type, $this->id);
    }
}
