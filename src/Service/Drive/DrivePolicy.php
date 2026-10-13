<?php

declare(strict_types=1);

namespace App\Service\Drive;

use App\Entity\User\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Who may use Drive, how much, and how big one file may be.
 *
 *  - Instance-wide (Admin → Drive): the default quota and the largest single
 *    file. Kept in var/drive/_settings.json, on the Drive volume, so it
 *    survives rebuilds and is included in app:backup with the files.
 *  - Per user (Admin → Users → edit): an own quota, or Drive switched off
 *    entirely for someone abusing it. Kept in the user's settings.
 *
 * A switched-off user cannot upload or make links, and every link they have
 * already shared stops working — that is the point of the switch.
 */
final class DrivePolicy
{
    public const string SETTING_QUOTA_MB = 'drive.quota_mb';
    public const string SETTING_DISABLED = 'drive.disabled';

    /** Used when nothing has been set in Admin → Drive. */
    private const int DEFAULT_MAX_FILE_MB = 2048;

    /** @var array{defaultQuotaMb:int, maxFileMb:int}|null */
    private ?array $instance = null;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        #[Autowire('%env(default:drive.quota_mb_default:int:DRIVE_QUOTA_MB)%')]
        private readonly int $envQuotaMb,
    ) {
    }

    // ── Instance-wide ───────────────────────────────────────────────────────

    /** @return array{defaultQuotaMb:int, maxFileMb:int} 0 quota = unlimited */
    public function instanceSettings(): array
    {
        if (null !== $this->instance) {
            return $this->instance;
        }

        $stored = [];
        $path = $this->settingsPath();
        if (is_file($path)) {
            $decoded = json_decode((string) @file_get_contents($path), true);
            $stored = is_array($decoded) ? $decoded : [];
        }

        return $this->instance = [
            'defaultQuotaMb' => max(0, (int) ($stored['defaultQuotaMb'] ?? $this->envQuotaMb)),
            'maxFileMb'      => max(1, (int) ($stored['maxFileMb'] ?? self::DEFAULT_MAX_FILE_MB)),
        ];
    }

    public function saveInstanceSettings(int $defaultQuotaMb, int $maxFileMb): void
    {
        $settings = ['defaultQuotaMb' => max(0, $defaultQuotaMb), 'maxFileMb' => max(1, $maxFileMb)];
        $path = $this->settingsPath();
        $dir = \dirname($path);
        if (false === is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        $tmp = $path . '.tmp';
        if (false === @file_put_contents($tmp, json_encode($settings, JSON_PRETTY_PRINT))) {
            throw new \RuntimeException(sprintf('Could not write %s', $path));
        }
        rename($tmp, $path);
        $this->instance = $settings;
    }

    /** The largest single file Drive accepts: the admin's limit, within what PHP allows. */
    public function maxFileBytes(): int
    {
        $admin = $this->instanceSettings()['maxFileMb'] * 1024 * 1024;
        $php = $this->phpLimitBytes();

        return $php > 0 ? min($admin, $php) : $admin;
    }

    /** What PHP itself will take in one upload (upload_max_filesize / post_max_size). */
    public function phpLimitBytes(): int
    {
        $limits = array_filter([
            self::toBytes((string) \ini_get('upload_max_filesize')),
            self::toBytes((string) \ini_get('post_max_size')),
        ]);

        return [] === $limits ? 0 : min($limits);
    }

    // ── Per user ────────────────────────────────────────────────────────────

    public function isDisabled(?User $user): bool
    {
        return null !== $user && true === (bool) $user->getSetting(self::SETTING_DISABLED, false);
    }

    /** The user's own quota in MB, or null when they follow the default. 0 = unlimited. */
    public function quotaOverrideMb(User $user): ?int
    {
        $value = $user->getSetting(self::SETTING_QUOTA_MB);

        return null === $value || '' === $value ? null : max(0, (int) $value);
    }

    /** Bytes this user may store, or null for no limit. */
    public function quotaBytes(User $user): ?int
    {
        $mb = $this->quotaOverrideMb($user) ?? $this->instanceSettings()['defaultQuotaMb'];

        return $mb > 0 ? $mb * 1024 * 1024 : null;
    }

    public function applyToUser(User $user, ?int $quotaMb, bool $disabled): void
    {
        $user->setSetting(self::SETTING_QUOTA_MB, null === $quotaMb ? null : max(0, $quotaMb));
        $user->setSetting(self::SETTING_DISABLED, $disabled);
    }

    private function settingsPath(): string
    {
        return $this->projectDir . '/var/drive/_settings.json';
    }

    private static function toBytes(string $value): int
    {
        $value = trim($value);
        $number = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $number * 1024 ** 3,
            'm' => $number * 1024 ** 2,
            'k' => $number * 1024,
            default => $number,
        };
    }
}
