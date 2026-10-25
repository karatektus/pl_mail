<?php

declare(strict_types=1);

namespace App\Service\Crypto;

use App\Domain\DTO\Crypto\KeyFetch;
use App\Domain\DTO\Crypto\WkdCheck;
use App\Domain\DTO\Crypto\WkdEntry;
use App\Domain\Enum\Crypto\WkdCheckResult;
use App\Domain\Enum\Crypto\WkdState;
use App\Entity\Crypto\AddressKey;
use App\Entity\User\User;
use App\Repository\User\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * plMail's own Web Key Directory (draft-koch-openpgp-webkey-service): which
 * key, if any, it hands out for an address on one of the installation's
 * domains — the other side of WkdLookup, which asks other people's domains.
 *
 * Three layers, each able only to say no, like DecryptedMailPolicy's:
 *
 *   1. the installation — APP_WKD_DOMAINS names the domains plMail answers
 *      for, and nothing else is ever served. Not "every domain somebody here
 *      has an address on": a person with a Gmail account here has no business
 *      having plMail speak for gmail.com, and an empty list (the default) is
 *      no directory at all;
 *   2. the address — only a person's **sign-in address** is published. Every
 *      other address of theirs came from them: an alias is typed in, and an
 *      IMAP account's address and even its server are whatever its owner
 *      entered. Publishing those would let anybody with an account here put a
 *      key of theirs under the boss's address. The sign-in address is unique
 *      on the installation and set by an administrator, which is as close as
 *      plMail gets to the domain owner vouching for it;
 *   3. the person — who may switch publishing off (User::$wkdUnpublished).
 *
 * The key is the one SendingKey picks for the address — active, the primary
 * one where there are several — so what the directory says is what the person
 * signs with, and a renewed key is served from the moment it is made. Expired
 * keys are not published, and neither are revoked or replaced ones, which are
 * no longer active. The key goes out reduced to the address's user ID
 * (WkdKeyExport).
 *
 * Found by the hash alone. The draft adds the local part as `?l=`, but only as
 * a hint a server may ignore, and a server that trusted it over the hash would
 * answer for an address nobody asked about.
 */
