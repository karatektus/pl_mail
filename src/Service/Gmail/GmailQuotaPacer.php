<?php

declare(strict_types=1);

namespace App\Service\Gmail;

use App\Domain\Exception\GmailThrottledException;
use App\Entity\Mail\Account;
use Doctrine\DBAL\Connection;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/** Shared token bucket: its burst plus one minute of refill fit the configured budget. */
final class GmailQuotaPacer
{
    public const int UNITS_PER_MESSAGE = 20;
    public const int UNITS_PER_LIST_PAGE = 5;

    /** @var array<string,array<string,mixed>> Isolated unit-test fallback; production injects DBAL. */
    private array $memory = [];

    public function __construct(
        private readonly ClockInterface $clock,
        private readonly ?Connection $connection = null,
        #[Autowire('%env(GOOGLE_OAUTH_CLIENT_ID)%')]
        private readonly string $googleClientId = '',
    ) {}

    public function now(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }

    /** No secrets are read. Query fresh configuration so long-lived workers see edits. */
    private function configuration(): array
    {
        $row = $this->connection?->fetchAssociative("SELECT client_id, settings FROM mail_provider_config WHERE provider = 'google'");
        $settings = is_array($row) ? json_decode((string) $row['settings'], true) : [];
        $settings = is_array($settings) ? $settings : [];
        return [
            'client' => is_array($row) && !empty($row['client_id']) ? (string) $row['client_id'] : $this->googleClientId,
            'budget' => max(3000, min(15000, (int) ($settings['gmail.quota_per_minute'] ?? 6000))),
            'headroom' => max(0, min(50, (int) ($settings['gmail.quota_headroom_percent'] ?? 20))),
        ];
    }

    public function key(Account $account): string
    {
        $client = $this->configuration()['client'];
        // Google client IDs contain their project number. Separate app clients in that
        // project share a budget. Missing/nonstandard IDs use a conservative fallback.
        $project = preg_match('/^(\d+)-.+\.apps\.googleusercontent\.com$/', $client, $match) === 1
            ? $match[1] : ('' === $client ? 'unknown-project' : $client);
        $user = strtolower(trim($account->email ?? $account->username ?? ''));
        if ('' === $user) { $user = 'account:' . $account->id; }
        return hash('sha256', $project . "\0" . $user);
    }

    public function spend(Account $account, int $units): void
    {
        if ($units <= 0) { return; }
        if ($units > 1000) { throw new \InvalidArgumentException('Split Gmail requests into batches of at most 50 messages.'); }
        $key = $this->key($account);
        while (true) {
            $config = $this->configuration();
            $usable = $config['budget'] * (100 - $config['headroom']) / 100;
            $rate = ($usable - 1000) / 60;
            $wait = $this->mutate($key, function (array &$state) use ($units, $rate): float {
                $now = $this->now();
                if ((float) $state['blocked_until'] > $now) {
                    throw new GmailThrottledException('Gmail sync paused by a shared quota cooldown.', 429, 'localCooldown', (int) ceil((float) $state['blocked_until'] - $now));
                }
                // Clamp existing credit on a settings change; never create a fresh burst.
                $credit = min(1000.0, (float) $state['credits'] + max(0, $now - (float) $state['updated_at']) * $rate);
                $state['credits'] = $credit;
                $state['updated_at'] = max($now, (float) $state['updated_at']);
                if ($credit + 0.000001 < $units) { return ($units - $credit) / $rate; }
                $state['credits'] = max(0, $credit - $units);
                return 0.0;
            });
            if ($wait <= 0) { return; }
            // DB row lock is released before waiting. Recheck after another worker spends.
            $this->clock->sleep(min(60.0, $wait + 0.001));
        }
    }

    public function retryAfter(string $header): ?int
    {
        $header = trim($header);
        if (ctype_digit($header)) { return (int) $header; }
        $date = \DateTimeImmutable::createFromFormat('!D, d M Y H:i:s \G\M\T', $header, new \DateTimeZone('UTC'));
        return false === $date ? null : max(0, (int) ceil($date->getTimestamp() - $this->now()));
    }

