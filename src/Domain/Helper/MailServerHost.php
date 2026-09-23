<?php

declare(strict_types=1);

namespace App\Domain\Helper;

/**
 * What an IMAP or SMTP host field may contain: a hostname or an IP address.
 *
 * The host is spliced into strings that other parsers read — the mailer DSN
 * `smtp://user:pass@HOST:port`, and the IMAP library's socket address — so a
 * value carrying `/`, `?`, `#` or `@` is not a strange hostname, it is a second
 * URL component. `mail.example.com?verify_peer=0` in the SMTP host field
 * switched certificate checking off for that account, and everything after it
 * would have been read as options too.
 */
final class MailServerHost
{
    /** RFC 1123 labels, dot-separated, an optional trailing dot. */
    private const string HOSTNAME = '/^(?=.{1,253}\.?$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.?$/iD';

    public static function isValid(?string $host): bool
    {
        if (null === $host || '' === $host) {
            return false;
        }

        $bare = str_starts_with($host, '[') && str_ends_with($host, ']') ? substr($host, 1, -1) : $host;

        if (false !== filter_var($bare, FILTER_VALIDATE_IP)) {
            return true;
        }

        return 1 === preg_match(self::HOSTNAME, $host);
    }

    /** The host as it goes into a URL authority: IPv6 in brackets, the rest as is. */
    public static function forAuthority(string $host): string
    {
        $bare = trim($host, '[]');

        return false !== filter_var($bare, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? '[' . $bare . ']' : $host;
    }
}
