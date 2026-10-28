<?php

declare(strict_types=1);

namespace App\Tests\Controller\Settings;

use App\Domain\Enum\Account\AuthType;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Repository\User\UserRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * "Follow Outlook categories" on a Microsoft account's row (#221 step 4): off
 * by default, on and off from the row, renewing the account's delta chains on
 * the way on so Exchange reports category changes, and not there at all for
 * an account that has no Outlook categories.
 */
final class AccountCategoriesTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $connection;
    private User $user;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $user = $container->get(UserRepository::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (false === $user instanceof User) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $this->user = $user;
        $this->client->loginUser($user);
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheSwitchIsOffAndTurningItOnRenewsTheDeltaChains(): void
    {
        $account = $this->microsoftAccount();
        $account->graphDeltaLinks = ['AAMkAD-inbox' => 'https://graph.microsoft.com/v1.0/me/mailFolders/x/messages/delta?$deltatoken=old'];
        $this->em->flush();

        $crawler = $this->client->request('GET', '/settings?section=accounts');
        $button  = $crawler->filter('turbo-frame#account-categories-' . $account->id . ' button[data-account-categories]');

        self::assertSame('false', $button->attr('aria-pressed'), 'off by default');

        $token = (string) $crawler->filter('turbo-frame#account-categories-' . $account->id . ' input[name=_token]')->attr('value');
        $crawler = $this->client->request('POST', '/settings/accounts/' . $account->id . '/categories', ['_token' => $token]);

        self::assertResponseIsSuccessful();
        self::assertSame('true', $crawler->filter('button[data-account-categories]')->attr('aria-pressed'));

        $stored = $this->em->find(Account::class, $account->id);
        self::assertInstanceOf(Account::class, $stored);
        self::assertTrue($stored->followsOutlookCategories);
        self::assertSame([], $stored->graphDeltaLinks, 'every folder goes back to the import, to be followed with categories');
    }

    public function testAnImapAccountHasNoSuchSwitch(): void
    {
        $account = new Account();
        $account->usr            = $this->user;
        $account->email          = 'categories-imap-' . uniqid('', true) . '@example.test';
        $account->username       = $account->email;
        $account->imapHost       = 'localhost';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        $account->smtpHost       = 'localhost';
        $account->smtpPort       = 587;
        $account->smtpEncryption = 'starttls';
        $account->password       = 'x';
        $account->authType       = 'password';
        $account->isActive       = true;
        $this->em->persist($account);
        $this->em->flush();

        $crawler = $this->client->request('GET', '/settings?section=accounts');

        self::assertCount(0, $crawler->filter('turbo-frame#account-categories-' . $account->id));

        $this->client->request('POST', '/settings/accounts/' . $account->id . '/categories', ['_token' => 'x']);

        self::assertResponseStatusCodeSame(404);
    }

    private function microsoftAccount(): Account
    {
        $account                   = new Account();
        $account->usr              = $this->user;
        $account->email            = 'categories-ms-' . uniqid('', true) . '@example.test';
        $account->username         = $account->email;
        $account->imapHost         = 'outlook.office365.com';
        $account->imapPort         = 993;
        $account->imapEncryption  = 'ssl';
        $account->authType         = AuthType::OAuth2->value;
        $account->oauthProvider    = 'microsoft';
        $account->oauthAccessToken = 'test-access-token';
        $account->oauthTokenExpiry = new DateTimeImmutable('+1 day');
        $account->isActive         = true;
        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }
}
