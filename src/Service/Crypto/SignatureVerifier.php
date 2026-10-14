<?php

declare(strict_types=1);

namespace App\Service\Crypto;

use App\Domain\Enum\Crypto\MessageCrypto;
use App\Domain\Enum\Crypto\SignatureState;
use App\Entity\Mail\Message;
use App\Entity\Crypto\ContactKey;
use App\Repository\Crypto\AddressKeyRepository;
use App\Repository\Crypto\ContactKeyRepository;
use App\Service\Mail\RawMessageResolver;
use Closure;
use Psr\Log\LoggerInterface;
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
 * The certificate of a good signature by the sender is kept for them
 * (ContactCertificates): it is what mail back to them is encrypted to.
 *
 * **PGP** goes through the gpg binary, in a GnuPG home made for this one check
 * and removed after it (GnupgHome), and is read from its status lines. One home per check rather than a shared keyring: workers
 * run side by side and the container under any uid, and the keys to check
 * against are whatever the database says now, not what a keyring remembers.
 * Three sources of keys go in:
 *
 *   - the person's own address keys: a message they sent themselves is Valid;
 *   - the keys they hold for the sender's address (ContactKey): a pinned one
 *     makes the signature Valid, an unpinned Current one Untrusted — intact,
 *     but nobody confirmed the key — and a Changed one, or any key other than
 *     the Current one, KeyChanged;
 *   - an Autocrypt header in the message itself, which proves the message is
 *     intact but not who wrote it: Untrusted, or KeyChanged when the sender
 *     already has a different key.
 *
 * Without any of them, UnknownKey, with the signing key's fingerprint as the
 * signer so it can be compared by hand and looked up.
 *
 * A signature naming somebody other than the sender is Invalid in both cases:
 * a valid signature from the wrong person is the forgery worth catching.
 */
