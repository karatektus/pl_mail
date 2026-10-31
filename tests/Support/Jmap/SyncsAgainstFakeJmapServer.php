<?php

declare(strict_types=1);

namespace App\Tests\Support\Jmap;

use App\Domain\Enum\Account\IncomingProtocol;
use App\Entity\Mail\Account;
use App\Entity\Mail\Message;
use App\Entity\User\User;
use App\Repository\Mail\MessageRepository;
use App\Service\JmapClient\JmapAccountSyncer;
use App\Service\JmapClient\JmapImporter;
use App\Service\JmapClient\JmapImportStep;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * A JMAP account on a FakeJmapServer, inside a transaction the test rolls back.
 *
 * For KernelTestCase tests: call bootJmap() from setUp() and rollBackJmap()
 * from tearDown(). The server is put in front of the client by replacing the
 * integration HTTP client — the one JmapClient is built with — before anything
 * asks for it.
 */
trait SyncsAgainstFakeJmapServer
{
    private EntityManagerInterface $em;
    private FakeJmapServer $server;
    private Account $account;

    private function bootJmap(): void
    {
        self::bootKernel();

        $this->server = new FakeJmapServer();
        static::getContainer()->set('app.http_client.integration', $this->server->client());

        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->em->getConnection()->beginTransaction();

        $user            = new User();
        $user->email     = 'jmap-' . bin2hex(random_bytes(5)) . '@plmail.test';
        $user->nameFirst = 'Jmap';
        $user->nameLast  = 'Fixture';
        $user->password  = 'x';
        $this->em->persist($user);

        $account                   = new Account();
        $account->usr              = $user;
        $account->name             = 'JMAP fixture';
        $account->email            = 'me@example.test';
        $account->username         = 'me@example.test';
        $account->password         = 'app-password';
        $account->incomingProtocol = IncomingProtocol::Jmap;
        $account->imapHost         = 'jmap.example.test';
        $account->imapPort         = 443;
        $account->imapEncryption   = 'ssl';
        $account->authType         = 'password';
        $account->isActive         = true;
        $this->em->persist($account);
        $user->addAccount($account);
        $this->em->flush();

        $this->account = $account;
    }

    private function rollBackJmap(): void
    {
        if (true === $this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }
    }

    /** One sync, and the import it planned run to its end. */
    private function syncAndImport(): void
    {
        static::getContainer()->get(JmapAccountSyncer::class)->sync($this->freshAccount());

        $importer = static::getContainer()->get(JmapImporter::class);

        for ($page = 0; $page < 20; ++$page) {
            if (JmapImportStep::Finished === $importer->importPage($this->freshAccount())) {
                break;
            }
        }

        $this->account = $this->freshAccount();
    }

    private function freshAccount(): Account
    {
        return $this->em->find(Account::class, $this->account->id);
    }

    /**
     * Hand every message of one class on a transport to a callable, and empty
     * the transport.
     *
     * @param class-string $class
     */
    private function drain(string $transport, string $class, callable $handle): int
    {
        /** @var InMemoryTransport $queue */
        $queue   = static::getContainer()->get('messenger.transport.' . $transport);
        $handled = 0;

        foreach ($queue->getSent() as $envelope) {
            $message = $envelope->getMessage();

            if ($message instanceof $class) {
                $handle($message);
                ++$handled;
            }
        }

        $queue->reset();

        return $handled;
    }

    /** @return list<Message> */
    private function messages(): array
    {
        return static::getContainer()->get(MessageRepository::class)->findBy(['account' => $this->account->id]);
    }

    private function only(): Message
    {
        $messages = $this->messages();
        self::assertCount(1, $messages);

        return $messages[0];
    }
}
