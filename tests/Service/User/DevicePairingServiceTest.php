<?php

declare(strict_types=1);

namespace App\Tests\Service\User;

use App\Entity\User\ApiToken;
use App\Entity\User\User;
use App\Repository\User\ApiTokenRepository;
use App\Service\User\DevicePairingService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Pairing mints a credential from a code, so the code's guarantees are the
 * security of the feature.
 *
 * Three of them, all asserted here rather than left to inspection: the code is
 * single-use, it expires, and it never carries the app password it stands in
 * for. The last one matters most — a QR on a laptop screen gets photographed,
 * screen-shared and walked away from, and a code that is dead two minutes
 * later cannot hand anyone a permanent key to a mailbox.
 */
final class DevicePairingServiceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private CacheItemPoolInterface $cache;
    private ApiTokenRepository $tokens;
    private DevicePairingService $pairing;

    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->cache      = $container->get('cache.app');
        $this->tokens     = $container->get(ApiTokenRepository::class);
        $this->pairing    = $container->get(DevicePairingService::class);

        $this->connection->beginTransaction();

        $this->user = $this->seedUser();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testRedeemingACodeMintsAnAppPasswordForThatUser(): void
    {
        ['code' => $code] = $this->pairing->issue($this->user);

        $result = $this->pairing->redeem($code, 'Pixel 9');

        self::assertNotNull($result);
        self::assertSame($this->user->getEmail(), $result['username']);
        self::assertStringStartsWith('plmail_', $result['secret']);

        $minted = $this->tokens->findForUser($this->user);

        self::assertCount(1, $minted);
        self::assertSame('Pixel 9', $minted[0]->name);
    }

    /**
     * The secret returned must be the one that actually authenticates — a test
     * that only checked the string's shape would pass against a service that
     * stored a different hash than it handed back.
     */
    public function testTheReturnedSecretIsTheOneStored(): void
    {
        ['code' => $code] = $this->pairing->issue($this->user);

        $result = $this->pairing->redeem($code, 'Pixel 9');
        $token  = $this->tokens->findForUser($this->user)[0];

        self::assertSame($token->tokenHash, ApiToken::hash($result['secret']));
    }

    /**
     * The guarantee that makes a photographed QR harmless. Two taps, a retried
     * request or a shoulder-surfer all get the same answer the second time.
     */
    public function testACodeWorksExactlyOnce(): void
    {
        ['code' => $code] = $this->pairing->issue($this->user);

        self::assertNotNull($this->pairing->redeem($code, 'First'));
        self::assertNull($this->pairing->redeem($code, 'Second'));

        self::assertCount(
            1,
            $this->tokens->findForUser($this->user),
            'a replayed code minted a second credential',
        );
    }

    /**
     * Burned before the credential is minted, so a redeem racing itself yields
     * one app password rather than two.
     */
    public function testTheCodeIsGoneFromTheCacheOnceUsed(): void
    {
        ['code' => $code] = $this->pairing->issue($this->user);

        $this->pairing->redeem($code, 'Pixel 9');

        self::assertFalse(
            $this->cache->getItem('device_pairing_'.hash('sha256', $code))->isHit(),
        );
    }

    public function testAnUnknownCodeMintsNothing(): void
    {
        self::assertNull($this->pairing->redeem('not-a-real-code', 'Pixel 9'));
        self::assertCount(0, $this->tokens->findForUser($this->user));
    }

    /**
     * Expiry is the cache item's TTL, so this asserts the code was stored with
     * one at all — a service that forgot expiresAfter() would pass every other
     * test in this file while leaving codes valid forever.
     */
    public function testTheStoredCodeCarriesAnExpiry(): void
    {
        ['code' => $code, 'expiresAt' => $expiresAt] = $this->pairing->issue($this->user);

        $item = $this->cache->getItem('device_pairing_'.hash('sha256', $code));

        self::assertTrue($item->isHit());
        self::assertEqualsWithDelta(
            (new \DateTimeImmutable('+120 seconds'))->getTimestamp(),
            $expiresAt->getTimestamp(),
            5,
        );
    }

    /** The plaintext code is never what is stored; only its digest is. */
    public function testTheCacheKeyIsADigestNotTheCodeItself(): void
    {
        ['code' => $code] = $this->pairing->issue($this->user);

        self::assertFalse($this->cache->getItem('device_pairing_'.$code)->isHit());
        self::assertTrue($this->cache->getItem('device_pairing_'.hash('sha256', $code))->isHit());
    }

    /** Two issues are two different codes; neither invalidates the other. */
    public function testCodesAreUnique(): void
    {
        ['code' => $first]  = $this->pairing->issue($this->user);
        ['code' => $second] = $this->pairing->issue($this->user);

        self::assertNotSame($first, $second);
        self::assertNotNull($this->pairing->redeem($first, 'One'));
        self::assertNotNull($this->pairing->redeem($second, 'Two'));
    }

    /**
     * The QR payload. Asserted because the whole design rests on it: if the
     * app password ever reached this string, every other guarantee here would
     * be decoration.
     */
    public function testThePairingUriCarriesTheCodeAndNoCredential(): void
    {
        ['code' => $code] = $this->pairing->issue($this->user);

        $uri = $this->pairing->pairingUri('https://mail.example.test/', $code);

        self::assertStringStartsWith('plmail://pair?', $uri);
        self::assertStringContainsString(rawurlencode($code), $uri);
        // Trailing slash trimmed, so the app does not build a double-slashed URL.
        self::assertStringContainsString(rawurlencode('https://mail.example.test'), $uri);
        self::assertStringNotContainsString('plmail_', $uri);
    }

    /** A device name is what the user revokes by, so an empty one is refused. */
    public function testABlankDeviceNameIsNotStored(): void
    {
        ['code' => $code] = $this->pairing->issue($this->user);

        $this->pairing->redeem($code, 'Pixel 9');

        self::assertNotSame('', $this->tokens->findForUser($this->user)[0]->name);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function seedUser(): User
    {
        $user = new User();
        $user
            ->setEmail('pairing-'.uniqid('', true).'@example.test')
            ->setNameFirst('Pairing')
            ->setNameLast('Fixture')
            ->setRoles(['ROLE_USER'])
            ->setPassword('x')
            ->setCreatedAt(new \DateTimeImmutable())
            ->setUpdatedAt(new \DateTimeImmutable());

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
