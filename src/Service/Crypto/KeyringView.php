<?php

declare(strict_types=1);

namespace App\Service\Crypto;

use App\Entity\Crypto\AddressKey;
use App\Entity\Crypto\AddressKeyBinding;
use App\Entity\Crypto\UserKey;
use App\Entity\Crypto\UserKeyCopy;
use App\Entity\User\User;
use App\Repository\Crypto\AddressKeyRepository;
use App\Repository\Crypto\UserKeyCopyRepository;
use App\Repository\Crypto\UserKeyRepository;
use App\Service\Demo\DemoMode;
use DateTimeInterface;

/**
 * A person's keyring as their browser needs it: every wrapped blob, and the
 * addresses keys may be made for.
 *
 * Handed only to the person it belongs to, and holding nothing the server can
 * open — the user keys are wrapped under the password, the address keys under
 * passphrases wrapped under those. Sending all of it on every load is what
 * lets any browser the person signs in on open the keyring with no more than
 * the password.
 */
final readonly class KeyringView
{
    public function __construct(
        private Keyring               $keyring,
        private UserKeyRepository     $userKeys,
        private UserKeyCopyRepository $copies,
        private AddressKeyRepository  $addressKeys,
        private DemoMode              $demoMode,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(User $user): array
    {
        return [
            // A demo visitor's mailbox is public and thrown away within hours;
            // a keyring on it would protect nothing and outlive nothing.
            'enabled'     => false === $this->demoMode->isEnabled(),
            'kdf'         => true === $user->hasClientDerivedPassword()
                ? ['salt' => $user->kdfSalt, 'iterations' => $user->kdfIterations]
                : null,
            'addresses'   => $this->keyring->ownedAddresses($user),
            'userKeys'    => array_map($this->userKey(...), $this->userKeys->findForUser($user)),
            'copies'      => array_map($this->copy(...), $this->copies->findForUser($user)),
            'addressKeys' => array_map($this->addressKey(...), $this->addressKeys->findForUser($user)),
        ];
    }

    /** @return array<string, mixed> */
    private function userKey(UserKey $key): array
    {
        return [
            'id'        => $key->id,
            'wrapped'   => $key->wrapped,
            'status'    => $key->status->value,
            'createdAt' => $key->createdAt->format(DateTimeInterface::ATOM),
            'lockedAt'  => $key->lockedAt?->format(DateTimeInterface::ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function copy(UserKeyCopy $copy): array
    {
        return [
            'id'         => $copy->id,
            'userKeyId'  => $copy->userKey->id,
            'kind'       => $copy->kind->value,
            'wrapped'    => $copy->wrapped,
            'label'      => $copy->label,
            'createdAt'  => $copy->createdAt->format(DateTimeInterface::ATOM),
            'lastUsedAt' => $copy->lastUsedAt?->format(DateTimeInterface::ATOM),
        ];
    }

    /** @return array<string, mixed> */
    private function addressKey(AddressKey $key): array
    {
        return [
            'id'                    => $key->id,
            'userKeyId'             => $key->userKey->id,
            'fingerprint'           => $key->fingerprint,
            'publicKey'             => $key->publicKey,
            'privateKey'            => $key->privateKey,
            'token'                 => $key->token,
            'origin'                => $key->origin->value,
            'status'                => $key->status->value,
            'expiresAt'             => $key->expiresAt?->format(DateTimeInterface::ATOM),
            'revocationCertificate' => $key->revocationCertificate,
            'createdAt'             => $key->createdAt->format(DateTimeInterface::ATOM),
            'addresses'             => array_values(array_map(
                static fn (AddressKeyBinding $binding): array => ['email' => $binding->email, 'primary' => $binding->isPrimary],
                $key->bindings->toArray(),
            )),
        ];
    }
}
