<?php

declare(strict_types=1);

namespace App\Service\Demo;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Turns a visitor's address into something that can be counted and cannot be
 * turned back into the address.
 *
 * Two steps, and the order is the point.
 *
 * The address is cut down first: the last octet of an IPv4 address and the last
 * eighty bits of an IPv6 one are zeroed, which leaves the network a visitor came
 * from and not the visitor. Eighty rather than the sixty-four IpUtils::anonymize
 * removes by default: a /64 is commonly one household's own prefix, and a /48
 * is the provider's allocation it was cut from. Only then is it hashed, with
 * the instance's secret as the key.
 *
 * Hashing alone would not do. There are four billion IPv4 addresses, and
 * anybody holding the key can hash all of them in an afternoon and read the
 * table back; a keyed hash of a whole address is the address under another
 * name. Cutting first means the most that can ever be recovered — by the
 * operator, with the key, trying — is a /24, which is what the address was
 * reduced to before anything was stored.
 *
 * The cost is that two people behind the same network count as one visitor.
 * For "roughly how many different people looked at the demo" that is a small
 * undercount, and it is the right direction to be wrong in.
 */
final readonly class DemoVisitorFingerprint
{
    /** Bytes zeroed at the end of an address before it is hashed: a /24 and a /48 remain. */
    private const int IPV4_BYTES_REMOVED = 1;
    private const int IPV6_BYTES_REMOVED = 10;

    public function __construct(
        #[Autowire('%kernel.secret%')]
        private string $secret,
    ) {
    }

    /**
     * Null for a request without an address, and for anything that is not one:
     * a visit nobody can be told apart by is counted as a visit and left out of
     * the unique visitors.
     */
    public function of(?string $clientIp): ?string
    {
        if (null === $clientIp || false === filter_var($clientIp, FILTER_VALIDATE_IP)) {
            return null;
        }

        return hash_hmac('sha256', IpUtils::anonymize($clientIp, self::IPV4_BYTES_REMOVED, self::IPV6_BYTES_REMOVED), $this->secret);
    }
}
