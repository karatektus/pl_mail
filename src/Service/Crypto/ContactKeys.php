<?php

declare(strict_types=1);

namespace App\Service\Crypto;

use App\Domain\DTO\Crypto\ContactKeyLookup;
use App\Domain\DTO\Crypto\PublicKeyInfo;
use App\Domain\Enum\Crypto\ContactKeyOrigin;
use App\Domain\Enum\Crypto\ContactKeyOutcome;
use App\Domain\Enum\Crypto\ContactKeyStatus;
use App\Domain\Enum\Crypto\PgpScheme;
use App\Domain\Enum\Crypto\RecipientPolicy;
use App\Domain\Exception\ContactKeyRefused;
use App\Entity\Crypto\ContactKey;
use App\Entity\User\User;
use App\Repository\Crypto\ContactKeyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * The keys a person holds for their contacts, and the one rule that makes
 * them worth holding: trust on first use.
 *
 *   - The first usable key found for an address is used — Current — whether
 *     it came from WKD, a key server, an Autocrypt header, an attachment or
 *     an import.
 *   - A different key turning up later is recorded as Changed and does
 *     nothing else. It is never used in place of the Current key, and never
 *     in place of a pinned one, until the person accepts it. This holds for a
 *     manual import too: a pasted key that differs from a known one is
 *     exactly the moment to stop and compare, not an exception to it.
 *   - Pinning is the person saying they compared the fingerprint with the
 *     contact. It is what turns a contact's signature from Untrusted into
 *     Valid. There is no web of trust: nobody else's certification counts.
 *   - Revoked and expired keys, and keys without a user ID for the address,
 *     are not kept at all (PublicKeyInfo::usableFor()).
 *
 * The only writer of ContactKey. Every change that alters what a signature
 * from that address means — a key added, pinned, unpinned, accepted, removed
 * — asks SignatureRecheck to check that sender's signed mail again.
 */
