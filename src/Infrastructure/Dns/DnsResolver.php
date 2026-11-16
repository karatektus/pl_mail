<?php

declare(strict_types=1);

namespace App\Infrastructure\Dns;

/**
 * TXT and SRV lookups, for the sender picture sources that are published in
 * DNS (#639): a BIMI record, a DMARC policy, a Libravatar server.
 *
 * A class rather than `dns_get_record()` at each call site so a test can say
 * what the DNS answers without a network — every lookup that reads DNS would
 * otherwise be untestable or flaky.
 *
 * `dns_get_record()` answers false and raises a warning on SERVFAIL and on a
 * timeout, and an empty list on NXDOMAIN. Both are folded into an empty answer
 * here: for every caller, a domain whose DNS cannot be read publishes nothing
 * they can use, and the warning would be one more line in the log per
 * stranger with broken DNS.
 */
class DnsResolver
{
    /** @return list<string> each TXT record, its strings joined as RFC 7208 says */
    public function txt(string $name): array
    {
        $records = @dns_get_record($name, DNS_TXT);

        if (false === is_array($records)) {
            return [];
        }

        $texts = [];

        foreach ($records as $record) {
            if (true === isset($record['entries']) && true === is_array($record['entries'])) {
                $texts[] = implode('', $record['entries']);
            } elseif (true === isset($record['txt'])) {
                $texts[] = (string) $record['txt'];
            }
        }

        return $texts;
    }

    /**
     * @return list<array{target: string, port: int, priority: int, weight: int}> best first
     */
    public function srv(string $name): array
    {
        $records = @dns_get_record($name, DNS_SRV);

        if (false === is_array($records)) {
            return [];
        }

        $services = [];

        foreach ($records as $record) {
            $services[] = [
                'target'   => rtrim((string) ($record['target'] ?? ''), '.'),
                'port'     => (int) ($record['port'] ?? 0),
                'priority' => (int) ($record['pri'] ?? 0),
                'weight'   => (int) ($record['weight'] ?? 0),
            ];
        }

        // Lowest priority first, heaviest weight within one: the order RFC
        // 2782 means, without its random weighting — one picture lookup is
        // not load worth spreading.
        usort($services, static fn (array $a, array $b): int => [$a['priority'], -$a['weight']] <=> [$b['priority'], -$b['weight']]);

        return $services;
    }
}
