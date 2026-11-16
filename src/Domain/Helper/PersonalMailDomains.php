<?php

declare(strict_types=1);

namespace App\Domain\Helper;

/**
 * Mail providers where an address belongs to a person, not to an organisation.
 *
 * Two readers, one list. ContactTextParser files an address at one of these as
 * a home address rather than a work one. The website icon lookup (#639) skips
 * them outright, because the website behind `gmail.com` is Google's, and a
 * Google logo on every row from somebody with a Gmail address would say the
 * opposite of what the row means.
 *
 * Moved here from ContactTextParser, where it was private, when the second
 * reader appeared: two copies of a list like this drift the first time
 * somebody adds a provider to one of them.
 *
 * Short and European on purpose — it is the providers plMail's users are
 * actually mailed from, not an attempt at a complete registry. A provider
 * missing here costs one wrong icon for its users, which is a smaller harm
 * than a list nobody can review.
 */
final class PersonalMailDomains
{
    /** @var list<string> */
    public const array DOMAINS = [
        'gmail.com', 'googlemail.com', 'gmx.de', 'gmx.net', 'gmx.at', 'gmx.ch', 'web.de', 't-online.de',
        'outlook.com', 'outlook.de', 'hotmail.com', 'hotmail.de', 'live.com', 'icloud.com', 'me.com', 'mac.com',
        'yahoo.com', 'yahoo.de', 'posteo.de', 'posteo.net', 'mailbox.org', 'proton.me', 'protonmail.com',
        'freenet.de', 'arcor.de', 'aol.com', 'orange.fr', 'free.fr', 'laposte.net',
    ];

    public static function contains(?string $domain): bool
    {
        return true === in_array(strtolower(trim((string) $domain)), self::DOMAINS, true);
    }
}
