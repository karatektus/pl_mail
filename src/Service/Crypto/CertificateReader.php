<?php

declare(strict_types=1);

namespace App\Service\Crypto;

use App\Domain\DTO\Crypto\CertificateInfo;
use DateTimeImmutable;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reads X.509 certificates with OpenSSL, and asks it whether one chains to a
 * certificate authority the system trusts.
 *
 * The one place that knows where those authorities are. SignatureVerifier
 * checks signed mail against them, and ContactCertificates decides with them
 * whether a contact's certificate is vouched for or merely seen — and both
 * must mean the same set, or a signature could be Valid while the certificate
 * that made it counts as unconfirmed. The set is the system's bundle
 * (openssl_get_cert_locations()), which in the container is Debian's
 * ca-certificates; SMIME_CA_FILE adds a file of further authorities for an
 * install with a private CA, and is how the tests bring their own.
 */
final readonly class CertificateReader
{
    /** A certificate with its chain is a few kilobytes; anything near this is not one. */
    public const int MAX_BYTES = 64 * 1024;

    /** keyUsage bits OpenSSL reports, in the words it uses for them. */
    private const array ENCRYPTING_USAGES = ['key encipherment'];

    public function __construct(
        #[Autowire('%env(default::SMIME_CA_FILE)%')]
        private ?string $extraAuthorities = null,
    ) {
    }

    /**
     * The first certificate in $pem, or null when there is none OpenSSL can
     * read.
     */
    public function read(string $pem): ?CertificateInfo
    {
        $pem = self::firstPem($pem);

        if (null === $pem) {
            return null;
        }

        $parsed      = @openssl_x509_parse($pem);
        $fingerprint = @openssl_x509_fingerprint($pem, 'sha256');
        $publicKey   = @openssl_pkey_get_public($pem);

        if (false === $parsed || false === $fingerprint || false === $publicKey) {
            return null;
        }

        $details = openssl_pkey_get_details($publicKey);
        $usage   = strtolower((string) ($parsed['extensions']['keyUsage'] ?? ''));

        return new CertificateInfo(
            pem:         $pem,
            fingerprint: strtolower($fingerprint),
            addresses:   self::addresses($parsed),
            subject:     self::name($parsed['subject'] ?? []),
            issuer:      self::name($parsed['issuer'] ?? []),
            notBefore:   new DateTimeImmutable('@' . (int) ($parsed['validFrom_time_t'] ?? 0)),
            notAfter:    new DateTimeImmutable('@' . (int) ($parsed['validTo_time_t'] ?? 0)),
            selfSigned:  ($parsed['subject'] ?? null) === ($parsed['issuer'] ?? null),
            rsa:         false !== $details && OPENSSL_KEYTYPE_RSA === ($details['type'] ?? null),
            // No keyUsage at all means no restriction (RFC 5280 §4.2.1.3).
            canEncrypt:  '' === $usage || [] !== array_filter(self::ENCRYPTING_USAGES, static fn (string $bit): bool => str_contains($usage, $bit)),
        );
    }

    /**
     * Every certificate in a PEM bundle, in order.
     *
     * @return list<string>
     */
    public static function splitPem(string $pem): array
    {
        preg_match_all('/-----BEGIN CERTIFICATE-----[A-Za-z0-9+\/=\s]+-----END CERTIFICATE-----/', $pem, $matches);

        return array_values(array_map(static fn (string $block): string => $block . "\n", $matches[0]));
    }

    /**
     * Whether $pem chains, through the certificates in $chain if it needs
     * them, to an authority the system trusts, and may be used for S/MIME.
     *
     * Not a revocation check: OCSP and CRLs are left out (see
     * docs/features/encryption.md), so a revoked certificate still passes
     * here until it expires.
     */
    public function chainsToAuthority(string $pem, string $chain = ''): bool
    {
        $authorities = $this->authorities();

        if ([] === $authorities || null === self::firstPem($pem)) {
            return false;
        }

        $untrusted = null;

        if ([] !== self::splitPem($chain)) {
            $untrusted = (string) tempnam(sys_get_temp_dir(), 'plmail-chain');
            file_put_contents($untrusted, implode('', self::splitPem($chain)));
        }

        try {
            // X509_PURPOSE_ANY: the purpose an S/MIME certificate is for —
            // signing, encrypting or both — is the caller's question, asked
            // of keyUsage; this one is only about who vouches for it.
            $result = null === $untrusted
                ? @openssl_x509_checkpurpose($pem, X509_PURPOSE_ANY, $authorities)
                : @openssl_x509_checkpurpose($pem, X509_PURPOSE_ANY, $authorities, $untrusted);

            return true === $result;
        } finally {
            if (null !== $untrusted) {
                @unlink($untrusted);
            }
        }
    }

    /**
     * The trusted authorities, as OpenSSL takes them: files and directories.
     *
     * @return list<string>
     */
    public function authorities(): array
    {
        $locations   = openssl_get_cert_locations();
        $authorities = [];

        foreach ([$locations['default_cert_file'] ?? '', $locations['default_cert_dir'] ?? '', (string) $this->extraAuthorities] as $location) {
            if ('' !== $location && (true === is_file($location) || true === is_dir($location))) {
                $authorities[] = $location;
            }
        }

        return $authorities;
    }

    /**
     * The addresses a certificate is for, lower-cased: its subjectAltName
     * emails, and the old emailAddress in the subject.
     *
     * @param array<string, mixed> $parsed what openssl_x509_parse() returned
     *
     * @return list<string>
     */
    public static function addresses(array $parsed): array
    {
        $addresses = [];

        foreach (explode(',', (string) ($parsed['extensions']['subjectAltName'] ?? '')) as $name) {
            if (1 === preg_match('/^\s*email:(.+)$/i', $name, $match)) {
                $addresses[] = strtolower(trim($match[1]));
            }
        }

        foreach ((array) ($parsed['subject']['emailAddress'] ?? []) as $address) {
            $addresses[] = strtolower(trim((string) $address));
        }

        return array_values(array_unique(array_filter($addresses, static fn (string $address): bool => false !== filter_var($address, FILTER_VALIDATE_EMAIL))));
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private static function firstPem(string $pem): ?string
    {
        if (strlen($pem) > self::MAX_BYTES) {
            return null;
        }

        return self::splitPem($pem)[0] ?? null;
    }

    /**
     * A distinguished name the way a person reads it: the common name, or the
     * organisation, or the address — whichever it has first.
     *
     * @param array<string, mixed> $name
     */
    private static function name(array $name): string
    {
        foreach (['CN', 'O', 'emailAddress'] as $field) {
            $value = $name[$field] ?? null;

            if (is_array($value)) {
                $value = $value[0] ?? null;
            }

            if (is_string($value) && '' !== trim($value)) {
                return mb_substr(trim($value), 0, 255);
            }
        }

        return '';
    }
}