final readonly class SignatureVerifier
{
    public function __construct(
        private RawMessageResolver   $raw,
        private AddressKeyRepository $addressKeys,
        private ContactKeyRepository $contactKeys,
        private CertificateReader    $certificates,
        private ContactCertificates  $contactCertificates,
        private LoggerInterface      $logger,
    ) {
    }

    /**
     * @return array{state: SignatureState, signer: ?string}|null null when it
     *         could not be checked at all — no raw source, an unreadable
     *         structure, no gpg — and the message stays unchecked
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
                MessageCrypto::SmimeSigned     => $this->smime($message, $path),
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
    private function smime(Message $message, string $path): ?array
    {
        $from       = strtolower(trim((string) $message->fromAddress));
        $signerFile = (string) tempnam(sys_get_temp_dir(), 'plmail-signer');

        try {
            $trusted = openssl_pkcs7_verify($path, 0, $signerFile, $this->certificates->authorities());

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

            $pem       = (string) @file_get_contents($signerFile);
            $parsed    = '' === $pem ? false : openssl_x509_parse($pem);
            $addresses = false === $parsed ? [] : CertificateReader::addresses($parsed);
            $signer    = $addresses[0] ?? null;

            if (false === in_array($from, $addresses, true)) {
                return ['state' => SignatureState::Invalid, 'signer' => $signer];
            }

            // Kept only now, with the signature good and naming the sender:
            // anything less is a certificate anybody could have attached.
            $this->contactCertificates->record($message->account->usr, $from, $pem, true === $trusted);

            return ['state' => true === $trusted ? SignatureState::Valid : SignatureState::Untrusted, 'signer' => $signer];
        } finally {
            @unlink($signerFile);
        }
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

        return $this->pgp($message, static fn (GnupgHome $home): string => $home->output(
            null,
            '--status-fd',
            '1',
            '--verify',
            $home->file('signature.asc', $parts['signature']),
            $home->file('signed', $parts['signed']),
        ));
    }

    /**
     * @return array{state: SignatureState, signer: ?string}|null
     */
    private function pgpInline(Message $message): ?array
    {
        $text = (string) $message->bodyText;

        return $this->pgp($message, static fn (GnupgHome $home): string => $home->output(
            null,
            '--status-fd',
            '1',
            '--verify',
            $home->file('message.asc', $text),
        ));
    }

    /**
     * @param Closure(GnupgHome): string $check runs gpg --verify, and returns
     *                                          its status lines
     *
     * @return array{state: SignatureState, signer: ?string}|null
     */
    private function pgp(Message $message, Closure $check): ?array
    {
        if (false === GnupgHome::available()) {
            $this->logger->notice('gpg is not installed, or does not start; PGP signatures stay unchecked.');

            return null;
        }

        $from      = strtolower(trim((string) $message->fromAddress));
        $own       = $this->ownKeys($message);
        $contacts  = $this->contactKeys->findForAddress($message->account->usr, $from);
        $autocrypt = Autocrypt::keyFor($message->headers ?? [], $from);

        return GnupgHome::with(static function (GnupgHome $home) use ($check, $from, $own, $contacts, $autocrypt): ?array {
            $keys = [
                ...array_keys($own),
                ...array_map(static fn (ContactKey $key): string => $key->publicKey, $contacts),
                ...(null === $autocrypt ? [] : [$autocrypt]),
            ];

            // One file per key and one gpg for all of them: armored and binary
            // keys do not mix in one stream, and a key gpg cannot read only
            // costs itself, not the others.
            $files = [];

            foreach (array_values($keys) as $index => $key) {
                $files[] = $home->file('key-' . $index, $key);
            }

            if ([] !== $files) {
                $home->output(null, '--import', ...$files);
            }

            $signature = self::firstSignature($check($home));

            if (null === $signature) {
                return null;
            }

            $signer  = '' === $signature['fingerprint'] ? null : $signature['fingerprint'];
            $current = self::currentKey($contacts);

            if ('missing' === $signature['outcome']) {
                // None of the keys plMail has. When the sender has a known
                // key, that alone is the news: whoever signed this, it was not
                // with the key plMail knows for them.
                return ['state' => null === $current ? SignatureState::UnknownKey : SignatureState::KeyChanged, 'signer' => $signer];
            }

            if ('good' !== $signature['outcome']) {
                return ['state' => SignatureState::Invalid, 'signer' => $signer];
            }

            // The key, by its primary fingerprint: a key often signs with a
            // subkey, and it is the primary fingerprint that the keyring —
            // and a person comparing by hand — knows it by.
            $primary   = $signature['primary'];
            $addresses = $home->keys($primary)[0]['addresses'] ?? [];

            if (false === in_array($from, $addresses, true)) {
                return ['state' => SignatureState::Invalid, 'signer' => $primary];
            }

            return ['state' => self::trust($primary, $own, $contacts, $current), 'signer' => $primary];
        });
    }

    /**
     * The first signature in gpg's status lines (doc/DETAILS in GnuPG): good
     * only on GOODSIG — a bad signature, an expired signature and a signature
     * by an expired or revoked key are all not good — and missing only on
     * ERRSIG with code 9, no public key.
     *
     * The signer is the issuer fingerprint where the signature names one, the
     * long key ID where it does not; the primary fingerprint comes with
     * VALIDSIG, which gpg prints only once it has checked the signature.
     *
     * @return array{outcome: 'good'|'bad'|'missing', fingerprint: string, primary: string}|null
     *         null when gpg said nothing about a signature at all
     */
    private static function firstSignature(string $status): ?array
    {
        $outcome     = null;
        $fingerprint = '';
        $primary     = '';

        foreach (explode("\n", $status) as $line) {
            if (false === str_starts_with($line, '[GNUPG:] ')) {
                continue;
            }

            $fields = explode(' ', trim(substr($line, 9)));

            switch ($fields[0]) {
                case 'NEWSIG':
                    if (null !== $outcome) {
                        break 2;
                    }
                    break;

                case 'GOODSIG':
                    $outcome = 'good';
                    break;

                case 'BADSIG':
                case 'EXPSIG':
                case 'EXPKEYSIG':
                case 'REVKEYSIG':
                    $outcome     = 'bad';
                    $fingerprint = '' === $fingerprint ? ($fields[1] ?? '') : $fingerprint;
                    break;

                case 'ERRSIG':
                    $outcome     = '9' === ($fields[6] ?? '') ? 'missing' : 'bad';
                    $fingerprint = '-' !== ($fields[7] ?? '-') ? $fields[7] : ($fields[1] ?? '');
                    break;

                case 'VALIDSIG':
                    $fingerprint = $fields[1] ?? '';
                    $primary     = $fields[10] ?? $fingerprint;
                    break;
            }
        }

        if (null === $outcome || ('good' === $outcome && '' === $primary)) {
            return null;
        }

        return ['outcome' => $outcome, 'fingerprint' => strtolower($fingerprint), 'primary' => strtolower($primary)];
    }

    /**
     * How far a good signature by $primary, with a user ID for the sender, is
     * to be believed.
     *
     * @param array<string, string> $own
     * @param list<ContactKey>      $contacts
     */
    private static function trust(string $primary, array $own, array $contacts, ?ContactKey $current): SignatureState
    {
        if (true === in_array($primary, array_values($own), true)) {
            return SignatureState::Valid;
        }

        if (null !== $current && $primary === $current->fingerprint) {
            return true === $current->pinned ? SignatureState::Valid : SignatureState::Untrusted;
        }

        // A Changed key, or the Autocrypt key of a message from a sender
        // plMail already holds another key for, is a key other than the known
        // one. Only with no key at all for the address is a key from the
        // message itself merely unconfirmed.
        return [] === $contacts ? SignatureState::Untrusted : SignatureState::KeyChanged;
    }

    /**
     * @param list<ContactKey> $contacts
     */
    private static function currentKey(array $contacts): ?ContactKey
    {
        foreach ($contacts as $key) {
            if (true === $key->isCurrent()) {
                return $key;
            }
        }

        return null;
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

}
