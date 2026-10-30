<?php

declare(strict_types=1);

namespace App\Tests\Controller\Settings;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Account\MailProvider;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Repository\User\UserRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * "Move to offers labels" on a Gmail account's row (#221 step 3): off by
 * default, on and off from the row, and not there at all for an account that
 * has folders.
 */
final class AccountGmailMoveTest extends WebTestCase
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

    public function testTheSwitchIsOffAndTurnsOnAndOff(): void
    {
        $account = $this->gmailAccount();

        $crawler = $this->client->request('GET', '/settings?section=accounts');
        $button  = $crawler->filter('turbo-frame#account-gmail-move-' . $account->id . ' button[data-account-gmail-move]');

        self::assertSame('false', $button->attr('aria-pressed'), 'off by default');

        $token   = (string) $crawler->filter('turbo-frame#account-gmail-move-' . $account->id . ' input[name=_token]')->attr('value');
        $crawler = $this->client->request('POST', '/settings/accounts/' . $account->id . '/gmail-move', ['_token' => $token]);

        self::assertResponseIsSuccessful();
        self::assertSame('true', $crawler->filter('button[data-account-gmail-move]')->attr('aria-pressed'));

        $stored = $this->em->find(Account::class, $account->id);
        self::assertInstanceOf(Account::class, $stored);
        self::assertTrue($stored->gmailMoveOffersLabels);

        $this->client->request('POST', '/settings/accounts/' . $account->id . '/gmail-move', ['_token' => $token]);

        self::assertFalse($this->em->find(Account::class, $account->id)?->gmailMoveOffersLabels);
    }

    public function testAnImapAccountHasNoSuchSwitch(): void
    {
        $account = new Account();
        $account->usr            = $this->user;
        $account->email          = 'gmail-move-imap-' . uniqid('', true) . '@example.test';
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

        self::assertCount(0, $crawler->filter('turbo-frame#account-gmail-move-' . $account->id));

        $this->client->request('POST', '/settings/accounts/' . $account->id . '/gmail-move', ['_token' => 'x']);

        self::assertResponseStatusCodeSame(404);
    }

    private function gmailAccount(): Account
    {
        $account                   = new Account();
        $account->usr              = $this->user;
        $account->email            = 'gmail-move-' . uniqid('', true) . '@example.test';
        $account->username         = $account->email;
        $account->imapHost         = 'imap.gmail.com';
        $account->imapPort         = 993;
        $account->imapEncryption   = 'ssl';
        $account->authType         = AuthType::OAuth2->value;
        $account->oauthProvider    = MailProvider::Google->value;
        $account->oauthAccessToken = 'test-access-token';
        $account->oauthTokenExpiry = new DateTimeImmutable('+1 day');
        $account->isActive         = true;
        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }
}
