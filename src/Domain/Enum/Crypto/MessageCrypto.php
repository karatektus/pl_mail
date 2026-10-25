<?php

declare(strict_types=1);

namespace App\Domain\Enum\Crypto;

/**
 * What a message is wrapped in, as it arrived.
 *
 * Six shapes, because they are handled six ways. An encrypted message keeps no
 * body on the server — what it has is ciphertext, and search, rules and the
 * assistant would only index noise or, worse, see armor as text — and is
 * opened in the browser from its raw source. A signed one keeps its body, and
 * has its signature checked on the server. Inline PGP is the armored block in
 * a plain text body; PGP/MIME and S/MIME are MIME structures. See
 * App\Service\Crypto\MessageCryptoDetector.
 */
enum MessageCrypto: string
{
    case PgpEncrypted       = 'pgp_encrypted';
    case PgpSigned          = 'pgp_signed';
    case PgpInlineEncrypted = 'pgp_inline_encrypted';
    case PgpInlineSigned    = 'pgp_inline_signed';
    case SmimeEncrypted     = 'smime_encrypted';
    case SmimeSigned        = 'smime_signed';

    public function isEncrypted(): bool
    {
        return match ($this) {
            self::PgpEncrypted, self::PgpInlineEncrypted, self::SmimeEncrypted => true,
            default                                                            => false,
        };
    }

    public function isPgp(): bool
    {
        return match ($this) {
            self::SmimeEncrypted, self::SmimeSigned => false,
            default                                 => true,
        };
    }
}
