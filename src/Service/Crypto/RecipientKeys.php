<?php

declare(strict_types=1);

namespace App\Service\Crypto;

use App\Domain\Enum\Crypto\PgpScheme;
use App\Domain\Enum\Crypto\RecipientPolicy;
use App\Entity\Crypto\SmimeCertificate;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Repository\Crypto\ContactKeyRepository;
use App\Service\Demo\DemoMode;
use DateTimeInterface;
use Throwable;

/**
 * What a composer needs to know about the people a message is going to: for
 * each address the key plMail would encrypt to and what the person set for
 * that recipient, and for the sender their own key.
 *
 * One answer for every composer: the browser's (ContactKeyController
 * ::recipients()) and a native client's (JMAP ContactKey/lookup). With
 * `lookup`, an address with no key is looked up first — its domain's WKD, then
 * the public key servers if the installation and the person allow them
 * (ContactKeys::lookUp()) — throttled like any other lookup, and a lookup that
 * fails is an address without a key, not an error. Each key says where it
 * came from (`origin`, and `originHost` for a key server), so a composer can
 * say so. The person's own addresses answer
 * with their own key.
 *
 * Beside the OpenPGP key, each answer says whether the address can be reached
 * with S/MIME (`smime`): the certificate plMail would encrypt to — the
 * contact's Current one while it lasts (ContactCertificates::usable()), or
 * the person's own for their own addresses. Nothing is looked up for it;
 * certificates only ever arrive on signed mail.
 */
final readonly class RecipientKeys
{
    /** More than any mail has To, Cc and Bcc for; a lookup each at most. */
    public const int MAX_RECIPIENTS = 50;

    public function __construct(
        private ContactKeyRepository $repository,
        private ContactKeys          $contactKeys,
        private SendingKey           $sendingKeys,
        private DecryptedMailPolicy  $decryptedMail,
        private SmimeCertificates    $ownCertificates,
        private ContactCertificates  $contactCertificates,
        private DemoMode             $demoMode,
    ) {
    }

    /**
     * @param list<mixed> $emails
     *
     * @return list<array{email: string, own: bool, key: array{fingerprint: string, publicKey: string, pinned: bool, origin: ?string, originHost: ?string}|null, changed: bool, encrypt: string, sign: string, scheme: string, smime: array{fingerprint: string, certificate: string, verified: bool, notAfter: string}|null}>
     */
    public function describe(User $user, array $emails, bool $lookup): array
    {
        $lookup = true === $lookup && false === $this->demoMode->isEnabled();
        $result = [];

        foreach (array_slice(array_values(array_unique(array_map(
            static fn (mixed $email): string => strtolower(trim(is_scalar($email) ? (string) $email : '')),
            $emails,
        ))), 0, self::MAX_RECIPIENTS) as $email) {
            if (false !== filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $result[] = $this->recipient($user, $email, $lookup);
            }
        }

        return $result;
    }

    /**
     * The sender's own key for the address a message goes out from, and
     * whether the account keeps decrypted mail — its drafts need no sealing
     * then, and its encrypted mail keeps its text (DecryptedMail). `smime` is
     * the fingerprint of the person's certificate for that address
     * (SmimeCertificates::forSender()), or null: without one, nothing is
     * signed or encrypted with S/MIME.
     *
     * @return array{email: string, fingerprint: ?string, smime: ?string, keepsDecrypted: bool}
     */
    public function sender(User $user, Account $account, string $address): array
    {
        return [
            'email'          => strtolower($address),
            'fingerprint'    => $this->sendingKeys->forAccount($user, $account, $address)?->fingerprint,
            'smime'          => $this->ownCertificates->forSender($account, $address)?->fingerprint,
            'keepsDecrypted' => $this->decryptedMail->keeps($account),
        ];
    }

    /**
     * @return array{email: string, own: bool, key: array{fingerprint: string, publicKey: string, pinned: bool, origin: ?string, originHost: ?string}|null, changed: bool, encrypt: string, sign: string, scheme: string, smime: array{fingerprint: string, certificate: string, verified: bool, notAfter: string}|null}
     */
    private function recipient(User $user, string $email, bool $lookup): array
    {
        $own = $this->sendingKeys->for($user, $email);

        if (null !== $own) {
            return [
                'email'   => $email,
                'own'     => true,
                // No origin: it is the person's own key, which `own` already says.
                'key'     => ['fingerprint' => $own->fingerprint, 'publicKey' => $own->publicKey, 'pinned' => true, 'origin' => null, 'originHost' => null],
                'changed' => false,
                'encrypt' => RecipientPolicy::Auto->value,
                'sign'    => RecipientPolicy::Auto->value,
                'scheme'  => PgpScheme::Mime->value,
                'smime'   => self::ownCertificate($this->ownCertificates->for($user, $email)),
            ];
        }

        $current = $this->repository->findCurrent($user, $email);

        if (null === $current && true === $lookup) {
            try {
                $this->contactKeys->lookUp($user, $email);
                $current = $this->repository->findCurrent($user, $email);
            } catch (Throwable) {
                // Throttled, unreachable, refused: no key, which the composer
                // already knows how to say.
            }
        }

        $changed = false;

        foreach ($this->repository->findForAddress($user, $email) as $key) {
            $changed = $changed || false === $key->isCurrent();
        }

        return [
            'email'   => $email,
            'own'     => false,
            'key'     => null === $current ? null : [
                'fingerprint' => $current->fingerprint,
                'publicKey'   => $current->publicKey,
                'pinned'      => $current->pinned,
                'origin'      => $current->origin->value,
                'originHost'  => $current->originHost,
            ],
            'changed' => $changed,
            'encrypt' => $current?->encrypt->value ?? RecipientPolicy::Auto->value,
            'sign'    => $current?->sign->value ?? RecipientPolicy::Auto->value,
            'scheme'  => $current?->scheme->value ?? PgpScheme::Mime->value,
            'smime'   => $this->contactCertificate($user, $email),
        ];
    }

    /**
     * @return array{fingerprint: string, certificate: string, verified: bool, notAfter: string}|null
     */
    private function contactCertificate(User $user, string $email): ?array
    {
        $certificate = $this->contactCertificates->usable($user, $email);

        return null === $certificate ? null : [
            'fingerprint' => $certificate->fingerprint,
            'certificate' => $certificate->certificate,
            'verified'    => $certificate->verified,
            'notAfter'    => $certificate->notAfter->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array{fingerprint: string, certificate: string, verified: bool, notAfter: string}|null
     */
    private static function ownCertificate(?SmimeCertificate $certificate): ?array
    {
        return null === $certificate ? null : [
            'fingerprint' => $certificate->fingerprint,
            'certificate' => $certificate->certificate,
            'verified'    => true,
            'notAfter'    => $certificate->notAfter->format(DateTimeInterface::ATOM),
        ];
    }
}
