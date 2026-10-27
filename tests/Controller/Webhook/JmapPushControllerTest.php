<?php

declare(strict_types=1);

namespace App\Tests\Controller\Webhook;

use App\Domain\Enum\Account\IncomingProtocol;
use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Infrastructure\Messaging\Message\ConfirmJmapPushMessage;
use App\Infrastructure\Messaging\Message\SyncAccountMessage;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * The JMAP PushSubscription callback (#27): a route the internet can reach,
 * so what it refuses matters as much as what it does.
 *
 * Its only proof is the token in the path. Without the comparison anybody
 * could make plMail sync any JMAP account on demand — harmless in what it
 * reads, but a free lever on another server's rate limit. A wrong token and a
 * missing account answer alike, so the route says nothing about which
 * accounts exist.
 */
final class JmapPushControllerTest extends WebTestCase
{
    private Connection $connection;
    private Account $account;
    private string $token;

    protected function tearDown(): void
    {
        if (true === isset($this->connection) && true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testAVerificationIsQueuedToBeEchoedBack(): void
    {
        $client = $this->seed();

        $this->post($client, $this->token, ['@type' => 'PushVerification', 'pushSubscriptionId' => 'ps1', 'verificationCode' => 'abc']);

        self::assertResponseStatusCodeSame(202);

        $queued = $this->queued('export', ConfirmJmapPushMessage::class);

        self::assertCount(1, $queued);
        self::assertSame('abc', $queued[0]->verificationCode);
    }

    public function testAStateChangeQueuesTheMailSync(): void
    {
        $client = $this->seed();

        $this->post($client, $this->token, ['@type' => 'StateChange', 'changed' => ['u1' => ['Email' => 's2']]]);

        self::assertResponseStatusCodeSame(202);
        self::assertCount(1, $this->queued('ingest', SyncAccountMessage::class));
    }

    public function testAWrongTokenIsANotFoundAndQueuesNothing(): void
    {
        $client = $this->seed();

        $this->post($client, str_repeat('0', 64), ['@type' => 'StateChange', 'changed' => ['u1' => ['Email' => 's2']]]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame([], $this->queued('ingest', SyncAccountMessage::class));
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(KernelBrowser $client, string $token, array $body): void
    {
        $client->request(
            'POST',
            sprintf('/webhook/jmap/%d/%s', (int) $this->account->id, $token),
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @param class-string $class
     *
     * @return list<object>
     */
    private function queued(string $transport, string $class): array
    {
        $queue = self::getContainer()->get('messenger.transport.' . $transport);

        self::assertInstanceOf(InMemoryTransport::class, $queue);

        return array_values(array_filter(
            array_map(static fn (object $envelope): object => $envelope->getMessage(), $queue->getSent()),
            static fn (object $message): bool => $message instanceof $class,
        ));
    }

    private function seed(): KernelBrowser
    {
        $client = static::createClient();
        $client->disableReboot();

        $container        = static::getContainer();
        $em               = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();

        $user            = new User();
        $user->email     = 'jmap-hook-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Jmap';
        $user->nameLast  = 'Hook';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $em->persist($user);

        $this->token = bin2hex(random_bytes(32));

        $account                       = new Account();
        $account->usr                  = $user;
        $account->email                = $user->email;
        $account->username             = $user->email;
        $account->authType             = 'password';
        $account->incomingProtocol     = IncomingProtocol::Jmap;
        $account->imapHost             = 'jmap.example.test';
        $account->isActive             = true;
        $account->pushEnabled          = true;
        $account->jmapPushSubscription = ['id' => 'ps1', 'verified' => false, 'expires' => time() + 3600, 'token' => $this->token];
        $em->persist($account);
        $em->flush();

        $this->account = $account;

        return $client;
    }
}
