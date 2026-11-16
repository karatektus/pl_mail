<?php

declare(strict_types=1);

namespace App\Tests\Controller\Settings;

use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Repository\System\SenderPictureSettings\SenderPictureSettingsRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Settings → Profile → Libravatar, and the admin card that enables it (#642).
 *
 * Pinned: the user's switch exists only while the administrator serves
 * Libravatar, starts off, and cannot be turned on behind the administrator's
 * back or without a token — it publishes somebody's face. And the admin card
 * gives the record each domain needs, which is the help the whole card is for.
 */
final class LibravatarSettingsTest extends WebTestCase
{
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
        $this->user->email     = sprintf('libravatar-pref-%s@plmail.test', bin2hex(random_bytes(4)));
        $this->user->nameFirst = 'Libra';
        $this->user->nameLast  = 'Pref';
        $this->user->roles     = ['ROLE_USER'];
        $this->user->password  = 'x';
        $this->em->persist($this->user);
        $this->em->flush();

        $this->client->loginUser($this->user);
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheSwitchIsAbsentWhileTheAdministratorDoesNotServe(): void
    {
        $page = $this->client->request('GET', '/settings/profile');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $page->filter('form[action="/settings/profile/libravatar"]'));
    }

    /**
     * Every setting is searchable, and search offers a card exactly when the
     * page draws it: not an anchor to nothing while the switch is hidden.
     */
    public function testSearchOffersTheCardExactlyWhenThePageDrawsIt(): void
    {
        $hit = 'li[data-settings--nav-target="hit"] a[href="/settings/profile#card-libravatar"]';

        $page = $this->client->request('GET', '/settings/profile');
        self::assertCount(0, $page->filter($hit));

        $this->serving(true);

        $page = $this->client->request('GET', '/settings/profile');
        self::assertCount(1, $page->filter($hit));
        self::assertCount(1, $page->filter('#card-libravatar'));
    }

    public function testItStartsOffAndIsTurnedOnByTheUser(): void
    {
        $this->serving(true);

        $page = $this->client->request('GET', '/settings/profile');
        self::assertSame('0', $page->filter('input[name="publish"]:checked')->attr('value'));

        $token = (string) $page->filter('form[action="/settings/profile/libravatar"] input[name="_token"]')->attr('value');
        $this->client->request('POST', '/settings/profile/libravatar', ['publish' => '1', '_token' => $token]);

        self::assertResponseRedirects('/settings/profile');
        self::assertTrue($this->reread()->publishesLibravatar);
    }

    public function testItCannotBeTurnedOnWhileTheAdministratorDoesNotServe(): void
    {
        $this->serving(true);
        $page  = $this->client->request('GET', '/settings/profile');
        $token = (string) $page->filter('form[action="/settings/profile/libravatar"] input[name="_token"]')->attr('value');
        $this->serving(false);

        $this->client->request('POST', '/settings/profile/libravatar', ['publish' => '1', '_token' => $token]);

        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->reread()->publishesLibravatar);
    }

    public function testAPostWithoutATokenIsRefused(): void
    {
        $this->serving(true);

        $this->client->request('POST', '/settings/profile/libravatar', ['publish' => '1']);

        self::assertResponseStatusCodeSame(403);
        self::assertFalse($this->reread()->publishesLibravatar);
    }

    public function testTheAdminCardGivesEachDomainItsRecord(): void
    {
        $this->user->roles = ['ROLE_USER', 'ROLE_ADMIN'];
        $this->em->flush();
        $this->client->loginUser($this->user);

        $account                 = new Account();
        $account->usr            = $this->user;
        $account->email          = 'someone@records.example';
        $account->username       = 'someone@records.example';
        $account->imapHost       = 'imap.example.test';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        $account->authType       = 'password';
        $account->mailEnabled    = true;
        $this->em->persist($account);
        $this->em->flush();

        $page = $this->client->request('GET', '/admin/sender-pictures');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('_avatars-sec._tcp.records.example.', $page->filter('turbo-frame')->text());
    }

    private function serving(bool $on): void
    {
        $settings                    = static::getContainer()->get(SenderPictureSettingsRepository::class)->currentOrNew();
        $settings->libravatarEnabled = $on;
        $this->em->persist($settings);
        $this->em->flush();
    }

    private function reread(): User
    {
        $this->em->clear();

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $this->user->email]);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }
}
