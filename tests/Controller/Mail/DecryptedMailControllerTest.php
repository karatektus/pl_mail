<?php

declare(strict_types=1);

namespace App\Tests\Controller\Mail;

use App\Domain\Enum\Crypto\MessageCrypto;
use App\Domain\Enum\Crypto\PlaintextSource;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Message\ForgetDecryptedMailMessage;
use App\Repository\User\UserRepository;
use App\Tests\Command\MailFixtures;
use App\Tests\Support\Mail\SendsAjaxCsrf;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The routes of decrypted mail kept on the server: the list a browser works
 * through, the upload, and the switch in Settings → Encryption.
 *
 * What is claimed: only the person's own encrypted mail, of accounts that keep
 * it, is listed and taken; the switch does not turn on without its box ticked;
 * turning it off queues the clean-up.
 */
final class DecryptedMailControllerTest extends WebTestCase
{
    use SendsAjaxCsrf;

    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';

    private EntityManagerInterface $em;
    private Connection $connection;

    protected function tearDown(): void
    {
        if (true === isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testOnlyEncryptedMailOfAccountsThatKeepItIsListed(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $keeping = $this->account($user, true);
        $other   = $this->account($user, false);

        $wanted   = $this->encrypted($keeping);
        $done     = $this->encrypted($keeping);
        $done->plaintextSource = PlaintextSource::Client;
        $this->encrypted($other);
        MailFixtures::message($this->em, $keeping);
        $this->em->flush();

        $client->request('GET', '/mail/decrypted/pending');

        self::assertResponseIsSuccessful();
        self::assertSame([$wanted->id], array_column(json_decode((string) $client->getResponse()->getContent(), true)['messages'], 'id'));
    }

    public function testTheUploadIsTakenForAKeepingAccountAndRefusedOtherwise(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $keeping = $this->encrypted($this->account($user, true));
        $other   = $this->encrypted($this->account($user, false));
        $csrf    = $this->ajaxCsrf($client);
        $mime    = "Content-Type: text/plain; charset=utf-8\r\n\r\nMeet at the mango stand.";

        $client->request('POST', '/mail/message/' . $keeping->id . '/plaintext', server: $csrf + ['CONTENT_TYPE' => 'application/octet-stream'], content: $mime);
        self::assertResponseStatusCodeSame(204);

        $client->request('POST', '/mail/message/' . $other->id . '/plaintext', server: $csrf + ['CONTENT_TYPE' => 'application/octet-stream'], content: $mime);
        self::assertResponseStatusCodeSame(422);

        $this->em->clear();
        self::assertStringContainsString('mango', (string) $this->em->find(Message::class, $keeping->id)->bodyText);
        self::assertSame('', (string) $this->em->find(Message::class, $other->id)->bodyText);
    }

    public function testSomebodyElsesMessageIsNotTaken(): void
    {
        $client   = static::createClient();
        $this->boot($client);
        $stranger = $this->account(MailFixtures::user($this->em, 'stranger'), true);
        $message  = $this->encrypted($stranger);

        $client->request('POST', '/mail/message/' . $message->id . '/plaintext', server: $this->ajaxCsrf($client) + ['CONTENT_TYPE' => 'application/octet-stream'], content: "Content-Type: text/plain\r\n\r\nx");

        self::assertResponseStatusCodeSame(403);
    }

    public function testTheSwitchNeedsItsBoxTickedAndTurningItOffQueuesTheCleanUp(): void
    {
        $client  = static::createClient();
        $user    = $this->boot($client);
        $account = $this->account($user, false);

        $crawler = $client->request('GET', '/settings?section=encryption');
        $token   = $crawler->filter('form[action="/settings/encryption/decrypted/' . $account->id . '"] input[name="_token"]')->attr('value');

        $client->request('POST', '/settings/encryption/decrypted/' . $account->id, ['_token' => $token, 'keep' => '1']);
        $this->em->clear();
        self::assertFalse($this->em->find(Account::class, $account->id)->keepDecrypted, 'not without the box ticked');

        $client->request('POST', '/settings/encryption/decrypted/' . $account->id, ['_token' => $token, 'keep' => '1', 'confirm' => '1']);
        $this->em->clear();
        self::assertTrue($this->em->find(Account::class, $account->id)->keepDecrypted);

        $client->request('POST', '/settings/encryption/decrypted/' . $account->id, ['_token' => $token, 'keep' => '0']);
        $this->em->clear();
        self::assertFalse($this->em->find(Account::class, $account->id)->keepDecrypted);

        // bulk is a real queue on the test stack: the job is a row, inside this
        // test's transaction and so out of the worker's sight.
        self::assertSame(1, (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'bulk' AND body LIKE ?",
            ['%' . new \ReflectionClass(ForgetDecryptedMailMessage::class)->getShortName() . '%'],
        ), 'turning it off queues the clean-up');
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    private function boot(KernelBrowser $client): User
    {
        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $user = $container->get(UserRepository::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (null === $user) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $client->loginUser($user);
        $client->disableReboot();
        $this->connection->beginTransaction();

        return $this->em->find(User::class, $user->id);
    }

    private function account(User $user, bool $keeping): Account
    {
        $account = MailFixtures::account($this->em, $user);
        $account->keepDecrypted = $keeping;
        $this->em->flush();

        return $account;
    }

    private function encrypted(Account $account): Message
    {
        $message = MailFixtures::message($this->em, $account, 'Sealed');
        $message->crypto   = MessageCrypto::PgpEncrypted;
        $message->bodyText = '';
        $message->bodyHtml = '';
        $this->em->flush();

        return $message;
    }
}
