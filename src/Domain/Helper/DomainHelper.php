<?php

declare(strict_types=1);

namespace App\Domain\Helper;

/**
 * Registrable-domain arithmetic, used wherever "is this the same organisation"
 * has to be answered from a hostname.
 *
 * This is an APPROXIMATION of the Public Suffix List, and deliberately so. The
 * real PSL is ~9 000 rules that need shipping, updating and a lookup structure;
 * the two callers here — the phishing heuristic and the image proxy — both fail
 * safe when it is wrong. Getting `co.uk` wrong makes the heuristic compare
 * `bbc.co.uk` against `co.uk` and stay quiet, which is the direction an
 * approximation is allowed to err in: a missed warning, never a fabricated one.
 *
 * The list below is the second-level suffixes that actually turn up in mail —
 * the ccTLD registries that never sell at the second level. Anything not on it
 * is treated as "the last two labels are the registrable domain", which is
 * correct for every gTLD and for the ccTLDs that do sell at the second level.
 */
final readonly class DomainHelper
{
    /**
     * Second-level public suffixes. A host ending in one of these needs THREE
     * labels to name an organisation, not two.
     *
     * @var list<string>
     */
    private const array MULTI_LABEL_SUFFIXES = [
        'co.uk', 'org.uk', 'me.uk', 'ltd.uk', 'plc.uk', 'net.uk', 'sch.uk', 'ac.uk', 'gov.uk',
        'com.au', 'net.au', 'org.au', 'edu.au', 'gov.au', 'id.au',
        'co.nz', 'net.nz', 'org.nz', 'govt.nz', 'ac.nz',
        'co.za', 'org.za', 'net.za', 'gov.za', 'ac.za',
        'com.br', 'net.br', 'org.br', 'gov.br',
        'co.jp', 'or.jp', 'ne.jp', 'ac.jp', 'go.jp',
        'co.kr', 'or.kr', 'ne.kr', 'go.kr',
        'com.cn', 'net.cn', 'org.cn', 'gov.cn', 'edu.cn',
        'com.mx', 'com.ar', 'com.tr', 'com.sg', 'com.hk', 'com.tw', 'com.my',
        'co.in', 'net.in', 'org.in', 'gov.in',
        'com.pl', 'net.pl', 'org.pl',
        'co.il', 'org.il', 'net.il', 'ac.il', 'gov.il',
    ];

    /**
     * The registrable domain (eTLD+1) of a host, lowercased.
     *
     * Null when the input is not a hostname with at least two labels — an IP
     * literal, a bare label, or nothing. Callers treat null as "cannot compare",
     * not as "no match".
     */
    public static function registrable(?string $host): ?string
    {
        $host = strtolower(trim((string) $host, " \t\n\r\0\x0B."));

        if ('' === $host) {
            return null;
        }

        // An IP literal names no organisation, and `1.2.3.4` would otherwise
        // come back as the registrable domain "3.4".
        if (false !== filter_var($host, FILTER_VALIDATE_IP)) {
            return null;
        }

        // Punycode in, punycode out: comparing an IDN against its A-label form
        // is the caller's problem, and both sides of every comparison here come
        // from the same kind of source.
        $labels = explode('.', $host);

        if (count($labels) < 2) {
            return null;
        }

        $lastTwo = implode('.', array_slice($labels, -2));

        if (true === in_array($lastTwo, self::MULTI_LABEL_SUFFIXES, true)) {
            return count($labels) >= 3
                ? implode('.', array_slice($labels, -3))
                : null;
        }

        return $lastTwo;
    }

    /**
     * The registrable domain of an email address's host part.
     */
    public static function registrableOfAddress(?string $address): ?string
    {
        $address = (string) $address;
        $at      = strrpos($address, '@');

        if (false === $at) {
            return null;
        }

        return self::registrable(substr($address, $at + 1));
    }
}
