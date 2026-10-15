<?php

declare(strict_types=1);

namespace App\Service\Crypto;

use App\Domain\DTO\Crypto\WkdEntry;
use App\Domain\Enum\Crypto\KeyringRoot;
use App\Entity\Crypto\AddressKey;
use App\Entity\Crypto\AddressKeyBinding;
use App\Entity\Crypto\SmimeCertificate;
use App\Entity\Crypto\UserKey;
use App\Entity\Crypto\UserKeyCopy;
use App\Entity\User\User;
use App\Repository\Crypto\AddressKeyRepository;
use App\Repository\Crypto\SmimeCertificateRepository;
use App\Repository\Crypto\UserKeyCopyRepository;
use App\Repository\Crypto\UserKeyRepository;
use App\Security\PasswordKdf;
use App\Service\Demo\DemoMode;
use DateTimeInterface;

/**
 * A person's keyring as their browser needs it: every wrapped blob, and the
 * addresses keys may be made for.
 *
 * Handed only to the person it belongs to, and holding nothing the server can
 * open — the user keys are wrapped under the password (or the encryption
 * passphrase, see KeyringRoot), the address keys under passphrases wrapped
 * under those, the S/MIME keys directly under the user keys. Sending all of it
 * on every load is what lets any browser the person signs in on open the
 * keyring with no more than that one secret.
 */
final readonly class KeyringView
{
    public function __construct(
        private Keyring                    $keyring,
        private UserKeyRepository          $userKeys,
        private UserKeyCopyRepository      $copies,
        private AddressKeyRepository       $addressKeys,
        private SmimeCertificateRepository $smimeCertificates,
        private WkdDirectory               $wkd,
        private DemoMode                   $demoMode,
        private PasswordKdf                $kdf,
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
            'enabled'           => false === $this->demoMode->isEnabled(),
            'kdf'               => true === $user->hasClientDerivedPassword()
                ? ['salt' => $user->kdfSalt, 'iterations' => $user->kdfIterations]
                : null,
            // Which secret opens the keyring, and how to stretch it — so a
            // browser or an app knows whether to ask for the password or the
            // encryption passphrase, and can derive the key from either the
            // same way. See rootKdf() for the shapes.
            'root'              => $user->keyringRootInEffect->value,
            'rootKdf'           => $this->rootKdf($user),
            'addresses'         => $this->keyring->ownedAddresses($user),
            'userKeys'          => array_map($this->userKey(...), $this->userKeys->findForUser($user)),
            'copies'            => array_map($this->copy(...), $this->copies->findForUser($user)),
            'addressKeys'       => array_map($this->addressKey(...), $this->addressKeys->findForUser($user)),
            'smimeCertificates' => array_map($this->smimeCertificate(...), $this->smimeCertificates->findForUser($user)),
            // Which of the person's addresses plMail's Web Key Directory
            // publishes, so an app can say so beside the key without asking
            // the directory from outside. Public facts, unlike the rest.
            'wkd'               => array_map($this->wkdEntry(...), $this->wkd->entriesFor($user)),
        ];
    }

    /**
     * The KDF parameters of the root in effect.
     *
     * On the password, what `kdf` holds: null while the stored hash is still
     * of the password itself, which the prelogin answers for instead. On the
     * passphrase, its own salt and count once it is set up; before that a
     * null salt, and the iteration count setting it up has to use.
     *
     * @return array{salt: ?string, iterations: int}|null
     */
    private function rootKdf(User $user): ?array
    {
        if (KeyringRoot::Password === $user->keyringRootInEffect) {
            return true === $user->hasClientDerivedPassword()
                ? ['salt' => $user->kdfSalt, 'iterations' => (int) $user->kdfIterations]
                : null;
        }

        return KeyringRoot::Passphrase === $user->keyringRoot && null !== $user->passphraseSalt
            ? ['salt' => $user->passphraseSalt, 'iterations' => (int) $user->passphraseIterations]
            : ['salt' => null, 'iterations' => $this->kdf->iterations];
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

    /** @return array<string, mixed> */
    private function wkdEntry(WkdEntry $entry): array
    {
        return [
            'email'       => $entry->email,
            'state'       => $entry->state->value,
            'published'   => $entry->state->isPublished(),
            'fingerprint' => $entry->fingerprint,
            'urls'        => $entry->urls,
        ];
    }

    /** @return array<string, mixed> */
    private function smimeCertificate(SmimeCertificate $certificate): array
    {
        return [
            'id'          => $certificate->id,
            'userKeyId'   => $certificate->userKey->id,
            'fingerprint' => $certificate->fingerprint,
            'certificate' => $certificate->certificate,
            'chain'       => $certificate->chain,
            'privateKey'  => $certificate->privateKey,
            'addresses'   => $certificate->addresses,
            'subject'     => $certificate->subject,
            'issuer'      => $certificate->issuer,
            'notBefore'   => $certificate->notBefore->format(DateTimeInterface::ATOM),
            'notAfter'    => $certificate->notAfter->format(DateTimeInterface::ATOM),
            'status'      => $certificate->status->value,
            'createdAt'   => $certificate->createdAt->format(DateTimeInterface::ATOM),
        ];
    }
}
