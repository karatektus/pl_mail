<?php

declare(strict_types=1);

namespace App\Domain\Enum\User;

/**
 * What a domain's DNS says about serving its Libravatar pictures from here
 * (#642) — one per row of the admin card's DNS check.
 *
 * Each case names what to DO, which is the only reason the card shows it: a
 * record that is missing wants adding, one pointing elsewhere wants changing
 * or is deliberate, a wrong port wants its port fixed, and a mail provider's
 * domain wants nothing, because its DNS is not the administrator's.
 */
enum LibravatarDnsStatus: string
{
    /** The SRV record names this server's public host on port 443. */
    case Ok = 'ok';

    /** No `_avatars-sec._tcp` record at all. */
    case Missing = 'missing';

    /** A record that names another host — another avatar server, or a typo. */
    case Elsewhere = 'elsewhere';

    /** This host, but not on 443, the only port the route is served on. */
    case WrongPort = 'wrong_port';

    /** Gmail and the like: the administrator cannot publish records there. */
    case NotYours = 'not_yours';

    /** Whether the row needs nothing more done. */
    public function isFine(): bool
    {
        return match ($this) {
            self::Ok                                                        => true,
            self::Missing, self::Elsewhere, self::WrongPort, self::NotYours => false,
        };
    }
}
