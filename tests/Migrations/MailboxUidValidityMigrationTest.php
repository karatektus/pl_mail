<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\User\User;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261016120000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * That mailbox.uid_validity is a BIGINT, and that the widening can be taken
 * back without failing on a value only the wider column could hold (#171).
 *
 * The forward half is read from the live catalogue rather than from the
 * migration's SQL, for the reason ReadReceiptColumnsMigrationTest gives: the
 * mapping validates and every in-memory test passes whether or not the
 * migration ran, and a deployed instance is the first to find out.
 *
 * The reverse half is run against rows. A plain `TYPE INT` is the obvious
 * down() and it refuses the whole statement as soon as one folder holds a
 * UIDVALIDITY above 2147483647 — which is exactly the folder this change is for.
 */
final class MailboxUidValidityMigrationTest extends KernelTestCase
{
    private const int ABOVE_INT  = 3064473417;
    private const int WITHIN_INT = 1700000000;

    private EntityManagerInterface $em;
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em         = self::getContainer()->get(EntityManagerInterface::class);
        $this->connection = self::getContainer()->get(Connection::class);

        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testTheColumnIsABigint(): void
    {
        self::assertSame('bigint', $this->columnType(), 'mailbox.uid_validity must hold an unsigned 32-bit UIDVALIDITY');
    }

    /**
     * Rolled back and re-applied inside this test's own transaction, so the
     * database it started with is the database it leaves.
     */
    public function testTheMigrationCanBeRolledBackOverAValueAnIntCannotHold(): void
    {
        [$large, $small] = $this->seedMailboxes();

        $this->migrate('down');

        self::assertSame('integer', $this->columnType());
        self::assertNull($this->uidValidityOf($large), 'a value an INT cannot hold is forgotten rather than failing the rollback');
        self::assertSame(self::WITHIN_INT, $this->uidValidityOf($small), 'and one it can hold is kept');

        $this->migrate('up');

        self::assertSame('bigint', $this->columnType());
        self::assertSame(self::WITHIN_INT, $this->uidValidityOf($small));
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function migrate(string $direction): void
    {
        // The migrations directory is read by Doctrine's own loader, not by
        // Composer, so nothing has autoloaded the class by the time a test runs.
        require_once dirname(__DIR__, 2) . '/migrations/Version20261016120000.php';

        $migration = new Version20261016120000($this->connection, new NullLogger());
        $migration->{$direction}(new Schema());

        foreach ($migration->getSql() as $query) {
            $this->connection->prepare($query->getStatement())->executeStatement();
        }

        $this->em->clear();
    }

    private function columnType(): string
    {
        return (string) $this->connection->fetchOne(
            'SELECT data_type FROM information_schema.columns WHERE table_name = :table AND column_name = :column',
            ['table' => 'mailbox', 'column' => 'uid_validity'],
        );
    }

    private function uidValidityOf(Mailbox $mailbox): ?int
    {
        $value = $this->connection->fetchOne('SELECT uid_validity FROM mailbox WHERE id = :id', ['id' => $mailbox->id]);

        return null === $value ? null : (int) $value;
    }

    /**
     * One folder whose UIDVALIDITY only a BIGINT holds, and one an INT holds.
     *
     * @return array{Mailbox, Mailbox}
     */
    private function seedMailboxes(): array
    {
        $user            = new User();
        $user->email     = 'uidvalidity-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Uid';
        $user->nameLast  = 'Validity';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $this->em->persist($user);

        $account                 = new Account();
        $account->usr            = $user;
        $account->email          = 'uidvalidity-' . uniqid('', true) . '@example.test';
        $account->username       = $account->email;
        $account->imapHost       = 'localhost';
        $account->imapPort       = 993;
        $account->imapEncryption = 'ssl';
        $account->smtpHost       = 'localhost';
        $account->smtpPort       = 587;
        $account->smtpEncryption = 'starttls';
        $account->password       = 'x';
        $account->authType       = 'password';
        $account->isActive       = true;
        $this->em->persist($account);

        $large = $this->mailbox($account, 'INBOX', self::ABOVE_INT);
        $small = $this->mailbox($account, 'Archive', self::WITHIN_INT);

        $this->em->flush();

        return [$large, $small];
    }

    private function mailbox(Account $account, string $path, int $uidValidity): Mailbox
    {
        $mailbox                = new Mailbox();
        $mailbox->account       = $account;
        $mailbox->name          = $path;
        $mailbox->fullPath      = $path;
        $mailbox->uidValidity   = $uidValidity;
        $mailbox->isSyncEnabled = true;
        $mailbox->isIdleEnabled = false;
        $this->em->persist($mailbox);

        return $mailbox;
    }
}
