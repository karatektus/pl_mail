<?php

declare(strict_types=1);

namespace App\Domain\Helper;

/**
 * A user's own logo, as the SVG they uploaded after SvgSanitizer was done with
 * it (#48).
 *
 * Kept under the same per-user key as the installed app's PNGs
 * (AppIconStorage::keyFor()), for the same reason: the tab icon and the
 * manifest are fetched without credentials, so the URL has to say whose logo
 * it is, and it says so without saying which user id that is.
 *
 * One logo per user, named by a hash of its contents. The hash is in every URL
 * the logo appears under, so those URLs can be cached for a year: a new upload
 * is a new name, and nothing ever has to be revalidated to find it. The
 * previous file goes when a new one arrives, because only the current one is
 * ever linked.
 *
 * Next to app-icons/ rather than under uploads/, which the weekly blob prune
 * sweeps for files no row names (#214).
 */
readonly class CustomLogoStorage
{
    /** A logo's name as the URL spells it; the routes' requirement. */
    public const string HASH_PATTERN = '[0-9a-f]{16}';

    public function __construct(
        private AppIconStorage $appIcons,
        private string         $projectDir,
        private string         $storageDir,
    ) {
    }

    /**
     * Keep $svg (already sanitised) as the user's logo, replacing any other.
     *
     * @return string its hash, for Appearance::$logoCustom
     */
    public function store(string $userId, string $svg): string
    {
        $hash      = substr(hash('sha256', $svg), 0, 16);
        $directory = $this->directory($this->appIcons->keyFor($userId));

        if (false === is_dir($directory) && false === mkdir($directory, 0755, true) && false === is_dir($directory)) {
            throw new \RuntimeException(sprintf('Could not create %s.', $directory));
        }

        // Written beside and renamed, so a request never reads half a file.
        $staging = $directory . '/.incoming-' . bin2hex(random_bytes(6));
        file_put_contents($staging, $svg);
        rename($staging, $directory . '/' . $hash . '.svg');

        foreach (glob($directory . '/*.svg') ?: [] as $other) {
            if ($other !== $directory . '/' . $hash . '.svg') {
                @unlink($other);
            }
        }

        return $hash;
    }

    /** Whether the user's logo of this hash is on disk. */
    public function has(string $userId, string $hash): bool
    {
        return null !== $this->pathFor($this->appIcons->keyFor($userId), $hash);
    }

    /**
     * The stored file for a key and hash, or null. Both come from a URL, so
     * both are checked here too and not only by the route.
     */
    public function pathFor(string $key, string $hash): ?string
    {
        if (1 !== preg_match('/^' . AppIconStorage::KEY_PATTERN . '$/', $key) || 1 !== preg_match('/^' . self::HASH_PATTERN . '$/', $hash)) {
            return null;
        }

        $path = $this->directory($key) . '/' . $hash . '.svg';

        return true === is_file($path) ? $path : null;
    }

    public function deleteAllFor(string $userId): void
    {
        $directory = $this->directory($this->appIcons->keyFor($userId));

        foreach (glob($directory . '/*.svg') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($directory);
    }

    private function directory(string $key): string
    {
        return $this->projectDir . '/' . $this->storageDir . '/custom-logos/' . $key;
    }
}