final readonly class WkdDirectory
{
    public function __construct(
        private UserRepository         $users,
        private SendingKey             $sendingKey,
        private Keyring                $keyring,
        private WkdKeyExport           $export,
        private WkdLookup              $lookup,
        private PublicKeyReader        $reader,
        private EntityManagerInterface $entityManager,
        private ClockInterface         $clock,
        #[Autowire('%app.wkd_domains%')]
        private string                 $configuredDomains,
    ) {
    }

    /**
     * The installation's domains, each in the shape WkdLookup::domain() gives
     * a domain — lower-case, IDNA-encoded — so that a domain with an umlaut is
     * the same domain whether the variable spells it as Unicode or as Punycode.
     * Commas or whitespace between them; anything that is not a public DNS
     * name is dropped rather than half-served.
     *
     * @return list<string>
     */
    public function domains(): array
    {
        $domains = [];

        foreach (preg_split('/[\s,]+/', $this->configuredDomains, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $domain) {
            $domain = WkdLookup::domain($domain);

            if (null !== $domain) {
                $domains[] = $domain;
            }
        }

        return array_values(array_unique($domains));
    }

    public function isEnabled(): bool
    {
        return [] !== $this->domains();
    }

    public function serves(string $domain): bool
    {
        $domain = WkdLookup::domain($domain);

        return null !== $domain && true === in_array($domain, $this->domains(), true);
    }

    /**
     * The domain a direct-method request is about.
     *
     * The host it arrived under, when that is one of the installation's
     * domains — which is how the website's server forwards it when it passes
     * the Host header on. Otherwise, when there is exactly one domain, that
     * one: a forward that rewrites the host to plMail's own name, or a
     * redirect, arrives under a host that is not a mail domain, and with one
     * domain there is no doubt which it meant. With several there is, so
     * nothing is guessed.
     */
    public function directDomain(string $host): ?string
    {
        if (true === $this->serves($host)) {
            return WkdLookup::domain($host);
        }

        $domains = $this->domains();

        return 1 === count($domains) ? $domains[0] : null;
    }

    /**
     * The binary key to hand out for this hash on this domain, or null for
     * every reason there is none. The reasons are deliberately not told apart:
     * the route answers each one with the same 404.
     */
    public function find(string $domain, string $hash): ?string
    {
        $domain = WkdLookup::domain($domain);
        $hash   = strtolower($hash);

        if (null === $domain || false === $this->serves($domain)) {
            return null;
        }

        foreach ($this->users->findForWkdDomain($domain) as $user) {
            $email = strtolower((string) $user->email);

            if ($hash !== WkdLookup::hash(substr($email, 0, (int) strrpos($email, '@')))) {
                continue;
            }

            $key = $this->publishedKey($user, $email);

            if (null === $key) {
                continue;
            }

            $binary = $this->export->reduce($key->publicKey, $key->fingerprint, $email);

            if (null === $binary) {
                continue;
            }

            // Handed out: from now on contacts may encrypt to it, so it may
            // no longer simply be deleted when another key replaces it — see
            // AddressKey::$firstUsedAt. One write per key, ever.
            if (null === $key->firstUsedAt) {
                $key->firstUsedAt = $this->clock->now();
                $this->entityManager->flush();
            }

            return $binary;
        }

        return null;
    }

    /**
     * The person's addresses on the installation's domains, with what the
     * directory does for each — the sign-in address and every address their
     * keys may be bound to, so the one that is not published is listed with
     * why rather than missing.
     *
     * @return list<WkdEntry>
     */
    public function entriesFor(User $user): array
    {
        // Asked on every load of the keyring (KeyringView), so an installation
        // without a directory pays nothing for it.
        if (false === $this->isEnabled()) {
            return [];
        }

        $entries = [];
        $own     = $this->addressesOf($user);

        foreach ($own as $email) {
            $entry = $this->entry($user, $email, $own);

            if (null !== $entry) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * Null for an address that is not the person's, or is on none of the
     * installation's domains — which is what makes this the ownership check
     * for the settings actions too.
     */
    public function entryFor(User $user, string $email): ?WkdEntry
    {
        return $this->entry($user, strtolower(trim($email)), $this->addressesOf($user));
    }

    /**
     * Switches publishing for one of the person's addresses. Refused (false)
     * for an address the directory would never publish, so a hand-made request
     * cannot fill the settings bag with strangers' addresses.
     */
    public function setPublished(User $user, string $email, bool $published): bool
    {
        $entry = $this->entryFor($user, $email);

        if (null === $entry || false === $entry->state->isSwitchable()) {
            return false;
        }

        $unpublished = array_values(array_diff($user->wkdUnpublished, [$entry->email]));

        if (false === $published) {
            $unpublished[] = $entry->email;
        }

        $user->wkdUnpublished = $unpublished;
        $this->entityManager->flush();

        return true;
    }

    /**
     * Asks for the person's own address the way anybody else would, by both
     * WKD methods on their own, from outside and past the cache
     * (WkdLookup::check()), and says of each whether what came back is the
     * key plMail means to publish. Null for an address that is not one of
     * theirs on a served domain. WkdLookup's refusals (rate limit, address)
     * are left to the caller, which shows them.
     */
    public function check(User $user, string $email): ?WkdCheck
    {
        $entry = $this->entryFor($user, $email);

        if (null === $entry) {
            return null;
        }

        $answers = $this->lookup->check($user, $entry->email);

        return new WkdCheck(
            $entry->email,
            $this->verdict($entry, $answers['advanced']),
            $this->verdict($entry, $answers['direct']),
        );
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * One method's answer against the key plMail publishes for the address.
     * Any key at all that is not the current one is Outdated — a key for
     * another address under this one's hash is as wrong as yesterday's.
     */
    private function verdict(WkdEntry $entry, KeyFetch $answer): WkdCheckResult
    {
        if (false === $answer->reached) {
            return WkdCheckResult::Unreachable;
        }

        $body = $answer->found();
        $keys = null === $body ? [] : $this->reader->read($body);

        foreach ($keys as $key) {
            if (true === $entry->state->isPublished() && $entry->fingerprint === $key->fingerprint && true === $key->usableFor($entry->email)) {
                return WkdCheckResult::Current;
            }
        }

        return [] === $keys ? WkdCheckResult::Missing : WkdCheckResult::Outdated;
    }

    /**
     * The sign-in address first, then every address the person's keys may be
     * bound to (Keyring::ownedAddresses()).
     *
     * @return list<string>
     */
    private function addressesOf(User $user): array
    {
        return array_values(array_unique([strtolower((string) $user->email), ...$this->keyring->ownedAddresses($user)]));
    }

    /**
     * @param list<string> $own the person's addresses, from addressesOf()
     */
    private function entry(User $user, string $email, array $own): ?WkdEntry
    {
        $urls = WkdLookup::urls($email);

        if (null === $urls || false === in_array($email, $own, true) || false === $this->serves(substr($email, (int) strrpos($email, '@') + 1))) {
            return null;
        }

        $key = $this->currentKey($user, $email);

        $state = match (true) {
            $email !== strtolower((string) $user->email)            => WkdState::NotSignInAddress,
            true === in_array($email, $user->wkdUnpublished, true) => WkdState::SwitchedOff,
            null === $key                                          => WkdState::NoKey,
            default                                                => WkdState::Published,
        };

        return new WkdEntry($email, $state, $urls, $key?->fingerprint);
    }

    /**
     * The key published for this address, if every layer agrees. Only ever
     * asked about the person's sign-in address, which is theirs by definition
     * — so the address list is that one address, and a stranger's request on
     * the public route costs no query for the person's accounts.
     */
    private function publishedKey(User $user, string $email): ?AddressKey
    {
        $entry = $this->entry($user, $email, [$email]);

        return null !== $entry && true === $entry->state->isPublished() ? $this->currentKey($user, $email) : null;
    }

    /**
     * SendingKey's choice, unless it has expired: a client handed an expired
     * key refuses it anyway, and a directory that serves one says the address
     * has a key when for every practical purpose it has none.
     */
    private function currentKey(User $user, string $email): ?AddressKey
    {
        $key = $this->sendingKey->for($user, $email);

        if (null === $key || (null !== $key->expiresAt && $key->expiresAt <= $this->clock->now())) {
            return null;
        }

        return $key;
    }
}
