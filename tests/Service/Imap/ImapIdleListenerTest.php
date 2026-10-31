<?php

declare(strict_types=1);

namespace App\Tests\Service\Imap;

use App\Domain\Enum\Mail\MailboxSpecialUse;
use App\Domain\Enum\Mail\SyncTrigger;
use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\User\User;
use App\Infrastructure\ImapClient\ImapArgument;
use App\Infrastructure\ImapClient\ImapConnection;
use App\Infrastructure\ImapClient\ImapCredentials;
use App\Infrastructure\ImapClient\ImapEndpoint;
use App\Infrastructure\ImapClient\ImapLoop;
use App\Infrastructure\ImapClient\ImapSecurity;
use App\Infrastructure\Messaging\Message\SyncImapMailboxMessage;
use App\Service\Imap\ImapIdleListener;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Mail delivered to a watched folder queues that folder's sync, in seconds.
 *
 * The listener end to end, against the test stack's Dovecot: an account as
 * a user would add it, the folder set read from the database, a connection
 * opened through ImapConnector, IDLE held, and a message appended by another
 * session. What comes out is the same SyncImapMailboxMessage app:imap:idle
 * used to queue, which is what everything downstream relies on.
 *
 * The append runs on the listener's own loop, as a blocking call. That is the
 * client's blocking mode doing what it is for — the listener's tasks go on
 * running underneath it — and it is why the test needs no second process.
 *
 * Skipped where IMAP_TEST_HOST names no server; see DovecotImapTest.
 */
final class ImapIdleListenerTest extends KernelTestCase
{
    private const int IMAP_PORT = 31143;

    private EntityManagerInterface $em;
    private ImapIdleListener $listener;
    private ImapLoop $loop;
    private InMemoryTransport $ingest;
    private string $host;
    private string $password;

    protected function setUp(): void
    {
        $host     = getenv('IMAP_TEST_HOST');
        $password = getenv('IMAP_TEST_PASSWORD');

        if (false === $host || '' === $host || false === $password) {
            self::markTestSkipped('Set IMAP_TEST_HOST and IMAP_TEST_PASSWORD to run against an IMAP server; the test stack does.');
        }

        self::bootKernel();

        $container      = static::getContainer();
        $this->host     = $host;
        $this->password = $password;
        $this->em       = $container->get(EntityManagerInterface::class);
        $this->listener = $container->get(ImapIdleListener::class);
        $this->loop     = $container->get(ImapLoop::class);

        $ingest = $container->get('messenger.transport.ingest');
        self::assertInstanceOf(InMemoryTransport::class, $ingest);
        $this->ingest = $ingest;

        // Never committed. The listener's own reads share this connection, so
        // they see the rows staged below.
        $this->em->getConnection()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->listener)) {
            $this->listener->stop(2.0);
        }

        if (isset($this->em) && true === $this->em->getConnection()->isTransactionActive()) {
            $this->em->getConnection()->rollBack();
        }

        parent::tearDown();
    }

    public function testMailArrivingInAWatchedFolderQueuesItsSync(): void
    {
        $username  = 'listener-' . bin2hex(random_bytes(6));
        $mailboxId = $this->watchedInbox($username);

        $this->listener->reconcile();
        $this->runUntil(fn (): bool => 0 < $this->listener->activeCount() && $this->isIdling($mailboxId));

        $this->append($username, "Subject: Hello\r\n\r\nNew mail.\r\n");

        $this->runUntil(fn (): bool => [] !== $this->syncsFor($mailboxId));

        $syncs = $this->syncsFor($mailboxId);

        self::assertCount(1, $syncs, 'one arrival, one sync');
        self::assertSame(SyncTrigger::Idle, $syncs[0]->trigger);
    }

    /**
     * A folder switched off is let go at the next reading, and its heartbeat
     * goes with it rather than going stale on the dashboard.
     */
    public function testAFolderThatNoLongerWantsIdleIsLetGo(): void
    {
        $mailboxId = $this->watchedInbox('listener-' . bin2hex(random_bytes(6)));

        $this->listener->reconcile();
        $this->runUntil(fn (): bool => $this->isIdling($mailboxId));

        $this->em->getConnection()->executeStatement('UPDATE mailbox SET is_idle_enabled = false WHERE id = ?', [$mailboxId]);

        $this->listener->reconcile();
        $this->runUntil(fn (): bool => false === $this->isIdling($mailboxId));

        self::assertFalse($this->isIdling($mailboxId));
    }

    private function watchedInbox(string $username): int
    {
        $user            = new User();
        $user->email     = 'listener-' . bin2hex(random_bytes(5)) . '@plmail.test';
        $user->nameFirst = 'IDLE';
        $user->nameLast  = 'Listener';
        $user->password  = 'x';
        $this->em->persist($user);

        $account                 = new Account();
        $account->usr            = $user;
        $account->email          = $username . '@plmail.test';
        $account->username       = $username;
        $account->password       = $this->password;
        $account->authType       = 'password';
        $account->imapHost       = $this->host;
        $account->imapPort       = self::IMAP_PORT;
        $account->imapEncryption = null;
        $account->smtpHost       = $this->host;
        $account->smtpPort       = 587;
        $account->smtpEncryption = 'starttls';
        $account->isActive       = true;
        $this->em->persist($account);

        $mailbox                = new Mailbox();
        $mailbox->account       = $account;
        $mailbox->name          = 'INBOX';
        $mailbox->fullPath      = 'INBOX';
        $mailbox->delimiter     = '/';
        $mailbox->specialUse    = MailboxSpecialUse::INBOX;
        $mailbox->isSyncEnabled = true;
        $mailbox->isIdleEnabled = true;
        $this->em->persist($mailbox);
        $this->em->flush();

        return (int) $mailbox->id;
    }

    private function append(string $username, string $message): void
    {
        $connection = ImapConnection::open($this->loop, new ImapEndpoint($this->host, self::IMAP_PORT, ImapSecurity::None));
        $connection->authenticate(new ImapCredentials($username, $this->password));
        $connection->command('APPEND', ImapArgument::string('INBOX'), ImapArgument::string($message));
        $connection->logout();
    }

    /** The heartbeat the listener writes once a folder is idling. */
    private function isIdling(int $mailboxId): bool
    {
        return false !== $this->em->getConnection()->fetchOne(
            "SELECT 1 FROM process_heartbeat WHERE type = 'imap-idle' AND beat_key = ?",
            [(string) $mailboxId],
        );
    }

    /** @return list<SyncImapMailboxMessage> */
    private function syncsFor(int $mailboxId): array
    {
        $syncs = [];

        foreach ($this->ingest->getSent() as $envelope) {
            $message = $envelope->getMessage();

            if ($message instanceof SyncImapMailboxMessage && $mailboxId === $message->mailboxId) {
                $syncs[] = $message;
            }
        }

        return $syncs;
    }

    private function runUntil(callable $condition): void
    {
        $until = microtime(true) + 10;

        while (false === $condition() && microtime(true) < $until) {
            $this->listener->run(0.2);
        }

        self::assertTrue($condition(), 'the listener got there within ten seconds');
    }
}
