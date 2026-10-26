<?php

declare(strict_types=1);

namespace App\Service\Crypto;

use App\Domain\DTO\Crypto\KeyServerAnswer;
use App\Domain\DTO\Crypto\PublicKeyInfo;
use App\Domain\Exception\ContactKeyRefused;
use App\Domain\Interface\KeyServerInterface;
use App\Entity\User\User;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Asks public OpenPGP key servers for an address's key, on the browser's
 * behalf — the fallback after the recipient's own domain (WkdLookup) has
 * nothing, for the many people who publish their key on keys.openpgp.org or
 * Mailvelope and have no Web Key Directory.
 *
 * Asked only after WKD (ContactKeys::lookUp()), because the domain speaks for
 * its own addresses and a key server only for whoever confirmed an address to
 * it. The servers are asked in the order the installation lists them, and the
 * first one with a usable key wins: a second opinion from the next server
 * would cost another request and another party told whom the person writes
 * to, and could not outvote the first anyway — what decides between keys is
 * trust on first use (ContactKeys) and the person comparing fingerprints.
 *
 * A lookup tells the server's operator which address somebody here is
 * writing to. That is the price, and two layers decide whether to pay it,
 * each able only to say no:
 *
 *   1. the installation — APP_KEY_SERVERS lists the servers by host name,
 *      both known ones by default (as Thunderbird asks keys.openpgp.org out
 *      of the box); empty switches key servers off. Host names plMail has no
 *      KeyServerInterface for are dropped with a log line rather than guessed
 *      at, since each server speaks its own API;
 *   2. the person — User::$lookUpKeyServers, on unless they switch it off.
 *
 * Every request goes through KeyFetcher, under the same rules as WKD's, and
 * counts against the same per-person budget. Only keys with a user ID for
 * the very address asked about count, not revoked and not expired
 * (PublicKeyInfo::usableFor()).
 */
final readonly class KeyServerLookup
{
    /** @var list<KeyServerInterface> the servers in effect, in the installation's order */
    private array $servers;

    /**
     * @param iterable<KeyServerInterface> $known every server plMail can speak to
     */
    public function __construct(
        private KeyFetcher      $fetcher,
        private PublicKeyReader $reader,
        #[AutowireIterator('app.key_server')]
        iterable                $known,
        #[Autowire('%app.key_servers%')]
        string                  $configured,
        LoggerInterface         $logger,
    ) {
        $byHost = [];

        foreach ($known as $server) {
            $byHost[$server->host()] = $server;
        }

        $servers = [];

        foreach (preg_split('/[\s,]+/', strtolower($configured), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $host) {
            if (false === isset($byHost[$host])) {
                $logger->warning('APP_KEY_SERVERS names {host}, which is not a key server plMail knows; ignored.', ['host' => $host]);
                continue;
            }

            $servers[$host] = $byHost[$host];
        }

        $this->servers = array_values($servers);
    }

    /**
     * The servers the installation asks, by host name, in order — for the
     * settings hint that has to name them before anybody is asked.
     *
     * @return list<string>
     */
    public function hosts(): array
    {
        return array_map(static fn (KeyServerInterface $server): string => $server->host(), $this->servers);
    }

    public function isEnabled(): bool
    {
        return [] !== $this->servers;
    }

    /** Whether a lookup for this person goes to the key servers at all. */
    public function isEnabledFor(User $user): bool
    {
        return true === $this->isEnabled() && true === $user->lookUpKeyServers;
    }

    /**
     * The first server's usable keys for the address, or null when no server
     * has one — or when the installation or the person switched them off, in
     * which case nothing is requested at all.
     *
     * @throws ContactKeyRefused for an address that is not one, or when the
     *         person has used up their lookups
     */
    public function find(User $user, string $email): ?KeyServerAnswer
    {
        $email = strtolower(trim($email));

        if (false === $this->isEnabledFor($user)) {
            return null;
        }

        if (false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ContactKeyRefused('contact_keys.lookup.invalid_address');
        }

        foreach ($this->servers as $server) {
            $body = $this->fetcher->cached(
                $user,
                sprintf('keyserver.%s.%s', $server->host(), sha1($email)),
                function () use ($server, $email): ?string {
                    $body = $this->fetcher->get($server->url($email), $server->accept());

                    return null === $body ? null : $server->keyIn($body);
                },
            );

            $keys = null === $body ? [] : array_values(array_filter(
                $this->reader->read($body),
                static fn (PublicKeyInfo $key): bool => $key->usableFor($email),
            ));

            if ([] !== $keys) {
                return new KeyServerAnswer($server->host(), $keys);
            }
        }

        return null;
    }
}
