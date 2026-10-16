<?php

declare(strict_types=1);

namespace App\Service\Crypto;

use App\Domain\DTO\Crypto\KeyFetch;
use App\Domain\DTO\Crypto\PublicKeyInfo;
use App\Domain\Exception\ContactKeyRefused;
use App\Entity\User\User;

/**
 * Asks a recipient's own domain for their key: the OpenPGP Web Key Directory
 * (draft-koch-openpgp-webkey-service), on the browser's behalf.
 *
 * On the server because the page may only talk to its own origin
 * (`connect-src 'self'`), which is also what keeps a person's contacts from
 * learning their IP address from a lookup. That makes this an endpoint that
 * fetches URLs derived from user input — the shape of every SSRF — so the
 * URL is never the user's. It is built here, in one of exactly two shapes,
 * from an address that has passed validation:
 *
 *     https://openpgpkey.<domain>/.well-known/openpgpkey/<domain>/hu/<hash>?l=<local>   (advanced)
 *     https://<domain>/.well-known/openpgpkey/hu/<hash>?l=<local>                       (direct)
 *
 * and the request is held to the rules every lookup that leaves the server
 * is held to — HTTPS only, no redirects, private addresses refused and the
 * checked one pinned, a timeout and a size limit, a cache and a per-person
 * rate limit — which live in KeyFetcher, shared with the key servers.
 *
 * Only keys with a user ID for the very address asked about are returned. A
 * domain that serves somebody else's key under an address's hash is serving
 * the wrong key, whatever its reason.
 */
final readonly class WkdLookup
{
    /** Z-Base-32, as WKD hashes local parts with it (RFC 6189 §5.1.6). */
    private const string ZBASE32 = 'ybndrfg8ejkmcpqxot1uwisza345h769';

    /** A WKD answer is the binary key; some servers send it armored. */
    private const string ACCEPT = 'application/octet-stream, application/pgp-keys';

    public function __construct(
        private KeyFetcher      $fetcher,
        private PublicKeyReader $reader,
    ) {}

    /**
     * The two URLs to ask, in order, or null for an address that is not one
     * WKD can be asked about.
     *
     * @return array{advanced: string, direct: string}|null
     */
    public static function urls(string $email): ?array
    {
        $at = strrpos($email, '@');

        if (false === $at) {
            return null;
        }

        $local  = substr($email, 0, $at);
        $domain = self::domain(substr($email, $at + 1));

        if (null === $domain || '' === $local || 64 < strlen($local) || 1 === preg_match('/[\x00-\x20\x7f]/', $local)) {
            return null;
        }

        $hash  = self::hash($local);
        $query = '?l=' . rawurlencode($local);

        return [
            'advanced' => sprintf('https://openpgpkey.%s/.well-known/openpgpkey/%s/hu/%s%s', $domain, $domain, $hash, $query),
            'direct'   => sprintf('https://%s/.well-known/openpgpkey/hu/%s%s', $domain, $hash, $query),
        ];
    }

    /**
     * The name a local part goes by in a Web Key Directory: the Z-Base-32 of
     * the SHA-1 of the local part, lower-cased (ASCII only, as the draft
     * says — which is what strtolower() does since PHP 8.2). Public because
     * plMail's own directory (WkdDirectory) has to find an address by the
     * same name, and two copies of this are two chances to disagree.
     */
    public static function hash(string $local): string
    {
        return self::zbase32(sha1(strtolower($local), true));
    }

    /**
     * Lower-case, IDNA-encoded, and shaped like a public DNS name with at
     * least two labels — not an IP literal, not `localhost`, not a bare
     * container name. Public for WkdDirectory, which holds the installation's
     * own domains to the same shape before it serves anything under them.
     */
    public static function domain(string $domain): ?string
    {
        $domain = strtolower(trim($domain));
        $ascii  = '' === $domain ? false : idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

        if (false === $ascii || 253 < strlen($ascii)) {
            return null;
        }

        if (1 !== preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $ascii)) {
            return null;
        }

        return $ascii;
    }

    /**
     * The usable keys the address's domain publishes for it, if any.
     *
     * @param bool $fresh ask again even if an answer is cached — for a person
     *                    checking their own address a minute after switching
     *                    it on, to whom yesterday's "not found" is no answer
     *
     * @return list<PublicKeyInfo>
     */
    public function find(User $user, string $email, bool $fresh = false): array
    {
        $email = strtolower(trim($email));
        $urls  = self::urls($email);

        if (null === $urls || false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ContactKeyRefused('contact_keys.lookup.invalid_address');
        }

        // One cache entry and one counted lookup for both methods: they are
        // two places on the same domain to ask the same question.
        $body = $this->fetcher->cached($user, self::cacheKey($email), fn (): ?string => $this->fetcher->get($urls['advanced'], self::ACCEPT) ?? $this->fetcher->get($urls['direct'], self::ACCEPT), $fresh);

        if (null === $body) {
            return [];
        }

        return array_values(array_filter(
            $this->reader->read($body),
            static fn (PublicKeyInfo $key): bool => $key->usableFor($email),
        ));
    }

    /**
     * Both methods asked on their own, past the cache, and what each brought
     * back — for a person checking their own address from outside
     * (WkdDirectory::check()), not for finding a key.
     *
     * find() asks the advanced URL and falls back to the direct one only when
     * the advanced one has nothing, as a mail program does. That is the right
     * order for a lookup and the wrong one for a check: when openpgpkey.<domain>
     * answers at all, the direct method is never tried, so a broken forward
     * from the website goes unnoticed until the subdomain goes away (#28,
     * comment 6216080577). One counted request for the pair, as for find(),
     * and the cached find() answer is dropped, so the next lookup sees what
     * the check saw.
     *
     * @return array{advanced: KeyFetch, direct: KeyFetch}
     *
     * @throws ContactKeyRefused for an address WKD cannot be asked about, or
     *                           when the person has used up their lookups
     */
    public function check(User $user, string $email): array
    {
        $email = strtolower(trim($email));
        $urls  = self::urls($email);

        if (null === $urls || false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ContactKeyRefused('contact_keys.lookup.invalid_address');
        }

        $this->fetcher->spend($user);
        $this->fetcher->forget(self::cacheKey($email));

        return [
            'advanced' => $this->fetcher->probe($urls['advanced'], self::ACCEPT),
            'direct'   => $this->fetcher->probe($urls['direct'], self::ACCEPT),
        ];
    }

        // ── Private ───────────────────────────────────────────────────────────────

    private static function cacheKey(string $email): string
    {
        return 'wkd.' . sha1($email);
    }


    private static function zbase32(string $bytes): string
    {
        $bits = '';

        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';

        foreach (str_split($bits, 5) as $chunk) {
            $encoded .= self::ZBASE32[(int) bindec(str_pad($chunk, 5, '0'))];
        }

        return $encoded;
    }
}
