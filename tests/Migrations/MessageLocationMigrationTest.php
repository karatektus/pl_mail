<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\User\User;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261029120000;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * That the migration gives every message with a folder exactly the location
 * its columns describe, and nothing else (#415).
 *
 * Run against rows written in SQL, as a deployed instance has them: the
 * mirror on Message would fill the table by itself for anything written
 * through the entity, and a test that went through it would pass whether the
 * backfill worked or not.
 */
final class MessageLocationMigrationTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();

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

    /**
     * Rolled back and re-applied inside this test's own transaction, so the
     * database it started with is the database it leaves.
     */
    public function testEveryMessageWithAFolderGetsItsLocation(): void
    {
        [$account, $inbox] = $this->seedFolder();

        $this->migrate('down');

        $located = $this->message($account, $inbox, 11);
        $moving  = $this->message($account, $inbox, null);
        $nowhere = $this->message($account, null, null);

        $this->migrate('up');

        self::assertSame([$inbox, 11], $this->locationOf($located));
        self::assertSame([$inbox, null], $this->locationOf($moving), 'a row waiting for its UID keeps its folder');
        self::assertNull($this->locationOf($nowhere), 'a row with no folder — Gmail, Graph, JMAP — gets no location');
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function migrate(string $direction): void
    {
        // The migrations directory is read by Doctrine's own loader, not by
        // Composer, so nothing has autoloaded the class by the time a test runs.
        require_once dirname(__DIR__, 2) . '/migrations/Version20261029120000.php';

        $migration = new Version20261029120000($this->connection, new NullLogger());
        $migration->{$direction}(new Schema());

        foreach ($migration->getSql() as $query) {
            $this->connection->prepare($query->getStatement())->executeStatement();
        }
    }

    /**
     * Through the entities: these tables are not what the migration changes.
     *
     * @return array{int, int} account id, mailbox id
     */
    private function seedFolder(): array
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);

        $user            = new User();
        $user->email     = 'location-' . uniqid('', true) . '@example.test';
        $user->nameFirst = 'Message';
        $user->nameLast  = 'Location';
        $user->roles     = ['ROLE_USER'];
        $user->password  = 'x';
        $em->persist($user);

        $account                 = new Account();
        $account->usr            = $user;
        $account->email          = 'location-' . uniqid('', true) . '@example.test';
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
        $em->persist($account);

        $mailbox                = new Mailbox();
        $mailbox->account       = $account;
        $mailbox->name          = 'INBOX';
        $mailbox->fullPath      = 'INBOX';
        $mailbox->isSyncEnabled = true;
        $mailbox->isIdleEnabled = false;
        $em->persist($mailbox);

        $em->flush();

        return [(int) $account->id, (int) $mailbox->id];
    }

    private function message(int $account, ?int $mailbox, ?int $uid): int
    {
        return $this->insert('message', [
            'account_id'      => $account,
            'mailbox_id'      => $mailbox,
            'imap_uid'        => $uid,
            'flags'           => '[]',
            'has_attachments' => 'false',
            'cancelled'       => 'false',
            'created_at'      => '2026-10-29 12:00:00',
            'updated_at'      => '2026-10-29 12:00:00',
        ]);
    }

    /**
     * @param array<string, scalar|null> $columns
     */
    private function insert(string $table, array $columns): int
    {
        return (int) $this->connection->fetchOne(
            sprintf(
                'INSERT INTO %s (%s) VALUES (%s) RETURNING id',
                $table,
                implode(', ', array_keys($columns)),
                implode(', ', array_fill(0, count($columns), '?')),
            ),
            array_values($columns),
        );
    }

    /**
     * @return array{int, int|null}|null
     */
    private function locationOf(int $message): ?array
    {
        $rows = $this->connection->fetchAllAssociative('SELECT mailbox_id, imap_uid FROM message_location WHERE message_id = ?', [$message]);

        self::assertLessThanOrEqual(1, count($rows), 'one location per row before any merge');

        if ([] === $rows) {
            return null;
        }

        return [(int) $rows[0]['mailbox_id'], null === $rows[0]['imap_uid'] ? null : (int) $rows[0]['imap_uid']];
    }
}