    public function throttle(Account $account, int $seconds): void
    {
        $this->mutate($this->key($account), function (array &$state) use ($seconds): void {
            $state['blocked_until'] = max((float) $state['blocked_until'], $this->now() + max(1, $seconds));
            $state['warning'] = 'throttled';
        });
    }

    public function incomplete(Account $account, string $warning = 'incomplete'): void
    {
        $this->mutate($this->key($account), static function (array &$state) use ($warning): void { $state['warning'] = $warning; });
    }

    /** @param list<string> $ids */
    public function beginBatch(Account $account, array $ids): void
    {
        $this->mutate($this->key($account), static function (array &$state) use ($ids): void {
            $pending = json_decode((string) $state['pending_ids'], true);
            $state['pending_ids'] = json_encode(array_values(array_unique(array_merge(is_array($pending) ? $pending : [], $ids))), JSON_THROW_ON_ERROR);
        });
    }

    /** @param list<string> $requested @param list<string> $retryable @param list<string> $permanent */
    public function batchResult(Account $account, array $requested, array $retryable, array $permanent): void
    {
        $this->mutate($this->key($account), function (array &$state) use ($requested, $retryable, $permanent): void {
            $pending = json_decode((string) $state['pending_ids'], true);
            $pending = is_array($pending) ? $pending : [];
            $pending = array_values(array_unique(array_merge(array_diff($pending, $requested), $retryable, $permanent)));
            $state['pending_ids'] = json_encode($pending, JSON_THROW_ON_ERROR);
            if ([] !== $permanent) { $state['warning'] = 'permanent'; }
            elseif ([] !== $pending && null === $state['warning']) { $state['warning'] = 'incomplete'; }
            elseif ([] === $pending && (float) $state['blocked_until'] <= $this->now()) { $state['warning'] = null; }
        });
    }

    /** @return array{warning: ?string, retryAt: ?\DateTimeImmutable} */
    public function health(Account $account): array
    {
        $key = $this->key($account);
        $state = $this->connection?->fetchAssociative('SELECT warning, blocked_until FROM gmail_quota_state WHERE quota_key = ?', [$key]) ?? ($this->memory[$key] ?? false);
        $warning = is_array($state) && is_string($state['warning']) ? $state['warning'] : null;
        // Existing failures predating this shared table still deserve an immediate,
        // correctly worded warning. No retry deadline can be inferred from that text.
        if (null === $warning && null !== $account->lastSyncError) {
            if (preg_match('/userRateLimitExceeded|rateLimitExceeded|quotaExceeded|Gmail.+429/', $account->lastSyncError)) { $warning = 'throttled'; }
        }
        $until = is_array($state) ? (float) $state['blocked_until'] : 0;
        return ['warning' => $warning, 'retryAt' => $until > $this->now() ? new \DateTimeImmutable('@' . (int) ceil($until)) : null];
    }

    /**
     * @template T
     * @param callable(array<string,mixed>):T $change
     * @return T
     */
    private function mutate(string $key, callable $change): mixed
    {
        $initial = ['credits' => 1000.0, 'updated_at' => $this->now(), 'blocked_until' => 0.0, 'warning' => null, 'pending_ids' => '[]'];
        if (null === $this->connection) {
            $this->memory[$key] ??= $initial;
            return $change($this->memory[$key]);
        }
        return $this->connection->transactional(function (Connection $db) use ($key, $initial, $change): mixed {
            $db->executeStatement('INSERT INTO gmail_quota_state (quota_key, credits, updated_at, blocked_until, pending_ids) VALUES (?, ?, ?, 0, ?) ON CONFLICT DO NOTHING', [$key, $initial['credits'], $initial['updated_at'], '[]']);
            $state = $db->fetchAssociative('SELECT credits, updated_at, blocked_until, warning, pending_ids FROM gmail_quota_state WHERE quota_key = ? FOR UPDATE', [$key]);
            if (false === $state) { throw new \LogicException('Missing Gmail quota state.'); }
            $result = $change($state);
            $db->executeStatement('UPDATE gmail_quota_state SET credits = ?, updated_at = ?, blocked_until = ?, warning = ?, pending_ids = ? WHERE quota_key = ?', [$state['credits'], $state['updated_at'], $state['blocked_until'], $state['warning'], $state['pending_ids'], $key]);
            return $result;
        });
    }
}
