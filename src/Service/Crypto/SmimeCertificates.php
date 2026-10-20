<?php

declare(strict_types=1);

namespace App\Service\Crypto;

use App\Domain\Enum\Crypto\UserKeyStatus;
use App\Domain\Exception\KeyringRefused;
use App\Entity\Crypto\SmimeCertificate;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Repository\Crypto\SmimeCertificateRepository;
use App\Repository\Crypto\UserKeyRepository;
use App\Security\Crypto\Wrapped;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * A person's own S/MIME certificates: what the server keeps of an imported
 * PKCS#12 file, and which certificate stands for an address.
 *
 * Part of the keyring (Keyring, KeyringView) and held to its rules: the
 * browser opens the file and wraps the private key, the server stores what
 * it is sent and refuses what would not work — a certificate for none of the
 * person's addresses, a key that is not RSA, a wrapping under a user key that
 * is not theirs or not active. It reads the certificate itself rather than
 * taking the browser's word for its addresses, fingerprint or dates: those
 * decide what mail is signed and encrypted with, and OpenSSL can read them
 * from the certificate as well as any browser.
 *
 * A newer certificate for the same addresses retires the older one, which
 * stays to decrypt the mail sent to it. That is the yearly renewal, and the
 * one way two certificates end up serving one address.
 */
final readonly class SmimeCertificates
{
    public function __construct(
        private SmimeCertificateRepository $certificates,
        private UserKeyRepository          $userKeys,
        private Keyring                    $keyring,
        private CertificateReader          $reader,
        private EntityManagerInterface     $entityManager,
        private ClockInterface             $clock,
    ) {
    }

    /**
     * @throws KeyringRefused
     */
    public function import(User $user, int $userKeyId, string $certificate, string $chain, mixed $wrappedKey): SmimeCertificate
    {
        $userKey = $this->userKeys->findOneForUser($user, $userKeyId);

        if (null === $userKey || UserKeyStatus::Active !== $userKey->status) {
            throw new KeyringRefused('No such active user key.');
        }

        if (false === Wrapped::isWellFormed($wrappedKey, Wrapped::MAX_KEY_LENGTH)) {
            throw new KeyringRefused('Expected a wrapped private key.');
        }

        $info = $this->reader->read($certificate)
            ?? throw new KeyringRefused('Not a certificate.');

        if (false === $info->rsa) {
            throw new KeyringRefused('Only RSA certificates can be used here.');
        }

        $addresses = array_values(array_intersect($info->addresses, $this->keyring->ownedAddresses($user)));

        if ([] === $addresses) {
            throw new KeyringRefused('The certificate is for none of this person\'s addresses.');
        }

        if (null !== $this->certificates->findOneByFingerprint($user, $info->fingerprint)) {
            throw new KeyringRefused('This certificate is already on the keyring.');
        }

        $imported = new SmimeCertificate(
            $user,
            $userKey,
            $info->fingerprint,
            $info->pem,
            implode('', array_filter(
                CertificateReader::splitPem($chain),
                static fn (string $pem): bool => trim($pem) !== trim($info->pem),
            )),
            $wrappedKey,
            $addresses,
            $info->subject,
            $info->issuer,
            $info->notBefore,
            $info->notAfter,
        );

        foreach ($this->certificates->findForUser($user) as $older) {
            if (true === $older->isActive()
                && [] === array_diff($older->addresses, $addresses)
                && $older->notAfter <= $imported->notAfter) {
                $older->retire();
            }
        }

        $this->entityManager->persist($imported);
        $this->entityManager->flush();

        return $imported;
    }

    /**
     * Gone for good, key and all. The mail encrypted to it cannot be read
     * here afterwards; the settings page says so before asking.
     */
    public function remove(User $user, int $id): void
    {
        $certificate = $this->certificates->findOneForUser($user, $id)
            ?? throw new KeyringRefused('No such certificate.');

        $this->entityManager->remove($certificate);
        $this->entityManager->flush();
    }

    /**
     * The certificate mail from or to $email is signed with and encrypted to
     * now: active, for the address, inside its validity, and of those the
     * one that lasts longest. Mirrors ownCertificateFor() in
     * assets/crypto/smime.js.
     *
     * For the address itself and no other: a certificate names its
     * addresses, and a signature by one that does not name the From address
     * is one every recipient's program flags as not from the sender.
     */
    public function for(User $user, string $email): ?SmimeCertificate
    {
        $now = $this->clock->now();

        foreach ($this->certificates->findForUser($user) as $certificate) {
            if (true === $certificate->servesAt($email, $now)) {
                return $certificate;
            }
        }

        return null;
    }

    /**
     * The certificate a message from this account and address signs with:
     * for() the address — or, when it is not an address at all (an IMAP
     * account whose "email" is a display name, which is what the wire then
     * carries in its place: Account::$displayAddress), the first of the
     * account's own addresses that has one, as SendingKey::forAccount() does
     * for OpenPGP. A real address is never swapped for another.
     */
    public function forSender(Account $account, string $address): ?SmimeCertificate
    {
        $user = $account->usr;

        if (false !== filter_var(trim($address), FILTER_VALIDATE_EMAIL)) {
            return $this->for($user, $address);
        }

        foreach ($account->ownedAddresses as $owned) {
            $certificate = $this->for($user, (string) $owned);

            if (null !== $certificate) {
                return $certificate;
            }
        }

        return null;
    }
}
