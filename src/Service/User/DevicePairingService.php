<?php

declare(strict_types=1);

namespace App\Service\User;

use App\Entity\User\ApiToken;
use App\Entity\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Pairing a phone by scanning a code.
 *
 * The alternative — showing the app password itself and having the user type
 * it — is 71 characters of base16 copied off one screen onto another, usually
 * a phone keyboard. It is the worst moment in onboarding and the one most
 * likely to be got wrong.
 *
 * **The QR never contains the app password.** It carries a short-lived,
 * single-use pairing code, which the app exchanges for a freshly minted one.
 * That matters because a QR on a laptop screen is a thing people photograph,
 * screen-share and walk away from: a code that is dead two minutes later, and
 * dead immediately once used, cannot hand anybody a permanent key to a
 * mailbox. Embedding the password would.
 *
 * Codes live in the cache rather than a table. A two-minute single-use secret
 * has no business surviving a restart — losing them on deploy costs the user
 * one rescan, and a table would need a migration, an index and a sweeper to
 * hold data whose whole lifetime is shorter than a deploy.
 */
final readonly class DevicePairingService
{
    /**
     * Long enough to walk to the sofa and find the app; short enough that a
     * code left on a screen at the end of the day is already useless.
     */
    private const int TTL_SECONDS = 120;

    /**
     * 32 bytes of CSPRNG. Guessing one inside its two-minute window is not a
     * thing that happens, which is why this endpoint needs no lockout of its
     * own — there is nothing to brute-force.
     */
    private const int CODE_BYTES = 32;

    private const string PREFIX = 'device_pairing_';

    public function __construct(
        private CacheItemPoolInterface $cache,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Mints a pairing code for a signed-in user.
     *
     * @return array{code:string,expiresAt:\DateTimeImmutable}
     */
    public function issue(User $user): array
    {
        $code = rtrim(strtr(base64_encode(random_bytes(self::CODE_BYTES)), '+/', '-_'), '=');

        $item = $this->cache->getItem(self::PREFIX.hash('sha256', $code));
        $item->set((int) $user->id);
        $item->expiresAfter(self::TTL_SECONDS);

        $this->cache->save($item);

        return [
            'code' => $code,
            'expiresAt' => new \DateTimeImmutable(sprintf('+%d seconds', self::TTL_SECONDS)),
        ];
    }

    /**
     * Exchanges a pairing code for a real app password.
     *
     * Burns the code first, so a redeem that races itself — two taps, a retried
     * request — mints one credential rather than two. Returns null for a code
     * that is unknown, expired or already used; the caller must not
     * distinguish those, because doing so would confirm which codes had once
     * been real.
     *
     * @return array{secret:string,username:string}|null
     */
    public function redeem(string $code, string $deviceName): ?array
    {
        $key = self::PREFIX.hash('sha256', $code);
        $item = $this->cache->getItem($key);

        if (false === $item->isHit()) {
            return null;
        }

        $userId = $item->get();
        $this->cache->deleteItem($key);

        $user = $this->entityManager->find(User::class, $userId);

        if (null === $user) {
            return null;
        }

        ['token' => $token, 'secret' => $secret] = ApiToken::create($user, $deviceName);

        $this->entityManager->persist($token);
        $this->entityManager->flush();

        return ['secret' => $secret, 'username' => (string) $user->email];
    }

    /**
     * What goes into the QR.
     *
     * A URL rather than bare JSON so the same string works as a deep link when
     * the code is on the same device the app is on — tapping it is the whole
     * flow, with no camera involved.
     */
    public function pairingUri(string $baseUrl, string $code): string
    {
        return sprintf(
            'plmail://pair?host=%s&code=%s',
            rawurlencode(rtrim($baseUrl, '/')),
            rawurlencode($code),
        );
    }
}
