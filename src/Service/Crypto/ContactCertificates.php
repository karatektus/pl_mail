<?php

declare(strict_types=1);

namespace App\Service\Crypto;

use App\Domain\Enum\Crypto\ContactKeyOutcome;
use App\Domain\Enum\Crypto\ContactKeyStatus;
use App\Domain\Enum\Crypto\SignatureState;
use App\Domain\Exception\ContactKeyRefused;
use App\Entity\Crypto\ContactCertificate;
use App\Entity\User\User;
use App\Repository\Crypto\ContactCertificateRepository;
use App\Repository\Crypto\SmimeCertificateRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * The S/MIME certificates a person holds for their contacts, and the rules
 * for which one mail to an address is encrypted to.
 *
 * Certificates arrive one way only: on a signature that checked out, for the
 * sender of that message — SignatureVerifier for signed mail, the reading
 * pane for a signature inside encrypted mail (signerState()). A certificate
 * is only ever kept for an address it names, and only one that can carry a
 * content key (CertificateInfo::usableFor()): that is what it is kept for.
 *
 * Two kinds of trust, because S/MIME has an authority where OpenPGP has none:
 *
 *   - A certificate that chains to an authority the system trusts is
 *     verified. A newer verified certificate replaces a verified Current one
 *     by itself — S/MIME certificates are renewed every year or two, and the
 *     authority vouches for the new one exactly as it did for the old — and
 *     replaces an expired one of any kind.
 *   - Anything else (self-signed, a private CA) is trusted on first use, the
 *     way ContactKeys treats OpenPGP keys: the first one for an address is
 *     Current, a different one later is Changed and waits for the person.
 *     A verified certificate does not get to displace an unexpired
 *     self-signed one the person has been using either: that is a change of
 *     who vouches for the address, and theirs to accept.
 *
 * The only writer of ContactCertificate.
 */
final readonly class ContactCertificates
{
    public function __construct(
        private ContactCertificateRepository $certificates,
        private SmimeCertificateRepository   $ownCertificates,
        private CertificateReader            $reader,
        private Keyring                      $keyring,
        private EntityManagerInterface       $entityManager,
        private ClockInterface               $clock,
    ) {
    }

    /**
     * Offers the certificate that made a good signature by $email.
     *
     * @param bool $verified whether it chained to a trusted authority when the
     *                       signature was checked
     */
    public function record(User $user, string $email, string $pem, bool $verified): ContactKeyOutcome
    {
        $email = strtolower(trim($email));
        $now   = $this->clock->now();
        $info  = $this->reader->read($pem);

        // Never for the person's own addresses: those are their keyring's.
        if (null === $info || false === $info->usableFor($email, $now) || true === in_array($email, $this->keyring->ownedAddresses($user), true)) {
            return ContactKeyOutcome::Unusable;
        }

        $known   = $this->certificates->findForAddress($user, $email);
        $current = null;

        foreach ($known as $row) {
            if ($info->fingerprint === $row->fingerprint) {
                $row->seenAgain($verified, $now);
                $this->entityManager->flush();

                return ContactKeyOutcome::Seen;
            }

            if (true === $row->isCurrent()) {
                $current = $row;
            }
        }

        $replaces = null !== $current && true === $verified
            && (true === $current->verified || true === $current->isExpiredAt($now));

        if (true === $replaces) {
            $this->entityManager->remove($current);
            // Before the new Current goes in: the partial unique index allows
            // one Current per address at any moment, statement by statement.
            $this->entityManager->flush();
        }

        // Current only for an address with nothing pending, as ContactKeys
        // has it: a Changed certificate waiting means a decision is open.
        $status = [] === $known || true === $replaces ? ContactKeyStatus::Current : ContactKeyStatus::Changed;

        $this->entityManager->persist(new ContactCertificate(
            $user,
            $email,
            $info->fingerprint,
            $info->pem,
            $verified,
            $status,
            $info->subject,
            $info->issuer,
            $info->notBefore,
            $info->notAfter,
            $now,
        ));
        $this->entityManager->flush();

        return ContactKeyStatus::Current === $status ? ContactKeyOutcome::Added : ContactKeyOutcome::Changed;
    }

    /**
     * What a good S/MIME signature by $pem on a message from $from means, and
     * the certificate kept for the sender while at it.
     *
     * For a signature the browser checked inside an encrypted message, where
     * the server cannot see the signature but can see the certificate: it
     * asks the system's authorities about it (CertificateReader) and answers
     * by the rules SignatureVerifier applies to signed mail — vouched for is
     * Valid, intact but unvouched-for is Untrusted, a certificate not naming
     * the sender is Invalid. One addition the server cannot make for signed
     * mail without a contact record: an unvouched-for certificate other than
     * the one in use for the sender is KeyChanged.
     *
     * @param string $chain the other certificates the signature carried
     *
     * @return array{state: SignatureState, signer: ?string}
     */
    public function signerState(User $user, string $from, string $pem, string $chain): array
    {
        $from = strtolower(trim($from));
        $info = $this->reader->read($pem);

        if (null === $info) {
            return ['state' => SignatureState::Invalid, 'signer' => null];
        }

        if (false === in_array($from, $info->addresses, true)) {
            return ['state' => SignatureState::Invalid, 'signer' => $info->fingerprint];
        }

        if (null !== $this->ownCertificates->findOneByFingerprint($user, $info->fingerprint)) {
            return ['state' => SignatureState::Valid, 'signer' => $info->fingerprint];
        }

        $verified = $this->reader->chainsToAuthority($info->pem, $chain);

        $this->record($user, $from, $info->pem, $verified);

        if (true === $verified) {
            return ['state' => SignatureState::Valid, 'signer' => $info->fingerprint];
        }

        $current = $this->certificates->findCurrent($user, $from);

        return [
            'state'  => null === $current || $current->fingerprint === $info->fingerprint ? SignatureState::Untrusted : SignatureState::KeyChanged,
            'signer' => $info->fingerprint,
        ];
    }

    /**
     * The certificate mail to $email is encrypted to now: the Current one,
     * if it has not expired. Null otherwise — an expired certificate is one
     * no recipient's program would open mail for any more.
     */
    public function usable(User $user, string $email): ?ContactCertificate
    {
        $current = $this->certificates->findCurrent($user, $email);

        return null === $current || true === $current->isExpiredAt($this->clock->now()) ? null : $current;
    }

    /**
     * A Changed certificate replaces the Current one, which is removed.
     */
    public function accept(User $user, int $id): ContactCertificate
    {
        $certificate = $this->owned($user, $id);

        if (true === $certificate->isCurrent()) {
            return $certificate;
        }

        $previous = $this->certificates->findCurrent($user, $certificate->email);

        if (null !== $previous) {
            $this->entityManager->remove($previous);
            $this->entityManager->flush();
        }

        $certificate->makeCurrent();
        $this->entityManager->flush();

        return $certificate;
    }

    public function remove(User $user, int $id): void
    {
        $this->entityManager->remove($this->owned($user, $id));
        $this->entityManager->flush();
    }

    private function owned(User $user, int $id): ContactCertificate
    {
        return $this->certificates->findOneForUser($user, $id)
            ?? throw new ContactKeyRefused('contact_keys.not_found');
    }
}
