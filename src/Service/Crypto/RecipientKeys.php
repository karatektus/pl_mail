<?php

declare(strict_types=1);

namespace App\Service\Crypto;

use App\Domain\Enum\Crypto\PgpScheme;
use App\Domain\Enum\Crypto\RecipientPolicy;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Repository\Crypto\ContactKeyRepository;
use App\Service\Demo\DemoMode;
use Throwable;

/**
 * What a composer needs to know about the people a message is going to: for
 * each address the key plMail would encrypt to and what the person set for
 * that recipient, and for the sender their own key.
 *
 * One answer for every composer: the browser's (ContactKeyController
 * ::recipients()) and a native client's (JMAP ContactKey/lookup). With
 * `lookup`, an address with no key is looked up on its domain's WKD first —
 * throttled by WkdLookup like any other lookup, and a lookup that fails is an
 * address without a key, not an error. The person's own addresses answer
 * with their own key.
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
        private DemoMode             $demoMode,
    ) {
    }

    /**
     * @param list<mixed> $emails
     *
     * @return list<array{email: string, own: bool, key: array{fingerprint: string, publicKey: string, pinned: bool}|null, changed: bool, encrypt: string, sign: string, scheme: string}>
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
     * then, and its encrypted mail keeps its text (DecryptedMail).
     *
     * @return array{email: string, fingerprint: ?string, keepsDecrypted: bool}
     */
    public function sender(User $user, Account $account, string $address): array
    {
        return [
            'email'          => strtolower($address),
            'fingerprint'    => $this->sendingKeys->forAccount($user, $account, $address)?->fingerprint,
            'keepsDecrypted' => $this->decryptedMail->keeps($account),
        ];
    }

    /**
     * @return array{email: string, own: bool, key: array{fingerprint: string, publicKey: string, pinned: bool}|null, changed: bool, encrypt: string, sign: string, scheme: string}
     */
    private function recipient(User $user, string $email, bool $lookup): array
    {
        $own = $this->sendingKeys->for($user, $email);

        if (null !== $own) {
            return [
                'email'   => $email,
                'own'     => true,
                'key'     => ['fingerprint' => $own->fingerprint, 'publicKey' => $own->publicKey, 'pinned' => true],
                'changed' => false,
                'encrypt' => RecipientPolicy::Auto->value,
                'sign'    => RecipientPolicy::Auto->value,
                'scheme'  => PgpScheme::Mime->value,
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
            'key'     => null === $current ? null : ['fingerprint' => $current->fingerprint, 'publicKey' => $current->publicKey, 'pinned' => $current->pinned],
            'changed' => $changed,
            'encrypt' => $current?->encrypt->value ?? RecipientPolicy::Auto->value,
            'sign'    => $current?->sign->value ?? RecipientPolicy::Auto->value,
            'scheme'  => $current?->scheme->value ?? PgpScheme::Mime->value,
        ];
    }
}
