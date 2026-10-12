<?php

declare(strict_types=1);

namespace App\Service\Crypto;

use App\Domain\Enum\Crypto\MessageCrypto;
use App\Domain\Enum\Crypto\SignatureState;
use App\Entity\Mail\Message;
use App\Repository\Crypto\AddressKeyRepository;
use App\Service\Mail\RawMessageResolver;
use FilesystemIterator;
use Psr\Log\LoggerInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * Checks the signature on a signed message, on the server, from its raw source.
 *
 * Only public keys and certificates are involved, which is why this can happen
 * here at all — nothing the server holds opens anything. Encrypted messages
 * are not checked here: their signature is inside the ciphertext, and the
 * browser checks it after decrypting (assets/crypto/message.js).
 *
 * **S/MIME** goes through OpenSSL, twice if need be. Once against the system's
 * certificate authorities: a pass means the certificate is one a known
 * authority vouches for. If that fails, once more with the chain left out: a
 * pass then means the message is intact but nobody vouches for the signer —
 * a self-signed or private-CA certificate — which is Untrusted, not Invalid.
 *
 * **PGP** goes through ext-gnupg, in a GnuPG home made for this one check and
 * removed after it. One home per check rather than a shared keyring: workers
 * run side by side and the container under any uid, and the keys to check
 * against are whatever the database says now, not what a keyring remembers.
 * Until contacts' keys exist (phase 4 of issue #28) there are two sources: the
 * person's own address keys — a message they sent themselves is Valid — and an
 * Autocrypt header in the message itself, which proves the message is intact
 * but not who wrote it, and is Untrusted. Without either, UnknownKey, with the
 * signing key's fingerprint as the signer so it can be compared by hand.
 *
 * A signature naming somebody other than the sender is Invalid in both cases:
 * a valid signature from the wrong person is the forgery worth catching.
 */
