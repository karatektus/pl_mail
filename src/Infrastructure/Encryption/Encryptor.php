<?php

declare(strict_types=1);

namespace App\Infrastructure\Encryption;

use SensitiveParameter;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Authenticated symmetric encryption for credentials stored in the database.
 *
 * libsodium secretbox (XSalsa20-Poly1305): confidentiality plus integrity, so a
 * tampered ciphertext fails to open rather than decrypting to garbage. A fresh
 * random nonce is generated per encryption and prepended to the ciphertext,
 * which is why encrypting the same value twice yields different output — that
 * is correct, and means these columns cannot be searched or compared by value.
 *
 * Stored format:
 *
 *     enc:v1:<base64(nonce || ciphertext)>
 *
 * The prefix exists so stored values are self-describing: EncryptedStringType
 * uses it to tell an encrypted value from a legacy plaintext one, and a future
 * algorithm change can bump the version without ambiguity.
 */
final readonly class Encryptor
{
    /** Marks a value as encrypted by this class, and with which scheme. */
    public const string PREFIX = 'enc:v1:';

    private string $key;

    /**
     * @param string $base64Key Base64-encoded 32-byte secretbox key
     *
     * @throws EncryptionException when the key is missing or the wrong size
     */
    public function __construct(
        #[SensitiveParameter]
        #[Autowire(env: 'APP_ENCRYPTION_KEY')]
        string $base64Key,
    ) {
        $key = base64_decode(trim($base64Key), true);

        if (false === $key) {
            throw new EncryptionException(
                'APP_ENCRYPTION_KEY is not valid base64. Generate one with: openssl rand -base64 32',
            );
        }

        if (SODIUM_CRYPTO_SECRETBOX_KEYBYTES !== strlen($key)) {
            throw new EncryptionException(sprintf(
                'APP_ENCRYPTION_KEY must decode to %d bytes, got %d. '
                . 'Generate one with: openssl rand -base64 32',
                SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
                strlen($key),
            ));
        }

        $this->key = $key;
    }

    /**
     * Already-encrypted input is returned untouched, so encrypting twice is a
     * no-op rather than a nested ciphertext. Doctrine can convert the same
     * value more than once in a unit of work.
     */
    public function encrypt(#[SensitiveParameter] string $plaintext): string
    {
        if ($this->isEncrypted($plaintext)) {
            return $plaintext;
        }

        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return self::PREFIX . base64_encode(
            $nonce . sodium_crypto_secretbox($plaintext, $nonce, $this->key),
        );
    }

    /**
     * @throws EncryptionException when the value is not decryptable — a wrong
     *                             key, or a truncated or tampered ciphertext
     */
    public function decrypt(#[SensitiveParameter] string $ciphertext): string
    {
        if (false === $this->isEncrypted($ciphertext)) {
            throw new EncryptionException('Value is not encrypted; refusing to decrypt.');
        }

        $raw = base64_decode(substr($ciphertext, strlen(self::PREFIX)), true);

        if (false === $raw || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new EncryptionException('Encrypted value is malformed or truncated.');
        }

        $nonce   = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $sealed  = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $opened  = sodium_crypto_secretbox_open($sealed, $nonce, $this->key);

        if (false === $opened) {
            throw new EncryptionException(
                'Could not decrypt value. This usually means APP_ENCRYPTION_KEY '
                . 'has changed since it was written.',
            );
        }

        return $opened;
    }

    public function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }
}
