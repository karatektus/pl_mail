<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use App\Domain\Helper\ImapConnectionFactory;
use App\Entity\Label\Label;
use App\Entity\Label\LabelBinding;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\User\User;
use App\Jmap\State\StateManager;
use App\Repository\Label\LabelBindingRepository;
use App\Repository\Label\LabelRepository;
use App\Repository\Mail\MailboxRepository;
use App\Service\Imap\MailboxSyncer;
use App\Service\Label\LabelResolver;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Config;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Support\FolderCollection;

/**
 * What a folder is called on the wire, and what it is called on screen.
 *
 * IMAP mailbox names travel as modified UTF-7 (RFC 3501 §5.1.3), so "Entwürfe"
 * is "Entw&APw-rfe" and "Gelöschte Objekte" is "Gel&APY-schte Objekte". The
 * label chain is built by splitting the folder's raw path, and those segments
 * became label names verbatim — so a German account's sidebar listed
 * "Entw&APw-rfe". Mailbox::$name was right the whole time; no template renders
 * it.
 *
 * The other half of this is the trap, and it is why the syncer is driven end to
 * end here rather than the splitter tested on its own: $fullPath must stay in
 * modified UTF-7. It is the folder's identity in the protocol — the syncer
 * indexes existing rows by it and hands it to SELECT — so "fixing" it to match
 * the label would stop every non-ASCII folder from being selectable at all.
 */
