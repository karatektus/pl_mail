<?php

declare(strict_types=1);

namespace App\Tests\Controller\Settings;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Account\IncomingProtocol;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Repository\User\User\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The badge on an account's row in Settings → Accounts says how a password
 * account reads its mail. It said IMAP for every password account, so a POP3
 * account looked as if the protocol choice had not been saved (#528).
 */
final class AccountTypeBadgeTest extends WebTestCase
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

    public function testEachPasswordAccountShowsItsOwnProtocol(): void
    {
        $badges = [];

        foreach ([IncomingProtocol::Imap, IncomingProtocol::Pop3, IncomingProtocol::Jmap] as $protocol) {
            $badges[$protocol->value] = $this->account($protocol);
        }

        $crawler = $this->client->request('GET', '/settings/accounts');

        foreach (['imap' => 'IMAP', 'pop3' => 'POP3', 'jmap' => 'JMAP'] as $protocol => $label) {
            $badge = $crawler->filter('li[data-account-id="' . $badges[$protocol]->id . '"] [data-account-type]');

            self::assertSame($label, trim($badge->text()), $protocol . ' account');
        }
    }

    private function account(IncomingProtocol $protocol): Account
    {
        $account                   = new Account();
        $account->usr              = $this->user;
        $account->email            = 'badge-' . $protocol->value . '-' . uniqid('', true) . '@example.test';
        $account->username         = $account->email;
        $account->imapHost         = 'mail.example.test';
        $account->imapPort         = $protocol->defaultPort();
        $account->imapEncryption   = 'ssl';
        $account->authType         = AuthType::Password->value;
        $account->incomingProtocol = $protocol;
        $account->isActive         = true;
        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }
}
