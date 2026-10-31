<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Domain\Enum\Account\AuthType;
use App\Domain\Enum\Mail\MailboxSpecialUse;
use App\Domain\Enum\Mail\MessageFlag;
use App\Domain\Enum\Mail\ThreadingMethod;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\Mail\Message;
use App\Entity\Mail\MessageThread;
use App\Entity\User\User;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261028120000;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * That every stored flag list comes out exactly as MessageFlag::canonicalList()
 * would write it, so a stored list can be compared as it is; and that an IMAP
 * draft still in Drafts opens in the editor, while one that is not stays
 * refused.
 *
 * The rewrite is checked against canonicalList() itself rather than against
 * expected lists written out here: the claim is that the SQL and the PHP agree,
 * and a list written out by hand would only check the SQL against this file.
 *
 * Run against rows, as SyncedDraftSentAtMigrationTest is, and prepared, as
 * Doctrine Migrations runs it.
 *
 * One thing this cannot catch: the test database is Alpine's Postgres, whose
 * musl `en_US.utf8` already sorts bytewise, so dropping the migration's
 * COLLATE "C" passes here and would not on a glibc-built Postgres, where
 * `$Junk` sorts after `\Deleted`.
 */
final class CanonicalFlagsMigrationTest extends KernelTestCase
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

        $this->account = $this->seedAccount($this->seedUser());
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    /** @return iterable<string, array{list<string>}> */
    public static function storedLists(): iterable
    {
        yield 'webklex spelling'            => [['Seen', 'Draft']];
        yield 'webklex with \\Recent'       => [['Recent', 'Seen', '\\Recent']];
        yield 'already canonical'           => [['$Forwarded', '\\Answered', '\\Seen']];
        yield 'unsorted, as addFlag left it' => [['\\Seen', '\\Flagged', '\\Draft']];
        yield 'both spellings at once'      => [['\\Seen', 'Seen', 'SEEN', 'flagged']];
        yield 'keywords kept verbatim'      => [['$Junk', 'NonJunk', '$label1', '\\Deleted', 'Deleted']];
        yield 'a backslash keyword kept'    => [['\\Unknown', 'Seen']];
        yield 'blank entries dropped'       => [['', '\\', 'Answered']];
        yield 'empty'                       => [[]];
    }

    /** @param list<string> $flags */
    #[DataProvider('storedLists')]
    public function testEveryListComesOutAsCanonicalListWritesIt(array $flags): void
    {
        $message = $this->seed($flags, null);

        $this->migrate();

        self::assertSame(MessageFlag::canonicalList($flags), $this->flagsOf($message));
    }

    public function testAnImapDraftStillInDraftsLosesItsSentAt(): void
    {
        $draft = $this->seed(['Draft', 'Seen'], MailboxSpecialUse::DRAFTS);

        $this->migrate();

        self::assertNull($this->sentAtOf($draft), 'a draft on the server, and now one here');
    }

    public function testAMessageFlaggedDraftOutsideDraftsKeepsItsSentAt(): void
    {
        $sent = $this->seed(['Draft', 'Seen'], MailboxSpecialUse::SENT);

        $this->migrate();

        self::assertNotNull($this->sentAtOf($sent), 'filed in Sent: clearing it would make sent mail sendable again');
    }

    /** Rows already spelled `\Draft` were not written by webklex and were handled by Version20261015130000. */
    public function testADraftAlreadySpelledTheNewWayIsLeftToTheEarlierMigration(): void
    {
        $draft = $this->seed([MessageFlag::DRAFT->value], MailboxSpecialUse::DRAFTS);

        $this->migrate();

        self::assertNotNull($this->sentAtOf($draft));
    }

    public function testAMessageInDraftsThatIsNotFlaggedADraftKeepsItsSentAt(): void
    {
        $message = $this->seed(['Seen'], MailboxSpecialUse::DRAFTS);

        $this->migrate();

        self::assertNotNull($this->sentAtOf($message));
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function migrate(): void
    {
        // The migrations directory is read by Doctrine's own loader, not by
        // Composer, so nothing has autoloaded the class by the time a test runs.
        require_once dirname(__DIR__, 2) . '/migrations/Version20261028120000.php';

        $migration = new Version20261028120000($this->connection, new NullLogger());
        $migration->up(new Schema());

        foreach ($migration->getSql() as $query) {
            $this->connection->prepare($query->getStatement())->executeStatement();
        }

        $this->em->clear();
    }

    /** @return list<string> */
    private function flagsOf(Message $message): array
    {
        $flags = json_decode((string) $this->connection->fetchOne('SELECT flags FROM message WHERE id = :id', ['id' => $message->id]), true);

        self::assertIsArray($flags);

        return $flags;
    }

    private function sentAtOf(Message $message): mixed
    {
        return $this->connection->fetchOne('SELECT sent_at FROM message WHERE id = :id', ['id' => $message->id]);
    }

    /**
     * A row as webklex stored it: its flags as given, its Date header as its
     * sentAt, in a folder of the given kind.
     *
     * @param list<string> $flags
     */
    private function seed(array $flags, ?MailboxSpecialUse $folder): Message
    {
        $at = new DateTimeImmutable('-1 hour');

        $mailbox                = new Mailbox();
        $mailbox->account       = $this->account;
        $mailbox->name          = null === $folder ? 'INBOX' : $folder->name;
        $mailbox->fullPath      = $mailbox->name;
        $mailbox->specialUse    = $folder;
        $mailbox->isSyncEnabled = true;

        $thread                    = new MessageThread();
        $thread->account           = $this->account;
        $thread->subject           = 'Flags';
        $thread->normalizedSubject = 'flags';
        $thread->threadingMethod   = ThreadingMethod::SubjectFallback;
        $thread->lastMessageAt     = $at;

        $message                 = new Message();
        $message->account        = $this->account;
        $message->mailbox        = $mailbox;
        $message->thread         = $thread;
        $message->subject        = 'Flags';
        $message->fromAddress    = 'flags@example.test';
        $message->receivedAt     = $at;
        $message->sentAt         = $at;
        $message->hasAttachments = false;
        $message->syncedAt       = new DateTimeImmutable();

        $thread->addMessage($message);

        $this->em->persist($mailbox);
        $this->em->persist($thread);
        $this->em->persist($message);
        $this->em->flush();

        // Written past the entity, so the row holds the list exactly as given,
        // whatever the entity would make of it now.
        $this->connection->executeStatement(
            'UPDATE message SET flags = :flags WHERE id = :id',
            ['flags' => json_encode($flags, JSON_THROW_ON_ERROR), 'id' => $message->id],
        );

        return $message;
    }

    private function seedAccount(User $user): Account
    {
        $account                 = new Account();
        $account->usr            = $user;
        $account->name           = 'Flags fixture';
        $account->email          = 'flags-' . uniqid('', true) . '@example.test';
        $account->username       = uniqid('flags-', true);
        $account->imapHost       = 'imap.example.test';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        $account->authType       = AuthType::Password->value;
        $account->isActive       = true;

        $this->em->persist($account);
        $this->em->flush();

        return $account;
    }

    private function seedUser(): User
    {
        $user            = new User();
        $user->email     = 'flag-migration-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Flag';
        $user->nameLast  = 'Migration';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';

        $this->em->persist($user);
        $this->em->flush();

        return $user;
    }
}
