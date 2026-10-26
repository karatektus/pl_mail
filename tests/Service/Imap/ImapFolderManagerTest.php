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
use App\Domain\Exception\FolderActionRefusedException;
use App\Entity\Label\Label;
use App\Infrastructure\Messaging\Message\SyncAccountMessage;
use App\Repository\Mail\MailboxRepository;
use App\Repository\Mail\MessageRepository;
use App\Repository\Mail\MessageThreadRepository;
use App\Service\Imap\ImapFolderManager;
use App\Service\Imap\ImapFolderProvisioner;
use App\Service\Label\LabelNotifier;
use App\Service\Label\LabelResolver;
use App\Service\Mail\MailChangeRecorder;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
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

    /** Writes down what the actions dispatched, in `sent`. */
    private MessageBusInterface $bus;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $this->connection->beginTransaction();
        $this->account = $this->seedAccount();

        $this->bus = new class implements MessageBusInterface {
            /** @var list<object> */
            public array $sent = [];

            public function dispatch(object $message, array $stamps = []): Envelope
            {
                $this->sent[] = $message;

                return new Envelope($message);
            }
        };
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

    // ── Rename ───────────────────────────────────────────────────────────

    /**
     * IMAP RENAME, raw paths both ways; the folders inside follow on the
     * server and so follow here, the label keeps its mail under the new name,
     * and a sync is asked for because the server may have given the renamed
     * folders a new UIDVALIDITY.
     */
    public function testRenameMovesTheFolderAndItsChildrenAndKeepsTheLabel(): void
    {
        $server  = new FakeFolderServer('', '/');
        $clients = $this->folder('INBOX/Clients', 'Clients', '/');
        $acme    = $this->folder('INBOX/Clients/Acme', 'Acme', '/');
        $label   = $clients->label;

        $this->manager($server)->rename($this->account, $clients, 'Kunden');

        self::assertSame('RENAME INBOX/Clients INBOX/Kunden', $server->commands[0]);
        self::assertSame('INBOX/Kunden', $clients->fullPath);
        self::assertSame('Kunden', $clients->name);
        self::assertSame('INBOX/Kunden/Acme', $acme->fullPath, 'the server renamed it along');
        self::assertSame($label?->id, $clients->label?->id, 'the same label, so what is filed under it stays');
        self::assertSame('Kunden', $clients->label?->name);
        self::assertSame('Kunden', $acme->label?->parent?->name);
        self::assertInstanceOf(SyncAccountMessage::class, $this->bus->sent[0] ?? null);
    }

    public function testANonAsciiRenameGoesOutAsModifiedUtf7(): void
    {
        $server  = new FakeFolderServer('INBOX.', '.');
        $folder  = $this->folder('INBOX.Books', 'Books', '.');

        $this->manager($server)->rename($this->account, $folder, 'Bücher');

        self::assertSame('RENAME INBOX.Books INBOX.B&APw-cher', $server->commands[0]);
        self::assertSame('Bücher', $folder->label?->name);
    }

    /**
     * The inbox and the special-use folders are never renamed: other clients
     * and plMail's own sending, archiving and spam handling find them by name
     * or by attribute.
     */
    public function testSpecialUseFoldersAreNeitherRenamedNorDeleted(): void
    {
        $server = new FakeFolderServer('', '/');
        $sent   = $this->folder('Sent Items', 'Sent Items', '/', MailboxSpecialUse::SENT);
        $inbox  = $this->folder('INBOX', 'INBOX', '/');

        foreach ([$sent, $inbox] as $folder) {
            foreach (['rename', 'delete'] as $action) {
                try {
                    'rename' === $action
                        ? $this->manager($server)->rename($this->account, $folder, 'Other')
                        : $this->manager($server)->delete($this->account, $folder);
                    self::fail($action . ' of ' . $folder->fullPath . ' was allowed');
                } catch (FolderActionRefusedException $e) {
                    self::assertSame(FolderActionRefusedException::SPECIAL_USE, $e->reason);
                }
            }
        }

        self::assertSame([], $server->commands, 'and the server was never asked');
    }

    // ── Delete ───────────────────────────────────────────────────────────

    /**
     * An empty folder goes on the server and here, and its label with it when
     * nothing else is left on the label.
     */
    public function testAnEmptyFolderIsDeletedAndItsLeftOverLabelWithIt(): void
    {
        $server  = new FakeFolderServer('', '/');
        $empty   = $this->folder('Empty', 'Empty', '/');
        $labelId = $empty->label?->id;
        $id      = $empty->id;

        $this->manager($server)->delete($this->account, $empty);

        self::assertSame(['STATUS Empty', 'DELETE Empty', 'UNSUBSCRIBE Empty'], $server->commands);
        self::assertNull($this->em->find(Mailbox::class, $id));
        self::assertNull($this->em->find(Label::class, $labelId));
    }

    /** Mail the server still holds is mail, imported here or not. */
    public function testAFolderTheServerStillHasMailInIsNotDeleted(): void
    {
        $server = new FakeFolderServer('', '/');
        $server->messages['Archive2'] = 3;
        $folder = $this->folder('Archive2', 'Archive2', '/');

        try {
            $this->manager($server)->delete($this->account, $folder);
            self::fail('a folder with mail in it was deleted');
        } catch (FolderActionRefusedException $e) {
            self::assertSame(FolderActionRefusedException::NOT_EMPTY, $e->reason);
            self::assertSame(3, $e->count);
        }

        self::assertSame(['STATUS Archive2'], $server->commands);
    }

    public function testAFolderWithFoldersInsideIsNotDeleted(): void
    {
        $server   = new FakeFolderServer('', '/');
        $projects = $this->folder('Projects', 'Projects', '/');
        $this->folder('Projects/2025', '2025', '/');

        $this->expectExceptionObject(new FolderActionRefusedException(FolderActionRefusedException::HAS_CHILDREN));

        $this->manager($server)->delete($this->account, $projects);
    }

    /** The server's no to the DELETE is shown, and the row stays. */
    public function testTheServersRefusalToDeleteKeepsTheFolder(): void
    {
        $server          = new FakeFolderServer('', '/');
        $server->refuse  = 'DELETE';
        $server->refusal = 'NO [CANNOT] Mailbox is in use';
        $folder          = $this->folder('Busy', 'Busy', '/');

        try {
            $this->manager($server)->delete($this->account, $folder);
            self::fail('a refused DELETE was reported as done');
        } catch (ImapFolderRefusedException $e) {
            self::assertSame('[CANNOT] Mailbox is in use', $e->reason);
        }

        self::assertNotNull($this->em->find(Mailbox::class, $folder->id));
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
            $container->get(MessageRepository::class),
            $container->get(MessageThreadRepository::class),
            $container->get(LabelResolver::class),
            $container->get(MailChangeRecorder::class),
            $container->get(LabelNotifier::class),
            $this->bus,
            $container->get(UrlGeneratorInterface::class),
            $this->em,
            new NullLogger(),
        );
    }

    /** A folder as a sync would have left it: recorded and given its label. */
    private function folder(string $path, string $name, string $delimiter, ?MailboxSpecialUse $specialUse = null): Mailbox
    {
        $mailbox = new Mailbox();
        $mailbox->account       = $this->account;
        $mailbox->name          = $name;
        $mailbox->fullPath      = $path;
        $mailbox->delimiter     = $delimiter;
        $mailbox->isSyncEnabled = true;

        $mailbox->specialUse    = 'INBOX' === $path ? MailboxSpecialUse::INBOX : $specialUse;

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
