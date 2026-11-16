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
 * A third reader is the Libravatar card (#642): an administrator is offered a
 * DNS record for every domain their users have addresses at, except these,
 * whose DNS belongs to the provider. It showed records for gmail.com and
 * fastmail.com "exactly as shown", which nobody but Google and Fastmail can
 * add.
 *
 * Not an attempt at a complete registry — the providers plMail's users are
 * actually mailed from, in the languages it speaks, grouped so a reviewer
 * can see what is there. A provider missing here costs a wrong icon, a
 * contact filed as work, or a record offered that cannot be added; each is
 * smaller than a list nobody can review. A custom domain hosted at one of
 * these providers is not on it, and rightly: that domain's DNS is its
 * owner's.
 */
final class PersonalMailDomains
{
    /** @var list<string> */
    public const array DOMAINS = [
        // Google, Microsoft, Apple, Yahoo, AOL
        'gmail.com', 'googlemail.com',
        'outlook.com', 'outlook.de', 'outlook.fr', 'hotmail.com', 'hotmail.de', 'hotmail.fr', 'hotmail.co.uk',
        'live.com', 'live.de', 'live.fr', 'msn.com',
        'icloud.com', 'me.com', 'mac.com',
        'yahoo.com', 'yahoo.de', 'yahoo.fr', 'yahoo.co.uk', 'ymail.com', 'aol.com', 'aol.de',
        // Privacy-minded providers
        'proton.me', 'protonmail.com', 'protonmail.ch', 'pm.me',
        'tutanota.com', 'tutanota.de', 'tuta.io', 'tuta.com',
        'posteo.de', 'posteo.net', 'mailbox.org', 'fastmail.com', 'fastmail.fm', 'hey.com',
        // German-speaking and French providers
        'gmx.de', 'gmx.net', 'gmx.at', 'gmx.ch', 'gmx.com', 'gmx.fr', 'web.de', 't-online.de', 'mail.de',
        'freenet.de', 'arcor.de', 'orange.fr', 'free.fr', 'laposte.net',
        // Elsewhere
        'mail.com', 'zoho.com', 'yandex.com', 'yandex.ru', 'seznam.cz', 'qq.com', '163.com', '126.com',
    ];

    public static function contains(?string $domain): bool
    {
        return true === in_array(strtolower(trim((string) $domain)), self::DOMAINS, true);
    }
}
