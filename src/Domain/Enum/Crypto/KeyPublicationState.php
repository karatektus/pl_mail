<?php

declare(strict_types=1);

namespace App\Domain\Enum\Crypto;

/**
 * Where one address of one of the person's keys stands on a public key
 * server they published it to (App\Service\Crypto\KeyPublishing), in the
 * words of keys.openpgp.org's VKS API, which the values are.
 *
 * Unpublished: the server holds the key, but hands it out by fingerprint
 * only — nobody looking for the address finds it. Pending: the server mailed
 * the address a link and waits for it to be clicked. Published: confirmed;
 * a lookup by the address finds the key. Revoked: the server holds the key's
 * revocation (the key was marked compromised) and hands out the revoked key.
 */
enum KeyPublicationState: string
{
    case Unpublished = 'unpublished';
    case Pending     = 'pending';
    case Published   = 'published';
    case Revoked     = 'revoked';

    /** Whether asking the server to mail a confirmation link still makes sense. */
    public function canAskToConfirm(): bool
    {
        return self::Unpublished === $this || self::Pending === $this;
    }
}
