<?php

declare(strict_types=1);

namespace App\Tests\Service\Maintenance;

use App\Entity\Mail\Account;
use App\Entity\User\User;
use App\Service\Maintenance\DataResetter;
use App\Service\Maintenance\ResetStage;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * One rung of the reset ladder, against a real database.
 *
 * Mocking the connection would test that this class calls TRUNCATE, which is
 * not the question — the question is whether "delete the mail and the folders"
 * leaves the accounts and their stored passwords behind, and only Postgres can
 * answer that. Structure is the rung worth spending the test on: it is the
 * first one that deletes something beyond the mail, so it exercises both halves
 * of the boundary at once.
 *
 * It runs inside a transaction that tearDown rolls back. That is not tidiness:
 * TRUNCATE empties the whole table, not just the rows seeded here, so without
 * the rollback one run would destroy the seed the rest of the suite and the e2e
 * stack depend on. TRUNCATE is transactional in Postgres, which is the property
 * that makes testing this safely possible at all.
 *
 * fullReset() is deliberately not exercised. Its truncations would roll back
 * like everything else, but emptying var/attachments and rewriting the
 * generated secrets file would not — those are on disk, outside any
 * transaction, and a test that quietly deleted the secrets of the container it
 * runs in would be a worse bug than anything it could catch.
 */
final class DataResetterTest extends KernelTestCase
{
    private Connection $connection;
    private EntityManagerInterface $em;
    private DataResetter $resetter;

    private int $userId;
    private int $accountId;

    protected function setUp(): void
    {
        self::bootKernel();

        $container        = self::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);
        $this->resetter   = $container->get(DataResetter::class);

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

    public function testTheStructureRungDeletesExactlyWhatItPromises(): void
    {
        $scope = ResetStage::Structure->scope();

        self::assertNotNull($scope);

        $this->resetter->reset($scope);

        foreach (['message', 'message_thread', 'mailbox', 'label'] as $table) {
            self::assertSame(0, $this->countRows($table), $table . ' should have been emptied');
        }

        // The accounts are the point: getting these back means re-entering
        // every mailbox password, and the users behind them would lock the
        // operator out of the app they are resetting.
        foreach (['contact', 'account', 'email_alias', '"user"'] as $table) {
            self::assertGreaterThan(0, $this->countRows($table), $table . ' should have survived');
        }

        // Deleting the mail without forgetting where the sync got to would
        // leave the account asking its provider for "changes since" a point
        // whose messages no longer exist — a resync that fetches nothing, which
        // looks exactly like a broken account.
        $account = $this->connection->fetchAssociative(
            'SELECT gmail_history_id, last_synced_at FROM account WHERE id = ?',
            [$this->accountId],
        );

        self::assertIsArray($account);
        self::assertNull($account['gmail_history_id']);
        self::assertNull($account['last_synced_at']);

        // And foreign-key enforcement is put back. In the web process a session
        // is a pooled connection the next request inherits, so leaving it at
        // `replica` would silently turn every later write into one that skips
        // its constraints.
        self::assertSame('origin', $this->connection->fetchOne('SHOW session_replication_role'));
    }

    // ── Fixture ───────────────────────────────────────────────────────────────

    private function seed(): void
    {
        $suffix = uniqid('', true);

        $user = new User();
        $user->email = 'reset-' . $suffix . '@example.test';
        $user->nameFirst = 'Reset';
        $user->nameLast = 'Fixture';
        $user->roles = ['ROLE_USER'];
        $user->password = 'x';
        $this->em->persist($user);

        $account = new Account();
        $account
            ->setUsr($user)
            ->setEmail('Reset Fixture')
            ->setUsername('reset-' . $suffix . '@example.test')
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

        $this->userId    = (int) $user->id;
        $this->accountId = (int) $account->getId();

        // The rest goes in as rows rather than entities. Six entity APIs would
        // be six things this test knows about that have nothing to do with what
        // it asserts, and an explicit column list fails loudly when a NOT NULL
        // column is added — which is exactly when someone should be asked
        // whether the new table belongs in the reset.
        $now = '2026-08-01 09:00:00';

        $this->connection->executeStatement(
            'UPDATE account SET gmail_history_id = :history, last_synced_at = :now WHERE id = :id',
            ['history' => '4711', 'now' => $now, 'id' => $this->accountId],
        );

        $this->connection->executeStatement(
            'INSERT INTO mailbox (account_id, name, full_path, uid_validity, last_seen_uid, is_sync_enabled, is_idle_enabled, synced_at, created_at, updated_at)
             VALUES (:account, :name, :path, 1, 42, true, false, :now, :now, :now)',
            ['account' => $this->accountId, 'name' => 'INBOX', 'path' => 'INBOX', 'now' => $now],
        );

        $this->connection->executeStatement(
            'INSERT INTO label (usr_id, name, is_visible, created_at, updated_at)
             VALUES (:usr, :name, true, :now, :now)',
            ['usr' => $this->userId, 'name' => 'Reset fixture', 'now' => $now],
        );

        $this->connection->executeStatement(
            'INSERT INTO contact (usr_id, email, frequency, is_correspondent, first_seen_at, last_seen_at, created_at, updated_at)
             VALUES (:usr, :email, 1, false, :now, :now, :now, :now)',
            ['usr' => $this->userId, 'email' => 'someone-' . $suffix . '@example.test', 'now' => $now],
        );

        $threadId = (int) $this->connection->fetchOne(
            'INSERT INTO message_thread (account_id, subject, normalized_subject, threading_method, message_count, unread_count, attachment_count)
             VALUES (:account, :subject, :subject, :method, 1, 0, 0) RETURNING id',
            ['account' => $this->accountId, 'subject' => 'Reset fixture', 'method' => 'references'],
        );

        $this->connection->executeStatement(
            'INSERT INTO message (account_id, thread_id, subject, has_attachments, flags, cancelled, created_at, updated_at)
             VALUES (:account, :thread, :subject, false, :flags, false, :now, :now)',
            ['account' => $this->accountId, 'thread' => $threadId, 'subject' => 'Reset fixture', 'flags' => '[]', 'now' => $now],
        );

        $this->connection->executeStatement(
            'INSERT INTO email_alias (account_id, address, status, source, created_at, updated_at)
             VALUES (:account, :address, :status, :source, :now, :now)',
            [
                'account' => $this->accountId,
                'address' => 'alias-' . $suffix . '@example.test',
                'status'  => 'active',
                'source'  => 'manual',
                'now'     => $now,
            ],
        );
    }

    private function countRows(string $table): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM ' . $table);
    }
}
