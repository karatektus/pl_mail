<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Mail\Account;
use App\Entity\Mail\Mailbox;
use App\Entity\User\User;
use App\Repository\User\UserRepository;
use App\Service\Monitoring\ProcessHeartbeatService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The Processes card after #421: one `app:imap:listen` holds every IDLE
 * folder, but still beats one `imap-idle` row per folder.
 *
 * Listed as rows, those read as one process per folder, all with the same
 * PID. The card folds them into the listener's row as folder icons instead.
 * What is pinned here is that the fold loses nothing: a stale folder is still
 * red, still counts as down in the card's summary, and still shows up when
 * the listener's own row is missing — the case where hiding it would hide the
 * only sign that IDLE has stopped.
 *
 * Skips itself without the seeded admin, exactly as PageRendersTest does.
 */
final class ProcessesPanelTest extends WebTestCase
{
    private const string ADMIN_EMAIL = 'e2e-admin@plmail.test';

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $connection;
    private User $admin;

    protected function setUp(): void
    {
        $this->client = static::createClient();

        $container        = static::getContainer();
        $this->em         = $container->get(EntityManagerInterface::class);
        $this->connection = $container->get(Connection::class);

        $admin = $container->get(UserRepository::class)->findOneBy(['email' => self::ADMIN_EMAIL]);

        if (false === $admin instanceof User) {
            self::markTestSkipped('run `app:test:seed-user --admin` first');
        }

        $this->admin = $admin;
        $this->client->loginUser($admin);

        $this->connection->beginTransaction();
        $this->connection->executeStatement('DELETE FROM process_heartbeat');
    }

    protected function tearDown(): void
    {
        if (true === $this->connection->isTransactionActive()) {
            $this->connection->rollBack();
        }

        parent::tearDown();
    }

    public function testIdleFoldersAreIconsOnTheListenerRow(): void
    {
        $inbox = $this->mailbox('INBOX');
        $junk  = $this->mailbox('Junk');

        $this->beat(ProcessHeartbeatService::TYPE_IMAP_LISTEN, 'main', 0);
        $this->beat(ProcessHeartbeatService::TYPE_IMAP_IDLE, (string) $inbox->id, 10);
        $this->beat(ProcessHeartbeatService::TYPE_IMAP_IDLE, (string) $junk->id, 7230);

        $crawler = $this->client->request('GET', '/admin/live');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $card = $crawler->filter('details[data-admin--admin-panel-key-value="processes"]');
        $rows = $card->filter('table tbody tr');

        self::assertCount(1, $rows, 'the folders are not processes of their own');
        self::assertStringContainsString('imap-listen', $rows->text());

        $healthy = $rows->filter('[data-idle-folder="healthy"]');
        $stale   = $rows->filter('[data-idle-folder="stale"]');

        self::assertCount(1, $healthy);
        self::assertCount(1, $stale);
        self::assertStringContainsString('procs-panel@example.test · INBOX', (string) $healthy->attr('title'));
        self::assertStringContainsString('procs-panel@example.test · Junk', (string) $stale->attr('title'));
        // 7230s rather than a round hour: the page reads PHP's clock and the
        // beat was written with the database's, so the age can be a second off.
        self::assertStringContainsString('2h 0m', (string) $stale->attr('title'), 'a red folder says how long it has been quiet');

        self::assertStringContainsString('1 down', $card->filter('summary')->text(), 'a stale folder is a failure the card summary must show');
    }

    public function testWithoutTheListenerRowTheFolderRowsStayRows(): void
    {
        $inbox = $this->mailbox('INBOX');

        $this->beat(ProcessHeartbeatService::TYPE_IMAP_IDLE, (string) $inbox->id, 3600);

        $crawler = $this->client->request('GET', '/admin/live');

        $rows = $crawler->filter('details[data-admin--admin-panel-key-value="processes"] table tbody tr');

        self::assertCount(1, $rows);
        self::assertStringContainsString('imap-idle', $rows->text());
    }

    private function mailbox(string $name): Mailbox
    {
        $account = $this->em->getRepository(Account::class)->findOneBy(['email' => 'procs-panel@example.test']);

        if (false === $account instanceof Account) {
            $account                 = new Account();
            $account->usr            = $this->admin;
            $account->email          = 'procs-panel@example.test';
            $account->username       = 'procs-panel@example.test';
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
        }

        $mailbox                = new Mailbox();
        $mailbox->account       = $account;
        $mailbox->name          = $name;
        $mailbox->fullPath      = $name;
        $mailbox->isSyncEnabled = true;
        $mailbox->isIdleEnabled = true;
        $this->em->persist($mailbox);
        $this->em->flush();

        return $mailbox;
    }

    private function beat(string $type, string $key, int $secondsAgo): void
    {
        $this->connection->executeStatement(
            // NOW() as upsertBeat() writes it, so the age matches what a real
            // beat would show whatever the PHP and database clocks disagree on.
            'INSERT INTO process_heartbeat (type, beat_key, pid, last_beat_at)
             VALUES (:type, :key, 89, NOW() - make_interval(secs => :ago))',
            ['type' => $type, 'key' => $key, 'ago' => $secondsAgo],
        );
    }
}
