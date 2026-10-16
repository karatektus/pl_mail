<?php

declare(strict_types=1);

namespace App\Service\Crypto\KeyServer;

use App\Domain\Interface\KeyServerInterface;

/**
 * keys.openpgp.org, through its Verifying Key Server API (VKS):
 *
 *     GET https://keys.openpgp.org/vks/v1/by-email/<address, URL-encoded>
 *
 * answers the armored key, or 404.
 *
 * The server **verifies addresses**: a user ID is only handed out once its
 * owner has clicked the link the server mailed to it, and a key whose
 * addresses nobody confirmed comes back without user IDs at all. Such a key
 * has no user ID for the address asked about, so PublicKeyInfo::usableFor()
 * refuses it in KeyServerLookup — which is the right answer: an unconfirmed
 * upload is exactly somebody else's claim to that address.
 */
final readonly class OpenPgpOrgKeyServer implements KeyServerInterface
{
    public function host(): string
    {
        return 'keys.openpgp.org';
    }

    public function url(string $email): string
    {
        return sprintf('https://keys.openpgp.org/vks/v1/by-email/%s', rawurlencode($email));
    }

    public function accept(): string
    {
        return 'application/pgp-keys';
    }

    public function keyIn(string $body): ?string
    {
        return str_contains($body, '-----BEGIN PGP PUBLIC KEY BLOCK-----') ? $body : null;
    }
}
