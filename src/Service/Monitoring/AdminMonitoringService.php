<?php

declare(strict_types=1);

namespace App\Service\Monitoring;

use App\Entity\Account;
use App\Repository\AccountRepository;
use App\Repository\ProcessHeartbeatRepository;
use Doctrine\DBAL\Connection;

/**
 * Read-model aggregation for the admin dashboard. Deliberately system-wide:
 * the admin view crosses user boundaries by design (self-hosted instance).
 */
final class AdminMonitoringService
{
    /** Seconds after which a heartbeat counts as stale, per process type. */
    private const array STALE_THRESHOLDS = [
        ProcessHeartbeatService::TYPE_IMAP_IDLE        => 2100, // just over the 29-min IDLE reissue
        ProcessHeartbeatService::TYPE_IMAP_SUPERVISE   => 300,
        ProcessHeartbeatService::TYPE_MESSENGER_WORKER => 120,  // listener beats every 30s
    ];

    private const int DEFAULT_STALE_THRESHOLD = 600;

    public function __construct(
        private readonly ProcessHeartbeatRepository $heartbeatRepository,
        private readonly AccountRepository          $accountRepository,
        private readonly Connection                 $connection,
    ) {}

    /**
     * @return list<array{type: string, key: string, pid: int|null, lastBeatAt: \DateTimeImmutable|null, meta: array<string,mixed>|null, ageSeconds: int|null, age: string, healthy: bool}>
     */
    public function heartbeats(): array
    {
        $rows = [];

        foreach ($this->heartbeatRepository->findAllOrdered() as $heartbeat) {
            $ageSeconds = null;

            if (null !== $heartbeat->lastBeatAt) {
                $ageSeconds = max(0, time() - $heartbeat->lastBeatAt->getTimestamp());
            }

            $threshold = self::STALE_THRESHOLDS[$heartbeat->type] ?? self::DEFAULT_STALE_THRESHOLD;

            $rows[] = [
                'type'       => $heartbeat->type,
                'key'        => $heartbeat->key,
                'pid'        => $heartbeat->pid,
                'lastBeatAt' => $heartbeat->lastBeatAt,
                'meta'       => $heartbeat->meta,
                'ageSeconds' => $ageSeconds,
                'age'        => $this->formatAge($ageSeconds),
                'healthy'    => null !== $ageSeconds && $ageSeconds < $threshold,
            ];
        }

        return $rows;
    }

    /**
     * Gmail push/webhook status for every account that has ever touched the
     * Gmail sync machinery. Detected via watch/history fields rather than the
     * provider enum so this stays decoupled from provider modelling.
     *
     * @return list<array{account: Account, watchActive: bool, watchExpiry: \DateTimeImmutable|null, lastPushAt: \DateTimeImmutable|null, lastPushAge: string, historyId: string|null}>
     */
    public function gmailWebhooks(): array
    {
        $rows = [];
        $now  = new \DateTimeImmutable();

        foreach ($this->accountRepository->findAll() as $account) {
            $watchExpiry  = $account->getGmailWatchExpiry();
            $resourceName = $account->getGmailWatchResourceName();
            $historyId    = $account->getGmailHistoryId();

            if (null === $watchExpiry && null === $resourceName && null === $historyId) {
                continue;
            }

            $lastPushAt  = $account->getGmailLastPushAt();
            $lastPushAge = null;

            if (null !== $lastPushAt) {
                $lastPushAge = max(0, $now->getTimestamp() - $lastPushAt->getTimestamp());
            }

            $rows[] = [
                'account'     => $account,
                'watchActive' => null !== $watchExpiry && $watchExpiry > $now,
                'watchExpiry' => $watchExpiry,
                'lastPushAt'  => $lastPushAt,
                'lastPushAge' => $this->formatAge($lastPushAge),
                'historyId'   => $historyId,
            ];
        }

        return $rows;
    }

    /**
     * OAuth token refresh health for accounts that have gone through the
     * token manager at least once.
     *
     * @return list<array{account: Account, lastRefreshAt: \DateTimeImmutable|null, lastRefreshAge: string, error: string|null, healthy: bool}>
     */
    public function tokenHealth(): array
    {
        $rows = [];

        foreach ($this->accountRepository->findAll() as $account) {
            $lastRefreshAt = $account->getOauthLastRefreshAt();
            $error         = $account->getOauthLastRefreshError();

            if (null === $lastRefreshAt && null === $error) {
                continue;
            }

            $age = null;

            if (null !== $lastRefreshAt) {
                $age = max(0, time() - $lastRefreshAt->getTimestamp());
            }

            $rows[] = [
                'account'        => $account,
                'lastRefreshAt'  => $lastRefreshAt,
                'lastRefreshAge' => $this->formatAge($age),
                'error'          => $error,
                'healthy'        => null === $error,
            ];
        }

        return $rows;
    }

    /**
     * Per-account sync overview: thread/message volume and last activity.
     * Messages attach to an account via mailbox (IMAP) or thread (Gmail API),
     * hence the OR join.
     *
     * @return list<array<string,mixed>>
     */
    public function accountOverview(): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT
                a.id,
                a.email,
                a.is_active,
                (SELECT COUNT(*) FROM message_thread t WHERE t.account_id = a.id) AS threads,
                (SELECT COUNT(*)
                   FROM message m
                   LEFT JOIN mailbox mb ON m.mailbox_id = mb.id
                   LEFT JOIN message_thread mt ON m.thread_id = mt.id
                  WHERE mb.account_id = a.id OR mt.account_id = a.id) AS messages,
                (SELECT MAX(t.last_message_at) FROM message_thread t WHERE t.account_id = a.id) AS last_activity
             FROM account a
             ORDER BY a.email',
        );
    }

    /**
     * @return list<array{table: string, size: string, bytes: int}>
     */
    public function tableSizes(int $limit = 12): array
    {
        $rows = $this->connection->fetchAllAssociative(
            "SELECT
                c.relname AS table_name,
                pg_size_pretty(pg_total_relation_size(c.oid)) AS pretty_size,
                pg_total_relation_size(c.oid) AS bytes
             FROM pg_class c
             JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE n.nspname = 'public' AND c.relkind = 'r'
             ORDER BY bytes DESC
             LIMIT :limit",
            ['limit' => $limit],
        );

        $sizes = [];

        foreach ($rows as $row) {
            $sizes[] = [
                'table' => (string) $row['table_name'],
                'size'  => (string) $row['pretty_size'],
                'bytes' => (int) $row['bytes'],
            ];
        }

        return $sizes;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function formatAge(?int $seconds): string
    {
        if (null === $seconds) {
            return '—';
        }

        if ($seconds < 60) {
            return $seconds . 's';
        }

        if ($seconds < 3600) {
            return intdiv($seconds, 60) . 'm ' . ($seconds % 60) . 's';
        }

        if ($seconds < 86400) {
            return intdiv($seconds, 3600) . 'h ' . intdiv($seconds % 3600, 60) . 'm';
        }

        return intdiv($seconds, 86400) . 'd ' . intdiv($seconds % 86400, 3600) . 'h';
    }
}
