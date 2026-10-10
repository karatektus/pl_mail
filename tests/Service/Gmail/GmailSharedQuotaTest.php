<?php

declare(strict_types=1);
namespace App\Tests\Service\Gmail;
use App\Entity\Mail\Account;
use App\Service\Gmail\GmailQuotaPacer;
use App\Service\Gmail\GmailBatchRetryPolicy;
use App\Domain\Exception\GmailThrottledException;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;

final class GmailSharedQuotaTest extends KernelTestCase
{
    public function testIndependentWorkersAndDuplicateAccountsShareTheSameBudget(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        $db->executeStatement('DELETE FROM gmail_quota_state');
        $other = DriverManager::getConnection($db->getParams());
        $clock = new MockClock('2026-10-10 12:00:00 UTC');
        $workerA = new GmailQuotaPacer($clock, $db, '123-one.apps.googleusercontent.com');
        $workerB = new GmailQuotaPacer($clock, $other, '123-two.apps.googleusercontent.com');
        $a = $this->account(100, 'quota-synthetic@example.test');
        $b = $this->account(101, 'QUOTA-SYNTHETIC@example.test');
        $start = $clock->now();
        $workerA->spend($a, 1000);
        $workerB->spend($b, 1000);
        self::assertGreaterThanOrEqual(15, $clock->now()->getTimestamp() - $start->getTimestamp());
        self::assertSame($workerA->key($a), $workerB->key($b));
        self::assertSame(1, (int) $db->fetchOne('SELECT count(*) FROM gmail_quota_state'));
        $before = $clock->now();
        $workerB->spend($this->account(102, 'different@example.test'), 1000);
        self::assertEquals($before, $clock->now());
        $other->close();
    }

    public function testCooldownSurvivesWorkerRestartAndWarningsClearOnlyAfterFetchRecovery(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        $db->executeStatement('DELETE FROM gmail_quota_state');
        $clock = new MockClock('2026-10-10 13:00:00 UTC');
        $a = $this->account(103, 'cooldown@example.test');
        $workerA = new GmailQuotaPacer($clock, $db);
        $workerA->throttle($a, 120);
        $workerB = new GmailQuotaPacer($clock, $db);
        self::assertSame('throttled', $workerB->health($a)['warning']);
        try { $workerB->spend($a, 1); self::fail('Cooldown must stop every API method'); }
        catch (GmailThrottledException $error) { self::assertSame(120, $error->getRetryAfterSeconds()); }
        $clock->sleep(121);
        self::assertNull($workerB->health($a)['retryAt']);
        self::assertSame('throttled', $workerB->health($a)['warning']);
        $workerB->batchResult($a, ['a','b'], ['b'], []);
        $workerB->batchResult($a, ['other'], [], []);
        self::assertNotNull($workerB->health($a)['warning'], 'Unrelated success cannot hide missing mail');
        $workerB->batchResult($a, ['b'], [], []);
        self::assertNull($workerB->health($a)['warning']);
    }

    public function testPartialRetryBudgetHasBoundedExponentialJitterAndHonoursCooldown(): void
    {
        self::assertSame(1000, GmailBatchRetryPolicy::delay(0, null, 0));
        self::assertSame(16500, GmailBatchRetryPolicy::delay(4, null, 500));
        self::assertSame(120500, GmailBatchRetryPolicy::delay(1, 120, 500));
        self::assertNull(GmailBatchRetryPolicy::delay(5, 120, 500));
    }

    public function testLongLivedWorkerReadsChangedBudgetWithoutRestart(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class); $db->beginTransaction();
        try {
            $db->executeStatement('DELETE FROM gmail_quota_state');
            $db->executeStatement("DELETE FROM mail_provider_config WHERE provider = 'google'");
            $clock = new MockClock('2026-10-10 12:00:00 UTC');
            $worker = new GmailQuotaPacer($clock, $db);
            $account = $this->account(104, 'fresh-settings@example.test');
            $worker->spend($account, 1000);
            $db->executeStatement("INSERT INTO mail_provider_config (provider, settings, created_at, updated_at) VALUES ('google', ?, NOW(), NOW())", [json_encode(['gmail.quota_per_minute' => 3000, 'gmail.quota_headroom_percent' => 50])]);
            $worker->spend($account, 1000);
            self::assertGreaterThanOrEqual(120, $clock->now()->getTimestamp() - strtotime('2026-10-10 12:00:00 UTC'));
        } finally { $db->rollBack(); }
    }

    private function account(int $id, string $email): Account
    {
        $account = new Account();
        $account->email = $email;
        (new ReflectionProperty(Account::class, 'id'))->setValue($account, $id);
        return $account;
    }
}
