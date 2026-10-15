<?php

declare(strict_types=1);

namespace App\Tests\Support\Crypto;

use OpenSSLAsymmetricKey;
use OpenSSLCertificate;
use RuntimeException;

/**
 * Real S/MIME certificates for tests, made by OpenSSL: a certificate
 * authority, certificates it issues, self-signed ones, PKCS#12 files, and
 * mail signed or encrypted with them.
 *
 * Real rather than checked-in fixtures for the reason Gpg gives: a fixture's
 * validity dates are a test that starts failing on a date. Expiry is tested
 * by moving the clock instead (Symfony's MockClock), which the services take.
 *
 * An authority's PEM can be handed to the app as SMIME_CA_FILE
 * (trustAuthority()), which is how a test makes it one "the system trusts" —
 * CertificateReader reads the variable when its container is built, so it is
 * set before the kernel boots and removed after.
 */
final class Smime
{
    public readonly string $certificate;
    public readonly string $fingerprint;

    private function __construct(
        public readonly string                $address,
        private readonly OpenSSLAsymmetricKey $key,
        private readonly OpenSSLCertificate   $x509,
    ) {
        openssl_x509_export($x509, $pem);
        $this->certificate = (string) $pem;
        $this->fingerprint = strtolower((string) openssl_x509_fingerprint($x509, 'sha256'));
    }

    /** A certificate authority, self-signed. */
    public static function authority(string $name = 'Test CA'): self
    {
        $key  = self::rsaKey();
        $csr  = openssl_csr_new(['commonName' => $name], $key, self::options('authority'));
        $cert = openssl_csr_sign($csr, null, $key, 30, self::options('authority'), random_int(1, PHP_INT_MAX));

        return new self('', $key, false === $cert ? throw new RuntimeException('no CA') : $cert);
    }

    /**
     * A certificate for $address: issued by $authority, or self-signed
     * without one. RSA unless $ec, which makes one plMail cannot encrypt to.
     */
    public static function issue(string $address, ?self $authority = null, bool $ec = false): self
    {
        $key = true === $ec
            ? openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'])
            : self::rsaKey();

        if (false === $key) {
            throw new RuntimeException('no key');
        }

        $options = self::options('person', $address);
        $csr     = openssl_csr_new(['commonName' => 'Person', 'emailAddress' => $address], $key, $options);
        $cert    = openssl_csr_sign($csr, $authority?->x509, null === $authority ? $key : $authority->key, 30, $options, random_int(1, PHP_INT_MAX));

        return new self($address, $key, false === $cert ? throw new RuntimeException('no certificate') : $cert);
    }

    /** Makes this authority one the app trusts, until untrust(). */
    public function trustAuthority(): void
    {
        $file = sys_get_temp_dir() . '/plmail-test-ca-' . bin2hex(random_bytes(4)) . '.pem';
        file_put_contents($file, $this->certificate);

        $_SERVER['SMIME_CA_FILE'] = $_ENV['SMIME_CA_FILE'] = $file;
        putenv('SMIME_CA_FILE=' . $file);
    }

    public static function untrust(): void
    {
        $file = $_SERVER['SMIME_CA_FILE'] ?? null;

        if (is_string($file) && is_file($file)) {
            @unlink($file);
        }

        unset($_SERVER['SMIME_CA_FILE'], $_ENV['SMIME_CA_FILE']);
        putenv('SMIME_CA_FILE');
    }

    public function pkcs12(string $password, ?self $authority = null): string
    {
        $extra = null === $authority ? [] : ['extracerts' => [$authority->certificate]];

        openssl_pkcs12_export($this->x509, $out, $this->key, $password, $extra);

        return (string) $out;
    }

    /** A whole message, From this address, signed the way Thunderbird does: multipart/signed. */
    public function signedMessage(string $body, ?string $from = null): string
    {
        $in  = (string) tempnam(sys_get_temp_dir(), 'smime-in');
        $out = (string) tempnam(sys_get_temp_dir(), 'smime-out');

        try {
            file_put_contents($in, "Content-Type: text/plain; charset=utf-8\r\n\r\n" . $body . "\r\n");
            openssl_pkcs7_sign($in, $out, $this->x509, $this->key, ['From' => $from ?? $this->address, 'Subject' => 'Signed'], PKCS7_DETACHED);

            return str_replace(["\r\n", "\n"], ["\n", "\r\n"], (string) file_get_contents($out));
        } finally {
            @unlink($in);
            @unlink($out);
        }
    }

    /**
     * $entity encrypted to every certificate given, as the entity the browser
     * hands over: Content-Type and the rest of its headers, a blank line, the
     * base64 body.
     *
     * @param list<self> $recipients
     */
    public static function envelope(string $entity, array $recipients): string
    {
        $in  = (string) tempnam(sys_get_temp_dir(), 'smime-in');
        $out = (string) tempnam(sys_get_temp_dir(), 'smime-out');

        try {
            file_put_contents($in, $entity);
            openssl_pkcs7_encrypt($in, $out, array_map(static fn (self $recipient): OpenSSLCertificate => $recipient->x509, $recipients), [], PKCS7_BINARY, OPENSSL_CIPHER_AES_256_CBC);

            // OpenSSL writes MIME-Version and the outer headers first; the
            // browser's entity starts at Content-Type.
            $written = str_replace(["\r\n", "\n"], ["\n", "\r\n"], (string) file_get_contents($out));

            return (string) preg_replace('/^MIME-Version:[^\r\n]*\r\n/i', '', $written);
        } finally {
            @unlink($in);
            @unlink($out);
        }
    }

    /**
     * The entity a browser hands over for a signed message: multipart/signed
     * from its Content-Type on, without the outer headers OpenSSL writes.
     */
    public function signedEntity(string $body): string
    {
        $raw = $this->signedMessage($body);

        return substr($raw, (int) stripos($raw, 'Content-Type:'));
    }

    /** Opens a whole message encrypted to this certificate, as a recipient would. */
    public function decrypt(string $raw): ?string
    {
        $in  = (string) tempnam(sys_get_temp_dir(), 'smime-in');
        $out = (string) tempnam(sys_get_temp_dir(), 'smime-out');

        try {
            file_put_contents($in, $raw);

            return true === openssl_pkcs7_decrypt($in, $out, $this->x509, $this->key) ? (string) file_get_contents($out) : null;
        } finally {
            @unlink($in);
            @unlink($out);
        }
    }

    // ── Private ───────────────────────────────────────────────────────────

    private static function rsaKey(): OpenSSLAsymmetricKey
    {
        return openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048])
            ?: throw new RuntimeException('no RSA key');
    }

    /**
     * The extensions a certificate gets, through a config file of their own:
     * PHP takes extensions only by section name.
     *
     * @return array<string, string>
     */
    private static function options(string $kind, string $address = ''): array
    {
        $file = sys_get_temp_dir() . '/plmail-test-openssl-' . md5($kind . $address) . '.cnf';

        file_put_contents($file, implode("\n", [
            '[req]',
            'distinguished_name = dn',
            '[dn]',
            '[authority]',
            'basicConstraints = critical,CA:TRUE',
            'keyUsage = critical,keyCertSign,cRLSign',
            'subjectKeyIdentifier = hash',
            '[person]',
            'basicConstraints = CA:FALSE',
            'keyUsage = critical,digitalSignature,keyEncipherment',
            'extendedKeyUsage = emailProtection',
            'subjectKeyIdentifier = hash',
            '' === $address ? '' : 'subjectAltName = email:' . $address,
            '',
        ]));

        return ['config' => $file, 'x509_extensions' => $kind, 'digest_alg' => 'sha256'];
    }
}
