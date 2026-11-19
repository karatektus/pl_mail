<?php

declare(strict_types=1);

namespace App\Domain\Helper;

/**
 * The URL segment that names one sender picture (#639): `u12-…`, `c345-…`,
 * `s678-…`.
 *
 * Three stores, three id spaces — a plMail user's profile picture, a contact's
 * photo, a looked-up picture in `sender_picture` — so the segment says which
 * one it means with a letter, the way a JMAP blobId does (BlobId). The id alone
 * would make "picture 12" ambiguous.
 *
 * The part after the dash is a VERSION: twelve hex digits of a hash of the
 * file's name, and every store here names a new picture with a new file name.
 * The URL therefore changes whenever the picture does, which is what lets the
 * response be cached as immutable for a year — and the route refuses a version
 * that is not the current one, so a stale URL is a 404 rather than somebody
 * else's newer photo under an old cache entry.
 */
final readonly class SenderPictureReference
{
    public const string USER    = 'u';
    public const string CONTACT = 'c';
    public const string STORED  = 's';

    public const string PATTERN = '[ucs][1-9][0-9]{0,18}-[0-9a-f]{12}';

    private function __construct(
        public string $type,
        public int $id,
        public string $version,
    ) {}

    public static function for(string $type, int $id, string $fileName): string
    {
        return sprintf('%s%d-%s', $type, $id, self::versionOf($fileName));
    }

    public static function parse(string $value): ?self
    {
        if (1 !== preg_match('/^(' . self::PATTERN . ')$/', $value)) {
            return null;
        }

        [$head, $version] = explode('-', $value, 2);

        return new self($head[0], (int) substr($head, 1), $version);
    }

    /** Whether $fileName is the file this reference was minted for. */
    public function names(string $fileName): bool
    {
        return true === hash_equals(self::versionOf($fileName), $this->version);
    }

    private static function versionOf(string $fileName): string
    {
        return substr(hash('sha256', $fileName), 0, 12);
    }
}
