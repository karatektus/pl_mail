<?php

declare(strict_types=1);

namespace App\Service\Monitoring;

use App\Entity\Mail\Account;
use App\Repository\Mail\AccountRepository;
use App\Repository\Monitoring\LogEntryRepository;
use App\Repository\Monitoring\PostgresStatusRepository;
use App\Repository\Monitoring\ProcessHeartbeatRepository;
use App\Service\Gmail\GmailPushSettings;
use App\Service\Push\PushSubscriptionRegistry;
use App\Service\Setup\PublicUrlSetting;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Read-model aggregation for the admin dashboard. Deliberately system-wide:
 * the admin view crosses user boundaries by design (self-hosted instance).
 */
final class AdminMonitoringService
{
    /**
     * Monolog's ERROR. Anything below it is the push path narrating itself;
     * at or above it, something did not work.
     */
    private const int PUSH_FAILURE_LEVEL = 400;

    /** Log messages the Gmail push path emits, in the order the path runs. */
    private const array PUSH_LOG_MESSAGES = [
        'GmailPushSubscriptionManager: watch failed, falling back to polling',
        'GmailPushSubscriptionManager: GMAIL_PUBSUB_TOPIC is not set, staying on polling',
        'GmailPush: rejected notification with bad or missing token',
        'GmailPush: unparseable envelope',
        'GmailPush: envelope carried no data',
        'GmailPush: data was not valid base64',
        'GmailPush: data was not valid JSON',
        'GmailPush: no emailAddress in payload',
    ];

    public function __construct(
        private readonly ProcessHeartbeatRepository $heartbeatRepository,
        private readonly AccountRepository          $accountRepository,
        private readonly LogEntryRepository         $logRepository,
        private readonly PostgresStatusRepository   $databaseStatus,
        private readonly PushSubscriptionRegistry   $pushRegistry,
        // Through the same resolvers the push path itself uses — the panel
        // exists to explain that path, so reading the raw environment here
        // showed placeholder values whenever the real ones were stored via
        // Admin → Integrations or the setup screen.
        private readonly GmailPushSettings          $gmailPushSettings,
        private readonly PublicUrlSetting           $publicUrl,
        #[Autowire(env: 'APP_DB_LOG_LEVEL')]
        private readonly string                     $dbLogLevel,
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

            $threshold = ProcessHeartbeatService::staleThreshold($heartbeat->type);

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
     * @return list<array{account: Account, watchActive: bool, watchExpiry: \DateTimeImmutable|null, lastPushAt: \DateTimeImmutable|null, lastPushAge: string, historyId: string|null, pushEnabled: bool, accountActive: bool, health: string, resourceName: string|null}>
     */
    public function gmailWebhooks(): array
    {
        $rows = [];
        $now  = new \DateTimeImmutable();

        foreach ($this->accountRepository->findAll() as $account) {
            $watchExpiry  = $account->gmailWatchExpiry;
            $resourceName = $account->gmailWatchResourceName;
            $historyId    = $account->gmailHistoryId;

            if (null === $watchExpiry && null === $resourceName && null === $historyId) {
                continue;
            }

            $lastPushAt  = $account->gmailLastPushAt;
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

                // A live watch on an account that is not actually armed for push
                // explains silence on its own: GmailPushController drops
                // notifications for accounts failing either of these.
                'pushEnabled'   => true === $account->pushEnabled,
                'accountActive' => true === $account->isActive,
                'health'        => $this->pushRegistry->health($account)->value,
                'resourceName'  => $resourceName,
            ];
        }

        return $rows;
    }

