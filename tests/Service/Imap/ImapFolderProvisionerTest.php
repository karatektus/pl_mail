<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use App\Domain\Enum\Mail\LabelRole;
use App\Domain\Enum\Mail\MailboxSpecialUse;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\User\User;
use App\Service\Imap\ImapFolderProvisioner;
use App\Service\Label\LabelResolver;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Throwable;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Connection\Protocols\ProtocolInterface;
use Webklex\PHPIMAP\Folder;

/**
 * Where a folder plMail creates on an IMAP server actually goes.
 *
 * Nothing in the codebase ever issued a CREATE. Gmail creates a label on demand
 * and Graph creates a folder on demand; IMAP archived into a folder that had to
 * already exist, and when it did not, the move resolved no destination and
 * stopped — the message left the inbox locally, the server never heard, and the
 * next sync put it back.
 *
 * The reason it is not a one-line fix is that "create a folder called Archive"
 * is not a well-defined instruction on IMAP. On a server with a top-level
 * personal namespace it means `Archive`; on the Courier-style INBOX-prefixed
 * layout this project already had to deal with — the namespace the
 * trash-duplication report came from — it means `INBOX.Archive`, and creating
 * `Archive` there makes a folder in a namespace the user's other clients do not
 * show. The mail moves somewhere nobody can see.
 *
 * So neither the prefix nor the separator is guessed or configured. Both are
 * read off the folders the account already has, which is the one source that is
 * definitionally right: whatever shape those are in is this server's shape.
 * These tests state an account's existing folders and assert where a new one
 * lands.
 */
