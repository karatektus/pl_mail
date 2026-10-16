<?php

declare(strict_types=1);

namespace App\Domain\Enum\Crypto;

/**
 * What asking one Web Key Directory method for one's own address from
 * outside found, against the key plMail would publish for it. See
 * WkdDirectory::check(), which asks each method on its own.
 *
 * Current: the key found is the person's current one. Outdated: a key was
 * found, but not that one — another server answers for the domain, or a cache
 * on the way still holds the old key. Missing: the host answered, with
 * nothing usable. Unreachable: no answer at all — the name does not resolve,
 * or resolves to a private address, or the connection or its certificate
 * failed — which for the advanced method usually means openpgpkey.<domain>
 * was never set up, and for the direct one that the website is down.
 */
enum WkdCheckResult: string
{
    case Current     = 'current';
    case Outdated    = 'outdated';
    case Missing     = 'missing';
    case Unreachable = 'unreachable';

    public function translationKey(): string
    {
        return 'encryption.wkd.check.result.' . $this->value;
    }

    /** Whether a mail program asking this method gets the right key. */
    public function isGood(): bool
    {
        return self::Current === $this;
    }
}
