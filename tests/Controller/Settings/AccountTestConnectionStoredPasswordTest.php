<?php

declare(strict_types=1);

namespace App\Tests\Controller\Settings;

use App\Entity\Mail\Account;
use App\Repository\User\User\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * A blank password in the connection test means "the stored one" — but only
 * for the servers it was saved for. Pointed anywhere else, the test used to
 * send the stored mailbox password to whatever host the request named, so a
 * session alone could read it off a server of its own.
 */
final class AccountTestConnectionStoredPasswordTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';

    private KernelBrowser $client;

    private Account $account;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();

        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (null === $user) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $this->client->loginUser($user);

        $em = static::getContainer()->get(EntityManagerInterface::class);
        $em->getConnection()->beginTransaction();

        $this->account                 = new Account();
        $this->account->usr            = $user;
        $this->account->email          = 'stored-password@example.test';
        $this->account->username       = 'stored-password@example.test';
        $this->account->password       = 'the-stored-password';
        $this->account->authType       = 'password';
        $this->account->imapHost       = 'imap.example.test';
        $this->account->imapPort       = 993;
        $this->account->imapEncryption = 'ssl';
        $this->account->smtpHost       = 'smtp.example.test';
        $this->account->smtpPort       = 587;
        $this->account->smtpEncryption = 'starttls';
        $em->persist($this->account);
        $em->flush();
    }

    protected function tearDown(): void
    {
        $connection = static::getContainer()->get(EntityManagerInterface::class)->getConnection();

        if (true === $connection->isTransactionActive()) {
            $connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAnotherHostWithABlankPasswordIsRefusedBeforeAnythingConnects(): void
    {
        $result = $this->probe(['imapHost' => 'attacker.example.net']);

        self::assertFalse($result['imap']['ok']);
        self::assertStringContainsString('Type the password again', (string) $result['imap']['message']);
    }

    public function testPlainTextOnTheSameHostIsAnotherServerToo(): void
    {
        $result = $this->probe(['smtpEncryption' => 'none']);

        self::assertFalse($result['imap']['ok']);
        self::assertStringContainsString('Type the password again', (string) $result['imap']['message']);
    }

    /**
     * @param array<string, string> $changed
     *
     * @return array<string, mixed>
     */
    private function probe(array $changed): array
    {
        $form = $this->client->request('GET', '/account/new');
        $csrf = $form->filter('[data-settings--connection-test-csrf-value]')->attr('data-settings--connection-test-csrf-value');

        $this->client->request('POST', '/account/test-connection', server: [
            'CONTENT_TYPE'      => 'application/json',
            'HTTP_X_CSRF_TOKEN' => (string) $csrf,
        ], content: (string) json_encode($changed + [
            'accountId'      => $this->account->id,
            'username'       => 'stored-password@example.test',
            'password'       => '',
            'imapHost'       => 'imap.example.test',
            'imapPort'       => '993',
            'imapEncryption' => 'ssl',
            'smtpHost'       => 'smtp.example.test',
            'smtpPort'       => '587',
            'smtpEncryption' => 'starttls',
        ]));

        self::assertResponseIsSuccessful();

        return json_decode((string) $this->client->getResponse()->getContent(), true);
    }
}