final class ImapFolderProvisionerTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $connection;
    private ImapFolderProvisioner $provisioner;
    private LabelResolver $labels;

    private User $user;
    private Account $account;

    /** @var list<string> paths the fake server was asked to create */
    private array $created = [];

    private bool $serverAccepts = true;

    protected function setUp(): void
    {
        self::bootKernel();

        $container = self::getContainer();

        $this->em          = $container->get(EntityManagerInterface::class);
        $this->connection  = $container->get(Connection::class);
        $this->provisioner = $container->get(ImapFolderProvisioner::class);
        $this->labels      = $container->get(LabelResolver::class);

        $this->created       = [];
        $this->serverAccepts = true;

        $this->connection->beginTransaction();

        $this->seed();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    // ── the namespace ────────────────────────────────────────────────────

    /**
     * The layout the trash-duplication report arrived with: every folder lives
     * under INBOX, separated by a dot. A new folder has to go there too.
     */
    public function testAnInboxPrefixedServerGetsItsFolderUnderInbox(): void
    {
        $this->folder('INBOX', 'INBOX', '.');
        $this->folder('Sent', 'INBOX.Sent', '.');
        $this->folder('spambucket', 'INBOX.spambucket', '.');

        $path = $this->provisioner->ensureSpecialUse($this->account, $this->client(), MailboxSpecialUse::ARCHIVE);

        self::assertSame('INBOX.Archive', $path);
        self::assertSame(['INBOX.Archive'], $this->created);
    }

    /**
     * And the commoner layout: folders at the top level, so the prefix is
     * nothing at all. Creating INBOX.Archive here would be the mirror-image
     * mistake.
     */
    public function testATopLevelServerGetsItsFolderAtTheTopLevel(): void
    {
        $this->folder('INBOX', 'INBOX', '/');
        $this->folder('Sent', 'Sent', '/');

        $path = $this->provisioner->ensureSpecialUse($this->account, $this->client(), MailboxSpecialUse::ARCHIVE);

        self::assertSame('Archive', $path);
        self::assertSame(['Archive'], $this->created);
    }

    /**
     * The separator is the server's, not a convention. A dot server and a slash
     * server disagree about what a nested name even looks like.
     */
    public function testTheSeparatorComesFromTheServerNotFromAConvention(): void
    {
        $this->folder('INBOX', 'INBOX', '/');
        $this->folder('Sent', 'INBOX/Sent', '/');

        $path = $this->provisioner->ensureSpecialUse($this->account, $this->client(), MailboxSpecialUse::ARCHIVE);

        self::assertSame('INBOX/Archive', $path);
    }

    /**
     * One folder outside INBOX settles it: a server with a personal namespace
     * at the top level has no reason to put anything under INBOX, so a single
     * top-level folder outweighs any number of INBOX-prefixed ones.
     */
    public function testASingleTopLevelFolderSettlesTheNamespace(): void
    {
        $this->folder('INBOX', 'INBOX', '.');
        $this->folder('Sent', 'INBOX.Sent', '.');
        $this->folder('Archive2', 'Archive2', '.');

        $path = $this->provisioner->ensureSpecialUse($this->account, $this->client(), MailboxSpecialUse::ARCHIVE);

        self::assertSame('Archive', $path);
    }

    /**
     * A brand-new account with nothing but INBOX is the one case with no
     * evidence either way. The top level is both the commoner layout and the
     * recoverable mistake — a folder in the wrong namespace is visible and
     * deletable, whereas the alternative is refusing to archive at all.
     */
    public function testAnAccountWithNothingButInboxGetsTheTopLevel(): void
    {
        $this->folder('INBOX', 'INBOX', '.');

        $path = $this->provisioner->ensureSpecialUse($this->account, $this->client(), MailboxSpecialUse::ARCHIVE);

        self::assertSame('Archive', $path);
    }

    // ── the write-back ───────────────────────────────────────────────────

    /**
     * The half that stops this creating a duplicate of its own making. Without
     * a Mailbox row the move that prompted the CREATE has no local destination
     * to point at, and the next sync meets the folder as one it has never seen.
     */
    public function testACreatedFolderIsRecordedLocallyAndBoundToItsLabel(): void
    {
        $this->folder('INBOX', 'INBOX', '.');
        $this->folder('Sent', 'INBOX.Sent', '.');

        $this->provisioner->ensureSpecialUse($this->account, $this->client(), MailboxSpecialUse::ARCHIVE);

        $recorded = $this->em->getRepository(Mailbox::class)
            ->findOneBy(['account' => $this->account, 'fullPath' => 'INBOX.Archive']);

        self::assertNotNull($recorded, 'the folder exists in both places the moment the CREATE returns');
        self::assertSame(MailboxSpecialUse::ARCHIVE, $recorded->specialUse);
        self::assertSame('.', $recorded->delimiter);
        self::assertSame(
            $this->labels->systemLabel(LabelRole::Archive, $this->account)->id,
            $recorded->label?->id,
            'and it feeds the label the Archive view reads',
        );
    }

    /**
     * Nothing is created when plMail already has the folder. The provisioner is
     * the last step of resolution rather than the first.
     */
    public function testAnExistingSpecialUseFolderIsNotRecreated(): void
    {
        $this->folder('INBOX', 'INBOX', '.');
        $this->folder('Archive', 'INBOX.Archive', '.', MailboxSpecialUse::ARCHIVE);

        $path = $this->provisioner->ensureSpecialUse($this->account, $this->client(), MailboxSpecialUse::ARCHIVE);

        self::assertSame('INBOX.Archive', $path);
        self::assertSame([], $this->created);
    }

    // ── failure ──────────────────────────────────────────────────────────

    /**
     * A server that refuses the CREATE — no permission, a strange ACL, a quota
     * — leaves the caller exactly where it was before any of this existed.
     *
     * It must not throw. The caller is a Messenger handler, and an exception
     * here would have it re-attempt the same rejected CREATE on a schedule
     * forever, which is a worse outcome than the archive not happening.
     */
    public function testARefusedCreateReturnsNullRatherThanThrowing(): void
    {
        $this->folder('INBOX', 'INBOX', '.');
        $this->serverAccepts = false;

        $path = $this->provisioner->ensureSpecialUse($this->account, $this->client(), MailboxSpecialUse::ARCHIVE);

        self::assertNull($path);

        self::assertNull(
            $this->em->getRepository(Mailbox::class)
                ->findOneBy(['account' => $this->account, 'fullPath' => 'Archive']),
            'and nothing is recorded for a folder that was never made',
        );
    }

    // ── an exact path ────────────────────────────────────────────────────

    /**
     * A destination that came off a Mailbox row is used verbatim: the server
     * itself described it on an earlier LIST, so its namespace and separator
     * are already right and rebuilding them could only introduce a difference.
     */
    public function testAnExactPathIsCreatedWithoutBeingRebuilt(): void
    {
        $this->folder('INBOX', 'INBOX', '.');
        $this->folder('Projekte', 'INBOX.Projekte', '.');

        $path = $this->provisioner->ensureExactPath($this->account, $this->client(), 'INBOX.Projekte');

        self::assertSame('INBOX.Projekte', $path);
        self::assertSame(['INBOX.Projekte'], $this->created, 'the server is asked for exactly what the row named');
    }

    // ── fixture ───────────────────────────────────────────────────────────

    /**
     * A client whose createFolder writes down what it was asked for, and
     * refuses when the test says the server would.
     */
    private function client(): Client
    {
        $client = $this->createStub(Client::class);

        $client->method('createFolder')->willReturnCallback(
            function (string $path): Folder {
                if (false === $this->serverAccepts) {
                    throw new \RuntimeException('CREATE refused');
                }

                $this->created[] = $path;

                return $this->createStub(Folder::class);
            },
        );

        $client->method('getConnection')->willReturn($this->createStub(ProtocolInterface::class));
        $client->method('disconnect')->willReturnSelf();

        return $client;
    }

    private function folder(
        string             $name,
        string             $fullPath,
        string             $delimiter,
        ?MailboxSpecialUse $specialUse = null,
    ): Mailbox {
        $mailbox = new Mailbox();
        $mailbox->account       = $this->account;
        $mailbox->name          = $name;
        $mailbox->fullPath      = $fullPath;
        $mailbox->delimiter     = $delimiter;
        $mailbox->specialUse    = $specialUse;
        $mailbox->isSyncEnabled = true;
        $mailbox->isIdleEnabled = false;

        $this->em->persist($mailbox);
        $this->em->flush();

        return $mailbox;
    }

    private function seed(): void
    {
        $this->user = new User();
        $this->user->email = 'provision-' . uniqid('', true) . '@example.test';
        $this->user->nameFirst = 'Folder';
        $this->user->nameLast = 'Provisioner';
        $this->user->roles = ['ROLE_USER'];
        $this->user->password = 'x';
        $this->em->persist($this->user);

        $this->account = new Account();
        $this->account->usr            = $this->user;
        $this->account->email          = 'provision-fixture@example.test';
        $this->account->username       = 'provision-fixture@example.test';
        $this->account->imapHost       = 'localhost';
        $this->account->imapPort       = 993;
        $this->account->imapEncryption = 'ssl';
        $this->account->smtpHost       = 'localhost';
        $this->account->smtpPort       = 587;
        $this->account->smtpEncryption = 'starttls';
        $this->account->password       = 'x';
        $this->account->authType       = 'password';
        $this->account->isActive       = true;
        $this->em->persist($this->account);

        $this->em->flush();

        $this->user->addAccount($this->account);

        $this->em->flush();
    }
}