final class MailboxLabelNamingTest extends KernelTestCase
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

    /**
     * The library half of the story, pinned because everything below rests on
     * it: webklex leaves `path` raw and decodes `name` and `full_name`. If an
     * upgrade ever changes that, the syncer starts writing decoded paths into
     * the identity column and this is the test that says so.
     */
    public function testWebklexLeavesThePathRawAndDecodesTheName(): void
    {
        $folder = self::folder('INBOX.Entw&APw-rfe');

        self::assertSame('INBOX.Entw&APw-rfe', $folder->path);
        self::assertSame('INBOX.Entwürfe', $folder->full_name);
        self::assertSame('Entwürfe', $folder->name);
    }

    public function testAGermanFolderGetsAReadableLabel(): void
    {
        $this->sync(['INBOX.Entw&APw-rfe', 'INBOX.Gel&APY-schte Objekte']);

        self::assertSame('Entwürfe', $this->labelNameFor('INBOX.Entw&APw-rfe'));
        self::assertSame('Gelöschte Objekte', $this->labelNameFor('INBOX.Gel&APY-schte Objekte'));
    }

    /**
     * The regression that stops someone tidying this up later: the column that
     * looks wrong is the one that has to stay that way.
     */
    public function testTheStoredPathIsStillRawModifiedUtf7(): void
    {
        $this->sync(['INBOX.Entw&APw-rfe']);

        $mailbox = $this->mailboxFor('INBOX.Entw&APw-rfe');

        self::assertNotNull($mailbox, 'the mailbox must be findable by the path the server sent');
        self::assertSame(
            'INBOX.Entw&APw-rfe',
            $mailbox->getFullPath(),
            'fullPath is the folder identity SELECT takes — decoding it breaks folder selection',
        );

        // And the decoded name was available beside it all along.
        self::assertSame('Entwürfe', $mailbox->getName());
    }

    /**
     * Nested ASCII paths are the common case and must come through untouched,
     * one label per segment with the INBOX namespace prefix dropped.
     */
    public function testAnAsciiPathIsUnchanged(): void
    {
        $this->sync(['INBOX.Work.Invoices']);

        $label = $this->mailboxFor('INBOX.Work.Invoices')?->getLabel();

        self::assertNotNull($label);
        self::assertSame('Invoices', $label->name);
        self::assertSame('Work', $label->parent?->name);
    }

    /**
     * A second sync of the same tree must find the rows it made, which it can
     * only do if it is still looking them up by the raw path.
     */
    public function testResyncingDoesNotDuplicateTheMailbox(): void
    {
        $this->sync(['INBOX.Entw&APw-rfe']);
        $result = $this->sync(['INBOX.Entw&APw-rfe']);

        self::assertSame(0, $result['created']);
        self::assertSame(1, $result['updated']);
    }

    /**
     * The upgrade path, which this fix does not get to dodge: an account
     * synced before it has a label literally called "Entw&APw-rfe" already
     * bound to the folder, and the next sync resolves that same folder to a
     * different name.
     *
     * label_binding.mailbox_id is unique, so the new label cannot take the
     * folder until the old one lets go — and without that release this is not
     * a cosmetic problem, it is `duplicate key value violates unique
     * constraint` out of MailboxSyncer's flush, killing the folder sync for
     * exactly the accounts the decoding was fixed for.
     */
    public function testAnAccountSyncedBeforeTheFixResyncsWithoutColliding(): void
    {
        $this->sync(['INBOX.Entw&APw-rfe']);
        $this->corruptTheLabelName('INBOX.Entw&APw-rfe', 'Entw&APw-rfe');

        $this->sync(['INBOX.Entw&APw-rfe']);

        self::assertSame('Entwürfe', $this->labelNameFor('INBOX.Entw&APw-rfe'));
    }

    /**
     * And the old label is left standing, deliberately.
     *
     * It is not orphaned data — every message synced before the fix is
     * labelled with it, so deleting it during a sync would unlabel history to
     * tidy up a name. Repairing those rows is a rename, which is a backfill and
     * a decision for someone else to take.
     */
    public function testTheOldLabelIsNotDeletedByTheResync(): void
    {
        $this->sync(['INBOX.Entw&APw-rfe']);
        $this->corruptTheLabelName('INBOX.Entw&APw-rfe', 'Entw&APw-rfe');

        $this->sync(['INBOX.Entw&APw-rfe']);

        $stale = $this->em->getRepository(Label::class)->findOneBy([
            'usr'  => $this->account->getUsr(),
            'name' => 'Entw&APw-rfe',
        ]);

        self::assertNotNull($stale, 'the pre-fix label still holds the mail filed under it');

        $binding = $stale->bindings->first();

        self::assertInstanceOf(LabelBinding::class, $binding);
        self::assertNull($binding->mailbox, 'but it no longer feeds the folder');
    }

    // ── Fixtures ──────────────────────────────────────────────────────────

    /**
     * Put a label back into the shape the bug left it in. There is no way to
     * ask the fixed code for that state, so it is written directly.
     */
    private function corruptTheLabelName(string $fullPath, string $rawName): void
    {
        $label = $this->mailboxFor($fullPath)?->getLabel();

        self::assertNotNull($label);

        $label->name = $rawName;

        $this->em->flush();
        $this->em->clear();

        $this->account = $this->em->getRepository(Account::class)->find((int) $this->account->getId());
    }

    /**
     * @param list<string> $paths  folder names exactly as a LIST response gives them
     *
     * @return array{created: int, updated: int, deleted: int}
     */
    private function sync(array $paths): array
    {
        $container = self::getContainer();

        $client = self::client($paths);

        // A stub, not a mock: the syncer is expected to connect, but how often
        // is not the subject here, and asserting it would only pin an
        // implementation detail.
        $factory = self::createStub(ImapConnectionFactory::class);
        $factory->method('connect')->willReturn($client);

        // A LabelResolver of its own per call rather than the container's.
        // It caches label ids in memory for the life of the instance, so
        // sharing one across two syncs would answer the second from the first
        // and hide whatever the second would really have done — which is
        // exactly the question when the two runs disagree about a name.
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
        );

        return $syncer->syncForAccount($this->account);
    }

    /**
     * A client that answers getFolders() from a fixture and never opens a
     * socket. Folder itself is the real one, so the decoding under test is the
     * library's own and not a copy of it.
     *
     * @param list<string> $paths
     */
    private static function client(array $paths): Client
    {
        $client = new class(Config::make()) extends Client {
            /** @var list<string> */
            public array $paths = [];

            public function getFolders(bool $hierarchical = true, ?string $parent_folder = null, bool $soft_fail = false): FolderCollection
            {
                $folders = [];

                foreach ($this->paths as $path) {
                    $folders[] = new Folder($this, $path, '.', []);
                }

                return FolderCollection::make($folders);
            }

            /** Never connected, so there is nothing to log out of. */
            public function disconnect(): Client
            {
                return $this;
            }
        };

        $client->paths = $paths;

        return $client;
    }

    private static function folder(string $path): Folder
    {
        return new Folder(new Client(Config::make()), $path, '.', []);
    }

    private function mailboxFor(string $fullPath): ?Mailbox
    {
        return $this->em->getRepository(Mailbox::class)->findOneBy([
            'account'  => $this->account,
            'fullPath' => $fullPath,
        ]);
    }

    private function labelNameFor(string $fullPath): ?string
    {
        return $this->mailboxFor($fullPath)?->getLabel()?->name;
    }

    private function seedAccount(): Account
    {
        $user = new User();
        $user
            ->setEmail('mailbox-naming-' . uniqid('', true) . '@example.test')
            ->setNameFirst('Mailbox')
            ->setNameLast('Naming')
            ->setRoles(['ROLE_USER'])
            ->setPassword('x');
        $this->em->persist($user);

        $account = new Account();
        $account
            ->setUsr($user)
            ->setEmail('Mailbox Naming Fixture')
            ->setUsername('mailbox-naming-fixture@example.test')
            ->setImapHost('localhost')
            ->setImapPort(993)
            ->setImapEncryption('ssl')
            ->setSmtpHost('localhost')
            ->setSmtpPort(587)
            ->setSmtpEncryption('starttls')
            ->setPassword('x')
            ->setAuthType('password')
            ->setIsActive(true);
        $this->em->persist($account);

        $this->em->flush();

        return $account;
    }
}
