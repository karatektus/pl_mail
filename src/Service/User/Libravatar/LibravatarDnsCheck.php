<?php

declare(strict_types=1);

namespace App\Service\User\Libravatar;

use App\Domain\Enum\User\LibravatarDnsStatus;
use App\Domain\Helper\PersonalMailDomains;
use App\Infrastructure\Dns\DnsResolver;

/**
 * Checks, per domain, whether Libravatar clients will find this server
 * (#642): the "Check DNS" button on the admin card.
 *
 * A client looks up `_avatars-sec._tcp.<domain>` and fetches from whatever
 * host and port the record names, over https. So the record has to name the
 * host plMail is reached at from outside — the one Admin → Address holds —
 * on 443. The check says which of those is not yet true, in the terms of
 * LibravatarDnsStatus.
 *
 * Only the secure record is checked. The plain `_avatars._tcp` one is for
 * http, which this route is never meant to be reached over.
 */
final readonly class LibravatarDnsCheck
{
    public function __construct(
        private DnsResolver $dns,
    ) {}

    /** The name of the record a domain needs. */
    public static function recordName(string $domain): string
    {
        return '_avatars-sec._tcp.' . $domain;
    }

    /**
     * @return array{status: LibravatarDnsStatus, found: ?string} found is the
     *         record as published, "host:port", when there is one
     */
    public function check(string $domain, ?string $host): array
    {
        if (true === PersonalMailDomains::contains($domain)) {
            return ['status' => LibravatarDnsStatus::NotYours, 'found' => null];
        }

        $records = $this->dns->srv(self::recordName($domain));

        if ([] === $records) {
            return ['status' => LibravatarDnsStatus::Missing, 'found' => null];
        }

        $best  = $records[0];
        $found = sprintf('%s:%d', $best['target'], $best['port']);

        if (null === $host || 0 !== strcasecmp(rtrim($best['target'], '.'), $host)) {
            return ['status' => LibravatarDnsStatus::Elsewhere, 'found' => $found];
        }

        if (443 !== $best['port']) {
            return ['status' => LibravatarDnsStatus::WrongPort, 'found' => $found];
        }

        return ['status' => LibravatarDnsStatus::Ok, 'found' => $found];
    }
}
