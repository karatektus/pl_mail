<?php

declare(strict_types=1);

namespace App\Domain\Enum\Crypto;

/**
 * What asking the Web Key Directory for one's own address from outside found,
 * against the key plMail would publish for it. See WkdDirectory::check().
 *
 * Current: the key found is the person's current one. Outdated: a key was
 * found, but not that one — another server answers for the domain, or a cache
 * on the way still holds the old key. Missing: nothing usable was found.
 */
enum WkdCheckResult: string
{
    case Current  = 'current';
    case Outdated = 'outdated';
    case Missing  = 'missing';

    public function translationKey(): string
    {
        return match ($this) {
            self::Current  => 'encryption.wkd.check.current',
            self::Outdated => 'encryption.wkd.check.outdated',
            self::Missing  => 'encryption.wkd.check.missing',
        };
    }
}