final readonly class ContactKeys
{
    /** More addresses than this in one import is a keyring dump, not a contact. */
    private const int MAX_IMPORT_ADDRESSES = 50;

    public function __construct(
        private ContactKeyRepository   $contactKeys,
        private PublicKeyReader        $reader,
        private WkdLookup              $wkd,
        private KeyServerLookup        $keyServers,
        private SignatureRecheck       $recheck,
        private EntityManagerInterface $entityManager,
        private ClockInterface         $clock,
    ) {
    }

    /**
     * Offers one key for one address, from wherever it was found.
     */
    public function record(User $user, string $email, PublicKeyInfo $key, ContactKeyOrigin $origin, ?string $originHost = null): ContactKeyOutcome
    {
        $email = strtolower(trim($email));

        if (false === $key->usableFor($email)) {
            return ContactKeyOutcome::Unusable;
        }

        $now   = $this->clock->now();
        $known = $this->contactKeys->findForAddress($user, $email);

        foreach ($known as $row) {
            if ($key->fingerprint === $row->fingerprint) {
                $row->seenAgain($key->armored, $key->expiresAt, $now);
                $this->entityManager->flush();

                return ContactKeyOutcome::Seen;
            }
        }

        // Current only for an address with no keys at all. An address whose
        // Current key was removed while a Changed one waits is still an
        // address with a decision pending, and a third key does not get to
        // make that decision by arriving.
        $status = [] === $known ? ContactKeyStatus::Current : ContactKeyStatus::Changed;

        $this->entityManager->persist(new ContactKey($user, $email, $key->fingerprint, $key->armored, $origin, $status, $key->expiresAt, $now, $originHost));
        $this->entityManager->flush();

        $this->recheck->forSender($user, $email);

        return ContactKeyStatus::Current === $status ? ContactKeyOutcome::Added : ContactKeyOutcome::Changed;
    }

    /**
     * Pasted or uploaded keys, kept for every address their user IDs name.
     *
     * @return array<string, int> how many addresses each ContactKeyOutcome
     *         value came to — added, seen, changed, unusable
     */
    public function import(User $user, string $data): array
    {
        if (true === PublicKeyReader::containsPrivateKey($data)) {
            throw new ContactKeyRefused('contact_keys.import.private_key');
        }

        $keys = $this->reader->read($data);

        if ([] === $keys) {
            throw new ContactKeyRefused('contact_keys.import.not_a_key');
        }

        $counts    = array_fill_keys(array_map(static fn (ContactKeyOutcome $outcome): string => $outcome->value, ContactKeyOutcome::cases()), 0);
        $addresses = 0;

        foreach ($keys as $key) {
            if ([] === $key->addresses || true === $key->revoked || true === $key->expired) {
                $counts[ContactKeyOutcome::Unusable->value]++;
                continue;
            }

            foreach ($key->addresses as $email) {
                if (self::MAX_IMPORT_ADDRESSES <= $addresses++) {
                    break 2;
                }

                $counts[$this->record($user, $email, $key, ContactKeyOrigin::Manual)->value]++;
            }
        }

        return $counts;
    }

    /**
     * Looks the address's key up and offers what turns up: first on the
     * address's own domain (WkdLookup), then — only when the domain has
     * nothing usable — on the key servers, in the installation's order
     * (KeyServerLookup), the first usable answer winning. Null when nobody
     * has a usable key for the address.
     *
     * WKD first because the domain speaks for its own addresses; a key server
     * only for whoever confirmed the address to it. Either way the key goes
     * through record() like any other: a key server never overrides a key
     * plMail already holds, and a different one waits as Changed.
     */
    public function lookUp(User $user, string $email): ?ContactKeyLookup
    {
        $keys   = $this->wkd->find($user, $email);
        $origin = ContactKeyOrigin::Wkd;
        $host   = null;

        if ([] === $keys) {
            $answer = $this->keyServers->find($user, $email);

            if (null === $answer) {
                return null;
            }

            $keys   = $answer->keys;
            $origin = ContactKeyOrigin::KeyServer;
            $host   = $answer->host;
        }

        $outcome = null;

        foreach ($keys as $key) {
            $result  = $this->record($user, $email, $key, $origin, $host);
            $outcome = ContactKeyOutcome::Changed === $outcome ? $outcome : $result;
        }

        return null === $outcome ? null : new ContactKeyLookup($outcome, $origin, $host);
    }

    public function pin(User $user, int $id): ContactKey
    {
        $key = $this->owned($user, $id);

        // A Changed key is accepted first: pinning it means "this is the one",
        // and leaving it Changed would leave the old key in use beside a
        // checkmark on the new one.
        if (false === $key->isCurrent()) {
            $this->accept($user, $id);
        }

        $key->pin();
        $this->entityManager->flush();
        $this->recheck->forSender($user, $key->email);

        return $key;
    }

    public function unpin(User $user, int $id): void
    {
        $key = $this->owned($user, $id);

        $key->unpin();
        $this->entityManager->flush();
        $this->recheck->forSender($user, $key->email);
    }

    /**
     * A Changed key replaces the Current one, which is removed — kept, it
     * would only be a second key for the address that plMail must not use.
     */
    public function accept(User $user, int $id): ContactKey
    {
        $key = $this->owned($user, $id);

        if (true === $key->isCurrent()) {
            return $key;
        }

        $previous = $this->contactKeys->findCurrent($user, $key->email);

        if (null !== $previous) {
            $this->entityManager->remove($previous);
            // Before the new Current goes in: the partial unique index allows
            // one Current per address at any moment, statement by statement.
            $this->entityManager->flush();
        }

        $key->acceptInPlaceOf($previous);
        $this->entityManager->flush();
        $this->recheck->forSender($user, $key->email);

        return $key;
    }

    public function remove(User $user, int $id): void
    {
        $key = $this->owned($user, $id);
        $email = $key->email;

        $this->entityManager->remove($key);
        $this->entityManager->flush();
        $this->recheck->forSender($user, $email);
    }

    public function setPreferences(User $user, int $id, RecipientPolicy $encrypt, RecipientPolicy $sign, PgpScheme $scheme): ContactKey
    {
        $key = $this->owned($user, $id);

        $key->encrypt = $encrypt;
        $key->sign    = $sign;
        $key->scheme  = $scheme;
        $this->entityManager->flush();

        return $key;
    }

    private function owned(User $user, int $id): ContactKey
    {
        return $this->contactKeys->findOneForUser($user, $id)
            ?? throw new ContactKeyRefused('contact_keys.not_found');
    }
}
