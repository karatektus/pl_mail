<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctrine\Type;

use App\Infrastructure\Encryption\EncryptionException;
use App\Infrastructure\Encryption\Encryptor;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\TextType;

/**
 * A TEXT column whose value is encrypted on the way in and decrypted on the
 * way out, so credentials are never at rest in readable form.
 *
 * Use it on a property with `#[ORM\Column(type: EncryptedStringType::NAME)]`.
 * The database column stays TEXT, so adopting it needs no schema migration —
 * only the values change shape.
 *
 * ── Why a static encryptor ───────────────────────────────────────────────
 * Doctrine instantiates types itself, through a static registry, and offers no
 * constructor injection. The container therefore hands the Encryptor over at
 * boot (see Kernel::boot). That is the standard workaround, and the reason this
 * class cannot simply be a service.
 *
 * ── Legacy plaintext ─────────────────────────────────────────────────────
 * Values written before this type was introduced carry no `enc:v1:` prefix.
 * They are passed through on read rather than throwing, so an instance that
 * predates encryption stays usable — you can still open the accounts page and
 * delete or re-enter the affected accounts. Anything written from then on is
 * encrypted, so plaintext disappears as records are rewritten. Nothing
 * backfills them: the old values are readable by definition, and rewriting
 * them silently would suggest a security guarantee that the backups and WAL
 * segments still holding the plaintext do not support.
 */
final class EncryptedStringType extends TextType
{
    public const string NAME = 'encrypted_string';

    private static ?Encryptor $encryptor = null;

    /**
     * Called once from Kernel::boot(), since Doctrine builds types statically.
     */
    public static function setEncryptor(Encryptor $encryptor): void
    {
        self::$encryptor = $encryptor;
    }

    public function convertToDatabaseValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value || '' === $value) {
            return $value;
        }

        return self::encryptor()->encrypt((string) $value);
    }

    public function convertToPHPValue(mixed $value, AbstractPlatform $platform): ?string
    {
        if (null === $value || '' === $value) {
            return $value;
        }

        $value = (string) $value;

        // Written before this type existed — see the class docblock.
        if (false === self::encryptor()->isEncrypted($value)) {
            return $value;
        }

        try {
            return self::encryptor()->decrypt($value);
        } catch (EncryptionException $e) {
            // Surfaced as a Doctrine conversion error so the failure names the
            // column it happened on, which matters when the cause is a changed
            // APP_ENCRYPTION_KEY and every credential fails at once.
            throw new ConversionException(
                sprintf('Could not decrypt %s column: %s', self::NAME, $e->getMessage()),
                previous: $e,
            );
        }
    }

    private static function encryptor(): Encryptor
    {
        if (null === self::$encryptor) {
            throw new EncryptionException(
                'EncryptedStringType has no Encryptor. It must be injected during kernel boot.',
            );
        }

        return self::$encryptor;
    }
}