final readonly class SignatureVerifier
{
    public function __construct(
        private RawMessageResolver   $raw,
        private AddressKeyRepository $addressKeys,
        private LoggerInterface      $logger,
    ) {
    }

    /**
     * @return array{state: SignatureState, signer: ?string}|null null when it
     *         could not be checked at all — no raw source, an unreadable
     *         structure, no ext-gnupg — and the message stays unchecked
     */
    public function verify(Message $message): ?array
    {
        $crypto = $message->crypto;

        if (null === $crypto || true === $crypto->isEncrypted()) {
            return null;
        }

        $path = $this->raw->absolutePathFor($message);

        if (null === $path || false === is_file($path)) {
            return null;
        }

        try {
            return match ($crypto) {
                MessageCrypto::SmimeSigned     => $this->smime($path, (string) $message->fromAddress),
                MessageCrypto::PgpSigned       => $this->pgpDetached($message, (string) file_get_contents($path)),
                MessageCrypto::PgpInlineSigned => $this->pgpInline($message),
                default                        => null,
            };
        } catch (Throwable $exception) {
            $this->logger->warning('Signature check failed to run: {error}', ['error' => $exception->getMessage(), 'messageId' => $message->id]);

            return null;
        }
    }

    // ── S/MIME ────────────────────────────────────────────────────────────────

    /**
     * @return array{state: SignatureState, signer: ?string}|null
     */
    private function smime(string $path, string $from): ?array
    {
        $signerFile = (string) tempnam(sys_get_temp_dir(), 'plmail-signer');

        try {
            $authorities = openssl_get_cert_locations()['default_cert_file'] ?? '';
            $trusted     = openssl_pkcs7_verify($path, 0, $signerFile, true === is_file($authorities) ? [$authorities] : []);

            if (true !== $trusted) {
                // Intact but unvouched-for, or not intact at all.
                $intact = openssl_pkcs7_verify($path, PKCS7_NOVERIFY, $signerFile);

                if (-1 === $intact) {
                    return null;
                }

                if (true !== $intact) {
                    return ['state' => SignatureState::Invalid, 'signer' => null];
                }
            }

            $addresses = self::certificateAddresses((string) @file_get_contents($signerFile));
            $signer    = $addresses[0] ?? null;

            if (false === in_array(strtolower($from), $addresses, true)) {
                return ['state' => SignatureState::Invalid, 'signer' => $signer];
            }

            return ['state' => true === $trusted ? SignatureState::Valid : SignatureState::Untrusted, 'signer' => $signer];
        } finally {
            @unlink($signerFile);
        }
    }

    /**
     * The addresses a certificate is for, lower-cased: its subjectAltName
     * emails, and the old emailAddress in the subject.
     *
     * @return list<string>
     */
    private static function certificateAddresses(string $pem): array
    {
        $parsed = '' === $pem ? false : openssl_x509_parse($pem);

        if (false === $parsed) {
            return [];
        }

        $addresses = [];

        foreach (explode(',', (string) ($parsed['extensions']['subjectAltName'] ?? '')) as $name) {
            if (1 === preg_match('/^\s*email:(.+)$/i', $name, $match)) {
                $addresses[] = strtolower(trim($match[1]));
            }
        }

        foreach ((array) ($parsed['subject']['emailAddress'] ?? []) as $address) {
            $addresses[] = strtolower(trim((string) $address));
        }

        return array_values(array_unique($addresses));
    }

    // ── PGP ───────────────────────────────────────────────────────────────────

    /**
     * @return array{state: SignatureState, signer: ?string}|null
     */
    private function pgpDetached(Message $message, string $raw): ?array
    {
        $parts = PgpMimeSplitter::split($raw);

        if (null === $parts) {
            return null;
        }

        return $this->pgp($message, static fn (\gnupg $gpg): array|false => $gpg->verify($parts['signed'], $parts['signature']));
    }

    /**
     * @return array{state: SignatureState, signer: ?string}|null
     */
    private function pgpInline(Message $message): ?array
    {
        $text = (string) $message->bodyText;
        $plain = '';

        return $this->pgp($message, static function (\gnupg $gpg) use ($text, &$plain): array|false {
            return $gpg->verify($text, false, $plain);
        });
    }

    /**
     * @param callable(\gnupg): (array<int, array<string, mixed>>|false) $check
     *
     * @return array{state: SignatureState, signer: ?string}|null
     */
    private function pgp(Message $message, callable $check): ?array
    {
        if (false === class_exists(\gnupg::class)) {
            $this->logger->notice('ext-gnupg is not installed; PGP signatures stay unchecked.');

            return null;
        }

        $home = sys_get_temp_dir() . '/plmail-gnupg-' . bin2hex(random_bytes(8));
        mkdir($home, 0700);

        try {
            $gpg = new \gnupg(['home_dir' => $home]);
            $gpg->seterrormode(\gnupg::ERROR_SILENT);

            $own       = $this->ownKeys($message);
            $autocrypt = self::autocryptKey($message);

            foreach ([...array_keys($own), ...(null === $autocrypt ? [] : [$autocrypt])] as $key) {
                $gpg->import($key);
            }

            $signatures = $check($gpg);

            if (false === $signatures || [] === $signatures) {
                return null;
            }

            $signature   = $signatures[0];
            $fingerprint = strtolower((string) ($signature['fingerprint'] ?? ''));
            $summary     = (int) ($signature['summary'] ?? 0);

            if (0 !== ($summary & \GNUPG_SIGSUM_KEY_MISSING)) {
                return ['state' => SignatureState::UnknownKey, 'signer' => '' === $fingerprint ? null : $fingerprint];
            }

            if (0 !== (int) ($signature['status'] ?? 1) || 0 !== ($summary & \GNUPG_SIGSUM_RED)) {
                return ['state' => SignatureState::Invalid, 'signer' => '' === $fingerprint ? null : $fingerprint];
            }

            // The key, by whichever of its fingerprints signed: a key often
            // signs with a subkey, and it is the primary fingerprint that the
            // keyring — and a person comparing by hand — knows it by. gpgme
            // lists the primary first among a key's subkeys.
            $signerKey = $gpg->keyinfo($fingerprint)[0] ?? null;
            $primary   = strtolower((string) ($signerKey['subkeys'][0]['fingerprint'] ?? $fingerprint));
            $addresses = array_map(
                static fn (array $uid): string => strtolower((string) ($uid['email'] ?? '')),
                (array) ($signerKey['uids'] ?? []),
            );

            if (false === in_array(strtolower((string) $message->fromAddress), $addresses, true)) {
                return ['state' => SignatureState::Invalid, 'signer' => $primary];
            }

            return [
                'state'  => true === in_array($primary, array_values($own), true) ? SignatureState::Valid : SignatureState::Untrusted,
                'signer' => $primary,
            ];
        } finally {
            self::removeTree($home);
        }
    }

    /**
     * The person's own public address keys, armored => fingerprint.
     *
     * @return array<string, string>
     */
    private function ownKeys(Message $message): array
    {
        $user = $message->account->usr;
        $keys = [];

        foreach ($this->addressKeys->findForUser($user) as $key) {
            $keys[$key->publicKey] = $key->fingerprint;
        }

        return $keys;
    }

    /**
     * The key an Autocrypt header carries (RFC-style `keydata=` base64), as
     * binary OpenPGP that gpg imports as it is.
     */
    private static function autocryptKey(Message $message): ?string
    {
        $header = $message->headers['autocrypt'] ?? null;
        $value  = is_array($header) ? (string) ($header[0] ?? '') : (string) $header;

        if (1 !== preg_match('/keydata=([A-Za-z0-9+\/=\s]+)/', $value, $match)) {
            return null;
        }

        $bytes = base64_decode((string) preg_replace('/\s+/', '', $match[1]), true);

        return false === $bytes || '' === $bytes ? null : $bytes;
    }

    private static function removeTree(string $path): void
    {
        if (false === is_dir($path)) {
            return;
        }

        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            true === $entry->isDir() && false === $entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }

        @rmdir($path);
    }
}
