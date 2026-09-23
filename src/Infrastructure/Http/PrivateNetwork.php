<?php

declare(strict_types=1);

namespace App\Infrastructure\Http;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Whether a host a user named lives on a network they must not reach through us.
 *
 * Decided by what the host RESOLVES to, not by what it looks like. The string
 * checks this replaces let through everything that is not a dotted quad in a
 * listed range: container names (`database`, `mercure`, `ollama`), wildcard DNS
 * such as `127.0.0.1.nip.io`, and the spellings libc still accepts as IPv4 —
 * `127.1`, `2130706433` — none of which a regex over the host sees as loopback.
 * Resolving answers all of them at once, because the resolver is what the HTTP
 * client will ask too.
 *
 * The ranges are {@see IpUtils::PRIVATE_SUBNETS}, which is also what
 * NoPrivateNetworkHttpClient enforces on every connection: one list, so the
 * check at save time and the check at connect time cannot disagree. It covers
 * IPv4-mapped IPv6 (`::ffff:169.254.169.254`) and the unspecified `::`.
 *
 * This is the early, friendly check that turns a bad address into a form
 * error. It is not the defence against DNS rebinding — a name can resolve
 * differently a second later — which is why the requests themselves go through
 * {@see UserUrlHttpClient}.
 */
final class PrivateNetwork
{
    public static function isPrivateHost(string $host): bool
    {
        $bare = strtolower(trim($host, '[]'));

        // Checked by name as well: a resolver without a hosts entry would
        // otherwise answer nothing for it, and nothing is not "public".
        if ('localhost' === $bare || str_ends_with($bare, '.localhost')) {
            return true;
        }

        foreach (self::resolve($bare) as $ip) {
            if (true === IpUtils::isPrivateIp($ip)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every address the host resolves to, IPv4 and IPv6.
     *
     * Both families, because a host with a public A record and a private AAAA
     * record is exactly the shape an attacker would register. A name that does
     * not resolve returns nothing: the request would fail anyway, and the
     * client's own check applies when it does resolve.
     *
     * @return list<string>
     */
    public static function resolve(string $host): array
    {
        $bare = trim($host, '[]');

        if (false !== filter_var($bare, FILTER_VALIDATE_IP)) {
            return [$bare];
        }

        if ('' === $bare) {
            return [];
        }

        $ips = gethostbynamel($bare);
        $ips = false === $ips ? [] : $ips;

        // Numeric shorthands (`127.1`) are answered by gethostbynamel alone;
        // asking DNS for their AAAA record would only be a wasted query.
        if (1 !== preg_match('/^[0-9.]+$/', $bare)) {
            $records = @dns_get_record($bare, DNS_AAAA);

            foreach (false === $records ? [] : $records as $record) {
                if (true === isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($ips));
    }
}
