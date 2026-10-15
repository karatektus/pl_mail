<?php

declare(strict_types=1);

namespace App\Service\Crypto;

use App\Domain\DTO\Crypto\AddressKeyInput;
use App\Domain\Enum\Crypto\AddressKeyOrigin;
use App\Domain\Enum\Crypto\KeyringRoot;
use App\Domain\Enum\Crypto\AddressKeyStatus;
use App\Domain\Enum\Crypto\UserKeyCopyKind;
use App\Domain\Enum\Crypto\UserKeyStatus;
use App\Domain\Exception\KeyringRefused;
use App\Entity\Crypto\AddressKey;
use App\Entity\Crypto\UserKey;
use App\Entity\Crypto\UserKeyCopy;
use App\Entity\User\User;
use App\Repository\Crypto\AddressKeyRepository;
use App\Repository\Crypto\UserKeyCopyRepository;
use App\Repository\Crypto\UserKeyRepository;
use App\Repository\Mail\AccountRepository;
use App\Security\Crypto\Wrapped;
use App\Security\PasswordKdf;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

/**
 * Everything the server does to a person's keyring, which is store what their
 * browser made and refuse what would break it.
 *
 * The browser does every piece of cryptography — see assets/crypto/keyring.js
 * — and this side holds the rules a browser cannot be trusted to keep on its
 * own because a stale tab, a bug or a hand-made request would break them for
 * every other tab too: one primary key per address, keys only for addresses
 * the person owns, a password change that re-wraps every key the person can
 * still open, and nothing half-written. Every write is one flush.
 *
 * The keyring hangs off one secret, its root (KeyringRoot): the login password,
 * or — for an account with no password, which signs in through single sign-on
 * — an encryption passphrase. Each rule that concerns "the password" here
 * applies only to keyrings on the password; a passphrase is changed through
 * its own call and nothing that happens to the login reaches it.
 */
