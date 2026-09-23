<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\User\ApiToken;
use App\Entity\User\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The `jmap` firewall's two ways in, and the ways they must not open.
 *
 * App passwords (ApiTokenAuthenticator) are the one every JMAP client uses; a
 * revoked one, or one presented under somebody else's address, is refused.
 * The JWT authenticator beside it (`jwt: ~`) is kept for now — setup mints its
 * keypair, config backups carry it and the JMAP README documents it — so what
 * is pinned instead is that it grants nothing to a token nobody signed.
 */
final class JmapAuthenticationTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private User $user;
    private ApiToken $token;
    private string $secret;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        $this->user            = new User();
        $this->user->email     = 'jmap-auth-' . bin2hex(random_bytes(6)) . '@plmail.test';
        $this->user->nameFirst = 'Jmap';
        $this->user->nameLast  = 'Auth';
        $this->user->password  = 'x';
        $this->em->persist($this->user);

        $minted       = ApiToken::create($this->user, 'auth test');
        $this->token  = $minted['token'];
        $this->secret = $minted['secret'];
        $this->em->persist($this->token);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        if (true === $this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }

        parent::tearDown();
    }

    public function testAnActiveAppPasswordIsAccepted(): void
    {
        $this->session('Bearer ' . $this->secret);

        self::assertResponseIsSuccessful();
    }

    public function testARevokedAppPasswordIsRefused(): void
    {
        $this->token->revoke();
        $this->em->flush();

        $this->session('Bearer ' . $this->secret);

        self::assertResponseStatusCodeSame(401);
    }

    public function testBasicAuthUnderAnotherAddressIsRefused(): void
    {
        $this->session('Basic ' . base64_encode('someone-else@plmail.test:' . $this->secret));

        self::assertResponseStatusCodeSame(401);
    }

    /** `alg: none`, claiming this user — what a forged JWT looks like. */
    public function testAnUnsignedJwtIsRefused(): void
    {
        $encode = static fn (array $part): string => rtrim(strtr(base64_encode((string) json_encode($part)), '+/', '-_'), '=');

        $this->session('Bearer ' . $encode(['alg' => 'none', 'typ' => 'JWT']) . '.' . $encode([
            'username' => $this->user->email,
            'exp'      => time() + 3600,
        ]) . '.');

        self::assertResponseStatusCodeSame(401);
    }

    private function session(string $authorization): void
    {
        $this->client->request('GET', '/jmap/session', server: ['HTTP_AUTHORIZATION' => $authorization]);
    }
}
