<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use App\Domain\Enum\Mail\MailboxSpecialUse;
use App\Domain\Exception\ImapFolderRefusedException;
use App\Domain\Exception\InvalidFolderNameException;
use App\Domain\Helper\ImapConnectionFactory;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\User\User;
use App\Repository\Mail\MailboxRepository;
use App\Service\Imap\ImapFolderManager;
use App\Service\Imap\ImapFolderProvisioner;
use App\Service\Label\LabelNotifier;
use App\Service\Label\LabelResolver;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The folder actions on a plain IMAP account (#221, step 1), against a server
 * that answers in memory.
 *
 * What is pinned is what goes on the wire and what is recorded here: the parent
 * is one the server listed, the separator is the parent's, the top level is the
 * server's personal namespace (RFC 2342), the name is modified UTF-7 exactly
 * once, and a refusal — plMail's or the server's — leaves nothing behind.
 */
final class ImapFolderManagerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private Account $account;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();
        $this->account = $this->seedAccount();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    // ── New folder ───────────────────────────────────────────────────────

    /**
     * Inside a folder the server listed, with that folder's separator, and
     * filed under that folder's label in the sidebar.
     */
    public function testANewFolderGoesInsideTheChosenFolder(): void
    {
        $server  = new FakeFolderServer('', '/');
        $clients = $this->folder('INBOX/Clients', 'Clients', '/');

        $mailbox = $this->manager($server)->create($this->account, $clients, 'Kine');

        self::assertSame(['CREATE INBOX/Clients/Kine', 'SUBSCRIBE INBOX/Clients/Kine'], $server->commands);
        self::assertSame('INBOX/Clients/Kine', $mailbox->fullPath);
        self::assertSame('Kine', $mailbox->name);
        self::assertSame('Kine', $mailbox->label?->name);
        self::assertSame('Clients', $mailbox->label->parent?->name, 'it hangs where the folder hangs');
    }

    /**
     * The top level is the server's personal namespace. On a Courier-style
     * server that is "INBOX." — a folder made beside INBOX there would be in a
     * namespace no other client shows, if the server took it at all.
     */
    public function testTheTopLevelIsThePersonalNamespace(): void
    {
        $this->folder('INBOX', 'INBOX', '.');

        $courier = new FakeFolderServer('INBOX.', '.');
        $this->manager($courier)->create($this->account, null, 'Kine');

        self::assertSame('CREATE INBOX.Kine', $courier->commands[0]);

        $plain = new FakeFolderServer('', '/');
        $this->manager($plain)->create($this->account, null, 'Receipts');

        self::assertSame('CREATE Receipts', $plain->commands[0]);
    }

    /** The name is encoded once; the parent's path, already encoded, is not touched. */
    public function testANonAsciiNameGoesOutAsModifiedUtf7(): void
    {
        $server = new FakeFolderServer('INBOX.', '.');
        $parent = $this->folder('INBOX.B&APw-cher', 'Bücher', '.');

        $mailbox = $this->manager($server)->create($this->account, $parent, 'Köln');

        self::assertSame('CREATE INBOX.B&APw-cher.K&APY-ln', $server->commands[0]);
        self::assertSame('Köln', $mailbox->name);
        self::assertSame('Köln', $mailbox->label?->name);
    }

    /** A name with the server's separator in it is refused before the server is asked. */
    public function testTheSeparatorInANameIsRefusedBeforeConnecting(): void
    {
        $server = new FakeFolderServer('INBOX.', '.');
        $parent = $this->folder('INBOX.Projects', 'Projects', '.');

        try {
            $this->manager($server)->create($this->account, $parent, 'v1.2');
            self::fail('a name with the separator in it was accepted');
        } catch (InvalidFolderNameException $e) {
            self::assertSame(InvalidFolderNameException::SEPARATOR, $e->reason);
            self::assertSame('.', $e->separator);
        }

        self::assertSame([], $server->commands);
    }

    /** The server's no comes back in its own words, and nothing is recorded. */
    public function testTheServersRefusalComesBackWithItsReason(): void
    {
        $server          = new FakeFolderServer('', '/');
        $server->refuse  = 'CREATE';
        $server->refusal = 'NO [OVERQUOTA] Too many folders';

        try {
            $this->manager($server)->create($this->account, null, 'Kine');
            self::fail('a refused CREATE was reported as done');
        } catch (ImapFolderRefusedException $e) {
            self::assertSame('[OVERQUOTA] Too many folders', $e->reason);
        }

        self::assertNull(
            self::getContainer()->get(MailboxRepository::class)->findOneBy(['account' => $this->account, 'fullPath' => 'Kine']),
        );
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function manager(FakeFolderServer $server): ImapFolderManager
    {
        $container = self::getContainer();

        $factory = self::createStub(ImapConnectionFactory::class);
        $factory->method('connect')->willReturn($server->client());

        return new ImapFolderManager(
            $factory,
            $container->get(ImapFolderProvisioner::class),
            $container->get(MailboxRepository::class),
            $container->get(LabelNotifier::class),
            $container->get(UrlGeneratorInterface::class),
            new NullLogger(),
        );
    }

    /** A folder as a sync would have left it: recorded and given its label. */
    private function folder(string $path, string $name, string $delimiter): Mailbox
    {
        $mailbox = new Mailbox();
        $mailbox->account       = $this->account;
        $mailbox->name          = $name;
        $mailbox->fullPath      = $path;
        $mailbox->delimiter     = $delimiter;
        $mailbox->isSyncEnabled = true;

        if ('INBOX' === $path) {
            $mailbox->specialUse = MailboxSpecialUse::INBOX;
        }

        $this->em->persist($mailbox);
        self::getContainer()->get(LabelResolver::class)->linkFolder($mailbox, $this->account);
        $this->em->flush();

        return $mailbox;
    }

    private function seedAccount(): Account
    {
        $user = new User();
        $user->email = 'folder-manager-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Folder';
        $user->nameLast = 'Manager';
        $user->roles = ['ROLE_USER'];
        $user->password = 'x';
        $this->em->persist($user);

        $account = new Account();
        $account->usr = $user;
        $account->email = 'folder-manager-fixture@example.test';
        $account->username = 'folder-manager-fixture@example.test';
        $account->imapHost = 'localhost';
        $account->imapPort = 993;
        $account->imapEncryption = 'ssl';
        $account->smtpHost = 'localhost';
        $account->smtpPort = 587;
        $account->smtpEncryption = 'starttls';
        $account->password = 'x';
        $account->authType = 'password';
        $account->isActive = true;
        $this->em->persist($account);

        $this->em->flush();
        $user->addAccount($account);
        $this->em->flush();

        return $account;
    }
}
