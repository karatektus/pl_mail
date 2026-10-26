<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use App\Domain\Helper\ImapConnectionFactory;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\User\User;
use App\Jmap\State\StateManager;
use App\Repository\Label\LabelBindingRepository;
use App\Repository\Label\LabelRepository;
use App\Repository\Mail\MailboxRepository;
use App\Repository\Mail\MessageRepository;
use App\Service\Imap\MailboxSyncer;
use App\Service\Label\LabelResolver;
use App\Service\Mail\MessageEraser;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A sync mirrors the folder tree and does not reshape what it already mirrored.
 *
 * MailboxSyncer used to re-derive every folder's label from its path on every
 * run. That undid a rename made in plMail, and it let two folders that only
 * differ by the INBOX namespace take one label from each other on alternate
 * syncs. The path is now read when a folder arrives and when it moves, and at
 * no other time (#221).
 */
final class FolderLabelStabilityTest extends KernelTestCase
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

    /** A folder's label, renamed here, is still its label after the next sync. */
    public function testALabelRenamedInPlMailIsNotTakenBackByTheNextSync(): void
    {
        $this->sync(new FakeListingClient(['INBOX' => [], 'INBOX.Clients' => []]));

        $label = $this->mailboxFor('INBOX.Clients')?->label;
        self::assertNotNull($label);

        $label->name = 'Customers';
        $this->em->flush();

        $this->sync(new FakeListingClient(['INBOX' => [], 'INBOX.Clients' => []]));

        self::assertSame($label->id, $this->mailboxFor('INBOX.Clients')?->label?->id);
        self::assertSame('Customers', $this->mailboxFor('INBOX.Clients')?->label?->name);
    }

    /**
     * "Work" at the top and "Work" under INBOX are two folders and stay two:
     * the second keeps its INBOX segment, and neither moves on a resync.
     */
    public function testTwoFoldersThatOnlyDifferByTheInboxNamespaceKeepTwoLabels(): void
    {
        $listing = ['INBOX' => [], 'Work' => [], 'INBOX.Work' => []];

        $this->sync(new FakeListingClient($listing));

        $top   = $this->mailboxFor('Work')?->label;
        $inbox = $this->mailboxFor('INBOX.Work')?->label;

        self::assertNotNull($top);
        self::assertNotNull($inbox);
        self::assertNotSame($top->id, $inbox->id);
        self::assertSame('Work', $top->fullName);
        self::assertSame('INBOX/Work', $inbox->fullName);

        $this->sync(new FakeListingClient($listing));
        $this->sync(new FakeListingClient($listing));

        self::assertSame($top->id, $this->mailboxFor('Work')?->label?->id);
        self::assertSame($inbox->id, $this->mailboxFor('INBOX.Work')?->label?->id);
    }

    /**
     * A folder that moved on the server — renamed by another client, seen as
     * the same folder by its UIDVALIDITY — is labelled after where it is now.
     */
    public function testAFolderRenamedElsewhereGetsTheLabelOfItsNewPath(): void
    {
        $this->sync(new FakeListingClient(['INBOX' => [], 'INBOX.Clients' => []]));

        $mailbox = $this->mailboxFor('INBOX.Clients');
        self::assertNotNull($mailbox);
        $mailbox->uidValidity = 4242;
        $this->em->flush();

        $client = (new FakeListingClient(['INBOX' => [], 'INBOX.Customers' => []]))
            ->withStatus('INBOX.Customers', ['uidvalidity' => 4242]);

        $result = $this->sync($client);

        self::assertSame(1, $result['renamed']);
        self::assertSame($mailbox->id, $this->mailboxFor('INBOX.Customers')?->id, 'the row and its mail are kept');
        self::assertSame('Customers', $this->mailboxFor('INBOX.Customers')?->label?->name);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /**
     * @return array{created: int, updated: int, deleted: int, renamed: int, missing: int}
     */
    private function sync(FakeListingClient $client): array
    {
        $container = self::getContainer();

        $factory = self::createStub(ImapConnectionFactory::class);
        $factory->method('connect')->willReturn($client);

        // A resolver of its own per run, as in MailboxLabelNamingTest: its id
        // caches would otherwise answer the second sync from the first.
        $syncer = new MailboxSyncer(
            $container->get(MailboxRepository::class),
            $this->em,
            $factory,
            new LabelResolver(
                $container->get(LabelRepository::class),
                $container->get(LabelBindingRepository::class),
                $this->em,
                $container->get(StateManager::class),
            ),
            $container->get(MessageRepository::class),
            $container->get(MessageEraser::class),
            new NullLogger(),
        );

        return $syncer->syncForAccount($this->account);
    }

    private function mailboxFor(string $fullPath): ?Mailbox
    {
        return $this->em->getRepository(Mailbox::class)->findOneBy([
            'account'  => $this->account,
            'fullPath' => $fullPath,
        ]);
    }

    private function seedAccount(): Account
    {
        $user = new User();
        $user->email = 'folder-stability-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Folder';
        $user->nameLast = 'Stability';
        $user->roles = ['ROLE_USER'];
        $user->password = 'x';
        $this->em->persist($user);

        $account = new Account();
        $account->usr = $user;
        $account->email = 'folder-stability-fixture@example.test';
        $account->username = 'folder-stability-fixture@example.test';
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

        return $account;
    }
}
