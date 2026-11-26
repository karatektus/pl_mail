<?php

declare(strict_types=1);

namespace App\Tests\Controller\Libravatar;

use App\Domain\Enum\Account\EmailAliasSource;
use App\Domain\Enum\Account\EmailAliasStatus;
use App\Domain\Helper\AvatarStorage;
use App\Entity\Mail\Account;
use App\Entity\Mail\EmailAlias;
use App\Entity\User\User;
use App\Repository\System\SenderPictureSettings\SenderPictureSettingsRepository;
use App\Service\User\Libravatar\LibravatarDirectory;
use App\Tests\Support\Mail\SenderPictureFixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * This server answering Libravatar requests for its own users (#642).
 *
 * The claims are about who gets what from a route anybody can call. A user
 * who opted in is served, by either hash the spec allows and for any address
 * of theirs. Everybody else — not opted in, an inactive alias, the
 * administrator's switch off, a stranger — gets exactly the answer an
 * unknown address gets, so the route cannot be used to find out who is here.
 * And the client's `d=` is honoured the way the spec says, without becoming
 * a way to bounce a browser to a `javascript:` URL.
 *
 * Signed out throughout, because every real caller is.
 */
final class LibravatarControllerTest extends WebTestCase
{
    private const string ADDRESS = 'pictured@own.example';
    private const string ALIAS   = 'alias@own.example';
    private const string RETIRED = 'retired@own.example';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $connection;
    private User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $this->em         = static::getContainer()->get(EntityManagerInterface::class);
        $this->connection = static::getContainer()->get(Connection::class);

        $this->connection->beginTransaction();

        $this->user            = new User();
        $this->user->email     = sprintf('libravatar-%s@plmail.test', bin2hex(random_bytes(4)));
        $this->user->nameFirst = 'Pictured';
        $this->user->nameLast  = 'Person';
        $this->user->roles     = ['ROLE_USER'];
        $this->user->password  = 'x';
        $this->em->persist($this->user);
        $this->em->flush();

        $this->user->avatar = static::getContainer()->get(AvatarStorage::class)
            ->storeContents((string) $this->user->id, 'face.png', SenderPictureFixtures::png(200, 120));

        $account                 = new Account();
        $account->usr            = $this->user;
        $account->email          = self::ADDRESS;
        $account->username       = self::ADDRESS;
        $account->imapHost       = 'imap.example.test';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        $account->authType       = 'password';
        $account->mailEnabled    = true;
        $this->em->persist($account);
        $account->addAlias(new EmailAlias($account, self::ALIAS, EmailAliasSource::Manual));
        $account->addAlias(new EmailAlias($account, self::RETIRED, EmailAliasSource::Manual, EmailAliasStatus::Inactive));

        $this->user->publishesLibravatar = true;
        $this->em->flush();

        $this->serving(true);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        static::getContainer()->get(AvatarStorage::class)->deleteAllFor((string) $this->user->id);
        static::getContainer()->get(LibravatarDirectory::class)->forget();

        parent::tearDown();
    }

    public function testAPublishingUserIsServedBySha256AndByMd5AtTheAskedSize(): void
    {
        foreach ([hash('sha256', self::ADDRESS), md5(self::ADDRESS)] as $hash) {
            $this->client->request('GET', '/avatar/' . $hash . '?s=64');

            self::assertResponseIsSuccessful();
            self::assertResponseHeaderSame('Content-Type', 'image/png');

            $size = getimagesizefromstring((string) $this->client->getResponse()->getContent());
            self::assertSame([64, 64], [$size[0] ?? null, $size[1] ?? null], 'square, at the size asked for');
            self::assertStringContainsString('public', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        }
    }

    public function testEveryOwnedAliasIsServedButAnInactiveOneIsNot(): void
    {
        $this->client->request('GET', '/avatar/' . md5(self::ALIAS) . '?d=404');
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/avatar/' . md5(self::RETIRED) . '?d=404');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAUserWhoHasNotOptedInLooksExactlyLikeAnUnknownAddress(): void
    {
        $this->user->publishesLibravatar = false;
        $this->em->flush();

        self::assertSame($this->miss(md5('nobody@own.example')), $this->miss(md5(self::ADDRESS)));
    }

    /**
     * The cached map still names the user after they opt out; the route must
     * ask their row again rather than trust it, or an opt-out would keep
     * serving their face until the cache ran out.
     */
    public function testOptingOutStopsServingAtOnceDespiteTheCache(): void
    {
        $this->client->request('GET', '/avatar/' . md5(self::ADDRESS) . '?d=404');
        self::assertResponseIsSuccessful();

        $this->user->publishesLibravatar = false;
        $this->em->flush();

        $this->client->request('GET', '/avatar/' . md5(self::ADDRESS) . '?d=404');
        self::assertResponseStatusCodeSame(404);
    }

    public function testWithTheAdministratorsSwitchOffNobodyIsServed(): void
    {
        $this->serving(false);

        self::assertSame($this->miss(md5('nobody@own.example')), $this->miss(md5(self::ADDRESS)));
    }

    public function testForceDefaultIsHonoured(): void
    {
        $this->client->request('GET', '/avatar/' . md5(self::ADDRESS) . '?d=404&f=y');

        self::assertResponseStatusCodeSame(404);
    }

    public function testTheClientsDefaultIsFollowedTheWayTheSpecSays(): void
    {
        $hash = md5('nobody@own.example');

        $this->client->request('GET', '/avatar/' . $hash . '?d=' . rawurlencode('https://example.org/fallback.png'));
        self::assertResponseRedirects('https://example.org/fallback.png');

        $this->client->request('GET', '/avatar/' . $hash . '?d=identicon&s=40');
        self::assertResponseRedirects(sprintf('https://seccdn.libravatar.org/avatar/%s?s=40&d=identicon', $hash));

        $this->client->request('GET', '/avatar/' . $hash . '?d=' . rawurlencode('javascript:alert(1)'));
        self::assertResponseRedirects(sprintf('https://seccdn.libravatar.org/avatar/%s?s=80', $hash), null, 'a non-http default is not followed');
    }

    public function testASizeIsKeptWithinWhatIsWorthDrawing(): void
    {
        $this->client->request('GET', '/avatar/' . md5(self::ADDRESS) . '?s=99999');

        $size = getimagesizefromstring((string) $this->client->getResponse()->getContent());
        self::assertSame(512, $size[0] ?? null);
    }

    /**
     * The whole answer to a miss, with this hash taken out of it, so two
     * misses can be compared for being the same answer.
     *
     * @return array{int, string}
     */
    private function miss(string $hash): array
    {
        $this->client->request('GET', '/avatar/' . $hash . '?s=50');
        $response = $this->client->getResponse();

        return [
            $response->getStatusCode(),
            str_replace($hash, '<hash>', (string) $response->headers->get('Location')),
        ];
    }

    private function serving(bool $on): void
    {
        $settings                    = static::getContainer()->get(SenderPictureSettingsRepository::class)->currentOrNew();
        $settings->libravatarEnabled = $on;
        $this->em->persist($settings);
        $this->em->flush();

        static::getContainer()->get(LibravatarDirectory::class)->forget();
    }
}
