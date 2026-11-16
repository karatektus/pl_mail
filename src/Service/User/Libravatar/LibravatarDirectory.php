<?php

declare(strict_types=1);

namespace App\Service\User\Libravatar;

use App\Entity\User\User;
use App\Repository\Mail\Account\AccountLibravatarRepository;
use App\Repository\System\SenderPictureSettings\SenderPictureSettingsRepository;
use App\Repository\User\User\UserRepository;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * Which user a Libravatar hash names, if any (#642).
 *
 * **Computed, not stored.** A hash is a function of an address, and the
 * addresses already live on accounts and aliases; a table of hashes would be
 * a second copy that has to be kept in step with every alias added on every
 * provider. Instead the map — MD5 and SHA-256 of every published address,
 * both forms the spec allows — is built from the addresses and cached.
 *
 * **The cache holds ids, and the user is asked again.** What the map answers
 * is only "this hash is an address of user 12". Whether user 12 still
 * publishes, and which picture they have now, is read fresh from their row on
 * every request, so opting out or changing the picture takes effect at once.
 * Only an address added or removed waits for the cache, which is short (TTL)
 * and dropped whenever somebody opts in (forget()).
 *
 * **An unpublished user is not here at all**, which is what makes "not
 * opted in" indistinguishable from "no such address" at the route: both are
 * a miss.
 */
class LibravatarDirectory
{
    private const string CACHE_KEY = 'libravatar.directory';

    /** Five minutes: how long an address change can take to be served. */
    private const int TTL = 300;

    public function __construct(
        private readonly AccountLibravatarRepository     $addresses,
        private readonly UserRepository                  $users,
        private readonly SenderPictureSettingsRepository $settings,
        private readonly CacheInterface                  $cache,
    ) {}

    /** The publishing user behind a hash, or null — which includes "the administrator has this off". */
    public function find(string $hash): ?User
    {
        $hash = strtolower($hash);

        if (1 !== preg_match('/^([0-9a-f]{32}|[0-9a-f]{64})$/', $hash) || false === $this->settings->libravatarEnabled()) {
            return null;
        }

        $userId = $this->map()[$hash] ?? null;

        if (null === $userId) {
            return null;
        }

        $user = $this->users->find($userId);

        // Asked again here, not trusted from the cache: see the class docblock.
        if (null === $user || true !== $user->publishesLibravatar || null === $user->avatar) {
            return null;
        }

        return $user;
    }

    /** Drops the cached map, so the next request builds it from the addresses as they are now. */
    public function forget(): void
    {
        $this->cache->delete(self::CACHE_KEY);
    }

    /** @return array<string, int> hash => user id */
    private function map(): array
    {
        return $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): array {
            $item->expiresAfter(self::TTL);

            $map = [];

            foreach ($this->addresses->publishedAddresses() as $row) {
                $map[md5($row['address'])]            = $row['userId'];
                $map[hash('sha256', $row['address'])] = $row['userId'];
            }

            return $map;
        });
    }
}