final readonly class Keyring
{
    /** Ten million PBKDF2 rounds: well past any sensible setting, short of absurd. */
    private const int MAX_ITERATIONS = 10_000_000;

    public function __construct(
        private UserKeyRepository      $userKeys,
        private UserKeyCopyRepository  $copies,
        private AddressKeyRepository   $addressKeys,
        private AccountRepository      $accounts,
        private EntityManagerInterface $entityManager,
        private ClockInterface         $clock,
        private PasswordKdf            $kdf,
    ) {
    }

    /**
     * The addresses a key may be bound to: every address of every account,
     * lower-cased, that is an address at all — an IMAP login name is not.
     *
     * @return list<string>
     */
    public function ownedAddresses(User $user): array
    {
        $owned = [];

        foreach ($this->accounts->findForUserOrdered($user) as $account) {
            foreach ($account->ownedAddresses as $address) {
                $address = strtolower($address);

                if (false !== filter_var($address, FILTER_VALIDATE_EMAIL)) {
                    $owned[] = $address;
                }
            }
        }

        return array_values(array_unique($owned));
    }

    /**
     * The first keyring of an account, or the fresh one after the old was
     * locked by a lost password: a new user key, the address keys under it,
     * and — usually — this browser as a known device.
     *
     * Refused while an active user key exists. Two browsers signing in at the
     * same moment would otherwise both make one, and the second would leave
     * the first one's keys orphaned under a key nobody remembers making.
     *
     * $passphrase is the salt and iteration count the browser stretched an
     * encryption passphrase with, and is what setting encryption up on an
     * account without a password sends. It is required there and refused
     * everywhere else: a password account's keyring hangs off its password,
     * and a passphrase beside it would be a second secret nothing asks for.
     * The root is written in the same flush as the key it wraps.
     *
     * @param list<mixed>       $addressKeys
     * @param array<mixed>|null $passphrase  {salt, iterations}
     */
    public function create(User $user, string $wrappedUserKey, array $addressKeys, ?string $deviceCopy, ?string $deviceLabel, ?array $passphrase = null): UserKey
    {
        if ([] !== $this->userKeys->findActiveForUser($user)) {
            throw new KeyringRefused('This account already has a keyring.');
        }

        if (KeyringRoot::Passphrase === $user->keyringRootInEffect) {
            if (null === $passphrase) {
                throw new KeyringRefused('An account without a password sets encryption up with a passphrase.');
            }

            [$salt, $iterations] = $this->passphraseParameters($passphrase);
            $user->usePassphraseRoot($salt, $iterations);
        } elseif (null !== $passphrase) {
            throw new KeyringRefused('This keyring hangs off the password; it takes no passphrase.');
        } elseif (false === $user->hasClientDerivedPassword()) {
            throw new KeyringRefused('The password has to be stretched in the browser before a keyring can hang off it.');
        }

        $userKey = new UserKey($user, $this->wrapped($wrappedUserKey));
        $this->entityManager->persist($userKey);

        if (null !== $deviceCopy) {
            $this->entityManager->persist(new UserKeyCopy($userKey, UserKeyCopyKind::Device, $this->wrapped($deviceCopy), $deviceLabel));
        }

        $this->persistAddressKeys($user, $userKey, $addressKeys);

        $this->entityManager->flush();

        return $userKey;
    }

    /**
     * More address keys under a key the person has open: for an address added
     * since, a renewal, or an import. Whatever was primary for the same address
     * stops being so, and is deleted instead of kept when it is one plMail made
     * and nobody has seen yet.
     *
     * @param list<mixed> $addressKeys
     *
     * @return list<AddressKey>
     */
    public function addAddressKeys(User $user, int $userKeyId, array $addressKeys): array
    {
        $userKey = $this->activeUserKey($user, $userKeyId);
        $added   = $this->persistAddressKeys($user, $userKey, $addressKeys);

        $this->entityManager->flush();

        return $added;
    }

    public function makePrimary(User $user, int $addressKeyId, string $email): void
    {
        $key   = $this->addressKey($user, $addressKeyId);
        $email = strtolower(trim($email));

        if (AddressKeyStatus::Active !== $key->status || false === in_array($email, $key->addresses(), true)) {
            throw new KeyringRefused('Only an active key can be made primary, and only for an address it serves.');
        }

        $this->demoteOthers($user, $email, $key);
        $key->bind($email, true);

        $this->entityManager->flush();
    }

    /**
     * Handed out — exported, its public half shown to somebody — so it may no
     * longer be deleted when another key takes its place. See
     * AddressKey::$firstUsedAt.
     *
     * @param list<mixed> $ids
     */
    public function markUsed(User $user, array $ids): void
    {
        foreach ($ids as $id) {
            $key = $this->addressKeys->findOneForUser($user, (int) $id);

            if (null !== $key) {
                $key->firstUsedAt ??= $this->clock->now();
            }
        }

        $this->entityManager->flush();
    }

    public function markCompromised(User $user, int $addressKeyId): void
    {
        $this->addressKey($user, $addressKeyId)->markCompromised();

        $this->entityManager->flush();
    }

    /**
     * A user key wrapped again: after a recovery copy opened a locked one, or
     * the person re-wrapping by hand. Either way the key is active afterwards.
     */
    public function rewrap(User $user, int $userKeyId, string $wrapped): void
    {
        $userKey = $this->userKeys->findOneForUser($user, $userKeyId)
            ?? throw new KeyringRefused('No such user key.');

        $userKey->rewrap($this->wrapped($wrapped));

        $this->entityManager->flush();
    }

    /**
     * A recovery copy. A phrase replaces the phrase the key had, since the old
     * one is the very thing a person makes a new one to stop trusting.
     */
    public function addCopy(User $user, int $userKeyId, UserKeyCopyKind $kind, string $wrapped, ?string $label): UserKeyCopy
    {
        $userKey = $this->activeUserKey($user, $userKeyId);

        if (UserKeyCopyKind::Phrase === $kind) {
            foreach ($this->copies->findPhrasesFor($userKey) as $old) {
                $this->entityManager->remove($old);
            }
        }

        $copy = new UserKeyCopy($userKey, $kind, $this->wrapped($wrapped), UserKeyCopyKind::Device === $kind ? $label : null);
        $this->entityManager->persist($copy);

        $this->entityManager->flush();

        return $copy;
    }

    public function removeCopy(User $user, int $copyId): void
    {
        $copy = $this->copies->findOneForUser($user, $copyId)
            ?? throw new KeyringRefused('No such recovery copy.');

        $this->entityManager->remove($copy);
        $this->entityManager->flush();
    }

    public function touchCopy(User $user, int $copyId): void
    {
        $copy = $this->copies->findOneForUser($user, $copyId);

        if (null !== $copy) {
            $copy->lastUsedAt = $this->clock->now();
            $this->entityManager->flush();
        }
    }

    /**
     * The password changed, and the browser re-wrapped every user key it
     * could open under the new one.
     *
     * Every active key must be in the list. One left out would stay wrapped
     * under a password that no longer exists, which is a lost key — so a
     * missing one refuses the whole change, and the password stays as it was.
     *
     * Nothing to do for a keyring on the passphrase: no key hangs off the
     * password, so there is nothing to re-wrap and nothing to refuse over.
     * The browser sends nothing for one, and anything it did send is ignored
     * rather than written over keys the passphrase opens.
     *
     * Not flushed: PasswordChanger writes the new password in the same flush,
     * so a key and the password it is wrapped under can never disagree.
     *
     * @param array<int|string, mixed> $rewrapped user key id => wrapped
     */
    public function applyPasswordChange(User $user, array $rewrapped): void
    {
        if (KeyringRoot::Password !== $user->keyringRootInEffect) {
            return;
        }

        $this->rewrapAll($user, $rewrapped, 'password');
    }

    /**
     * A new encryption passphrase, for a keyring that hangs off one: its salt
     * and iteration count, and every active user key re-wrapped under it by
     * the browser, which opened them with the old one.
     *
     * The same all-or-nothing rule as a password change, and for the same
     * reason. The login is not touched — it is not what this is.
     *
     * @param array<mixed>             $passphrase {salt, iterations}
     * @param array<int|string, mixed> $rewrapped  user key id => wrapped
     */
    public function changePassphrase(User $user, array $passphrase, array $rewrapped): void
    {
        if (KeyringRoot::Passphrase !== $user->keyringRoot) {
            throw new KeyringRefused('This keyring does not hang off a passphrase.');
        }

        if (false === $this->hasActiveKeys($user)) {
            throw new KeyringRefused('There is no keyring to re-wrap; set encryption up instead.');
        }

        [$salt, $iterations] = $this->passphraseParameters($passphrase, $user->passphraseIterations);

        $this->rewrapAll($user, $rewrapped, 'passphrase');
        $user->usePassphraseRoot($salt, $iterations);

        $this->entityManager->flush();
    }

    /**
     * The password was replaced by somebody who did not know the old one: an
     * operator with `app:user:password`, or a forced change after one. Every
     * key wrapped under the old password is locked, not deleted — a recovery
     * phrase or a known device opens it again.
     *
     * None is, on a keyring that hangs off the passphrase: nothing there was
     * wrapped under the password being replaced, and the person who set the
     * passphrase still knows it. Giving an account that signs in through
     * single sign-on a password as well is exactly this case.
     *
     * Not flushed, for the reason applyPasswordChange() gives.
     *
     * @return int how many keys were locked
     */
    public function lockAll(User $user): int
    {
        if (KeyringRoot::Password !== $user->keyringRootInEffect) {
            return 0;
        }

        $locked = 0;

        foreach ($this->userKeys->findActiveForUser($user) as $userKey) {
            $userKey->lock($this->clock->now());
            ++$locked;
        }

        return $locked;
    }

    public function hasActiveKeys(User $user): bool
    {
        return [] !== $this->userKeys->findActiveForUser($user);
    }

    /**
     * Everything a removed person's browser made. Not merely locked: nobody
     * will sign in as them again to open it, and key material is the last
     * thing a removal should leave behind.
     */
    public function forget(User $user): void
    {
        foreach ($this->userKeys->findForUser($user) as $userKey) {
            $this->entityManager->remove($userKey);
        }
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * @param array<int|string, mixed> $rewrapped
     */
    private function rewrapAll(User $user, array $rewrapped, string $secret): void
    {
        foreach ($this->userKeys->findActiveForUser($user) as $userKey) {
            $wrapped = $rewrapped[$userKey->id] ?? null;

            if (false === Wrapped::isWellFormed($wrapped)) {
                throw new KeyringRefused(sprintf('User key %d was not re-wrapped for the new %s.', $userKey->id, $secret));
            }

            $userKey->rewrap($wrapped);
        }
    }

    /**
     * The salt and iteration count of a passphrase, as the browser sent them.
     *
     * At least the count the installation stretches passwords with, so a
     * browser cannot talk the server into a cheaper root — or the count the
     * passphrase already has, so raising the configured number later does not
     * refuse a change made with the one the keyring was set up under. Bounded
     * above only so that a typo cannot make every unlock take an hour.
     *
     * @param array<mixed> $passphrase
     *
     * @return array{string, int}
     */
    private function passphraseParameters(array $passphrase, ?int $current = null): array
    {
        $salt       = $passphrase['salt'] ?? null;
        $iterations = $passphrase['iterations'] ?? null;
        $acceptable = is_int($iterations)
            && $iterations <= self::MAX_ITERATIONS
            && ($iterations >= $this->kdf->iterations || $iterations === $current);

        if (false === is_string($salt) || false === $this->kdf->isSalt($salt) || false === $acceptable) {
            throw new KeyringRefused('Expected the salt and iteration count the passphrase was stretched with.');
        }

        return [$salt, $iterations];
    }

    /**
     * @param list<mixed> $addressKeys
     *
     * @return list<AddressKey>
     */
    private function persistAddressKeys(User $user, UserKey $userKey, array $addressKeys): array
    {
        $owned = $this->ownedAddresses($user);
        $added = [];

        foreach ($addressKeys as $raw) {
            $input = AddressKeyInput::fromArray($raw, $owned);

            foreach ($this->addressKeys->findForUser($user) as $existing) {
                if ($existing->fingerprint === $input->fingerprint) {
                    throw new KeyringRefused(sprintf('Key %s is already on this keyring.', $input->fingerprint));
                }
            }

            $key = new AddressKey(
                $user,
                $userKey,
                $input->fingerprint,
                $input->publicKey,
                $input->privateKey,
                $input->token,
                $input->origin,
                $input->expiresAt,
                $input->revocationCertificate,
            );

            foreach ($input->addresses as $binding) {
                if (true === $binding['primary']) {
                    $this->demoteOthers($user, $binding['email'], $key);
                }

                $key->bind($binding['email'], $binding['primary']);
            }

            $this->entityManager->persist($key);
            $added[] = $key;
        }

        return $added;
    }

    /**
     * The key that was primary for this address stops being it. A generated
     * key nobody has seen is deleted outright — keeping it would leave a
     * second key for the address that was never anybody's — and any other
     * becomes obsolete, still there to decrypt what was sent to it.
     */
    private function demoteOthers(User $user, string $email, AddressKey $newPrimary): void
    {
        foreach ($this->addressKeys->findForUser($user) as $key) {
            if ($key === $newPrimary) {
                continue;
            }

            foreach ($key->bindings as $binding) {
                if ($email !== $binding->email || false === $binding->isPrimary) {
                    continue;
                }

                $binding->isPrimary = false;

                if (true === $this->isDisposable($key)) {
                    $this->entityManager->remove($key);
                } elseif (false === $this->servesAnyAsPrimary($key)) {
                    $key->markObsolete();
                }
            }
        }
    }

    /**
     * Only ever a key under an active user key: one under a locked key is the
     * very thing a recovery phrase is kept to bring back, and the browser that
     * replaced it could not even open it to know whether it mattered.
     */
    private function isDisposable(AddressKey $key): bool
    {
        return AddressKeyOrigin::Generated === $key->origin
            && null === $key->firstUsedAt
            && UserKeyStatus::Active === $key->userKey->status
            && false === $this->servesAnyAsPrimary($key);
    }

    private function servesAnyAsPrimary(AddressKey $key): bool
    {
        foreach ($key->bindings as $binding) {
            if (true === $binding->isPrimary) {
                return true;
            }
        }

        return false;
    }

    private function activeUserKey(User $user, int $id): UserKey
    {
        $userKey = $this->userKeys->findOneForUser($user, $id);

        if (null === $userKey || UserKeyStatus::Active !== $userKey->status) {
            throw new KeyringRefused('No such active user key.');
        }

        return $userKey;
    }

    private function addressKey(User $user, int $id): AddressKey
    {
        return $this->addressKeys->findOneForUser($user, $id)
            ?? throw new KeyringRefused('No such address key.');
    }

    private function wrapped(mixed $value): string
    {
        if (false === Wrapped::isWellFormed($value)) {
            throw new KeyringRefused('Expected a wrapped key.');
        }

        return $value;
    }
}