    /**
     * Instance-wide Gmail push wiring, for the failure mode the per-account
     * rows cannot explain: the watch registered fine and nothing arrives.
     *
     * Everything between users.watch and /gmail/push lives in Google Cloud, so
     * plMail cannot observe it directly. What it can do is show the values the
     * Cloud-side push subscription has to agree with — topic and endpoint —
     * and surface the log lines proving delivery was attempted and refused.
     *
     * A missing verification token is called out separately because it fails
     * closed and silently: the watch succeeds, Pub/Sub delivers, and every
     * notification is answered with a 403 the user never sees.
     *
     * @return array{topic: string, topicConfigured: bool, tokenConfigured: bool, publicUrl: string, publicUrlUsable: bool, endpoint: string, deliveryLogged: bool, events: list<array{message: string, level: string, count: int, lastAt: \DateTimeImmutable|null, age: string}>, lastFailure: array{message: string, context: array<string,mixed>|null, at: \DateTimeImmutable|null}|null}
     */
    public function gmailPushDiagnostics(): array
    {
        $topic = trim($this->gmailPushSettings->topic() ?? '');
        $token = trim($this->gmailPushSettings->verificationToken() ?? '');
        $base  = trim((string) $this->publicUrl->current());

        return [
            'topic'           => '' === $topic ? '—' : $topic,
            'topicConfigured' => '' !== $topic,
            'tokenConfigured' => '' !== $token,
            'publicUrl'       => '' === $base ? '—' : $base,
            'publicUrlUsable' => str_starts_with($base, 'https://'),
            'endpoint'        => $this->pushEndpoint($base, $token),

            // Successful deliveries log at info, which the DB handler drops
            // unless APP_DB_LOG_LEVEL is lowered. Without it, "no events" is
            // ambiguous, so the panel has to say which it is.
            'deliveryLogged' => in_array(strtolower($this->dbLogLevel), ['debug', 'info'], true),

            'events'      => $this->pushEvents(),
            'lastFailure' => $this->lastPushFailure(),
        ];
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
            $lastRefreshAt = $account->oauthLastRefreshAt;
            $error         = $account->oauthLastRefreshError;

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
        return $this->accountRepository->findSyncOverviewRows();
    }

    /**
     * @return list<array{table: string, size: string, bytes: int}>
     */
    public function tableSizes(int $limit = 12): array
    {
        $rows = $this->databaseStatus->tableSizes($limit);

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

    /**
     * The URL the Cloud push subscription must target, token included — that
     * pairing is exactly what silent 403s come down to, so showing the two
     * apart would defeat the point. Masked because the dashboard is a web page;
     * enough of the token is shown to compare against the Cloud console.
     */
    private function pushEndpoint(string $base, string $token): string
    {
        if ('' === $base) {
            return '—';
        }

        $url = rtrim($base, '/') . '/gmail/push';

        if ('' === $token) {
            return $url . '?token=' . '(not set)';
        }

        return $url . '?token=' . mb_substr($token, 0, 4) . '…' . mb_substr($token, -4);
    }

    /**
     * Counts per known push log message. Only messages at or above the DB log
     * threshold can appear, which is why deliveryLogged is reported alongside.
     *
     * @return list<array{message: string, level: string, count: int, lastAt: \DateTimeImmutable|null, age: string}>
     */
    private function pushEvents(): array
    {
        $rows = $this->logRepository->countsByMessage(self::PUSH_LOG_MESSAGES);

        $events = [];

        foreach ($rows as $row) {
            $lastAt = null === $row['last_at'] ? null : new \DateTimeImmutable((string) $row['last_at']);

            $events[] = [
                'message' => (string) $row['message'],
                'level'   => (string) $row['level_name'],
                'count'   => (int) $row['hits'],
                'lastAt'  => $lastAt,
                'age'     => $this->formatAge(
                    null === $lastAt ? null : max(0, time() - $lastAt->getTimestamp()),
                ),
            ];
        }

        return $events;
    }

    /**
     * The most recent watch failure with its context, which carries the
     * provider's own reason — the topic that was tried and Google's response
     * body. Saves a trip to the log browser for the one line that matters.
     *
     * @return array{message: string, context: array<string,mixed>|null, at: \DateTimeImmutable|null}|null
     */
    private function lastPushFailure(): ?array
    {
        $row = $this->logRepository->findLatestErrorStartingWith('GmailPush', self::PUSH_FAILURE_LEVEL);

        if (null === $row) {
            return null;
        }

        $context = $row['context'];

        if (is_string($context)) {
            $context = json_decode($context, true);
        }

        return [
            'message' => (string) $row['message'],
            'context' => is_array($context) ? $context : null,
            'at'      => null === $row['created_at'] ? null : new \DateTimeImmutable((string) $row['created_at']),
        ];
    }

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
