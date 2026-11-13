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
 * WHY UNDER uploads/
 * ──────────────────
 * Under uploads/ with the avatars and the signature images, because that is
 * the tree every install keeps: compose.yaml gives it a volume, and the NAS
 * templates keep the whole storage directory. These used to live next to
 * app-icons/, in var/custom-logos/, which compose.yaml has no volume for, so
 * the file lived in the container and went with it at the next upgrade (#600).
 * The row kept the hash and AppearanceExtension, finding no file, quietly put
 * the motif back. Adding a volume instead would only have reached people who
 * fetch compose.yaml again; moving the files reaches everyone who pulls the
 * image.
 *
 * The weekly blob prune (#214) does not touch these: it only ever deletes
 * files laid out as `<account>/<32 hex>.blob`, which a logo never is.
 *
 * A logo still in the old place (an install whose old directory survived: the
 * NAS templates, the dev stack, or a container not yet recreated) is moved
 * across the first time anything looks for it.
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

        $this->moveLegacy($key);

        $path = $this->directory($key) . '/' . $hash . '.svg';

        return true === is_file($path) ? $path : null;
    }

    public function deleteAllFor(string $userId): void
    {
        $key = $this->appIcons->keyFor($userId);

        foreach ([$this->directory($key), $this->legacyDirectory($key)] as $directory) {
            foreach (glob($directory . '/*.svg') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($directory);
        }
    }

    /**
     * Bring a logo kept before #600 into uploads/. Only when the new place has
     * none, so a logo uploaded since is never overwritten by an older one; the
     * old directory goes either way, since nothing reads it after this.
     */
    private function moveLegacy(string $key): void
    {
        $legacy = $this->legacyDirectory($key);

        if (false === is_dir($legacy)) {
            return;
        }

        $directory = $this->directory($key);
        $files     = glob($legacy . '/*.svg') ?: [];

        if ([] !== $files && [] === (glob($directory . '/*.svg') ?: [])) {
            if (false === is_dir($directory) && false === mkdir($directory, 0755, true) && false === is_dir($directory)) {
                return;
            }

            foreach ($files as $file) {
                // rename() copies when the two are on different filesystems,
                // which they are wherever uploads/ is a volume and this is not.
                @rename($file, $directory . '/' . basename($file));
            }
        }

        foreach (glob($legacy . '/*.svg') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($legacy);
        @rmdir(dirname($legacy));
    }

    private function directory(string $key): string
    {
        return $this->projectDir . '/' . $this->storageDir . '/uploads/custom-logos/' . $key;
    }

    /** Where logos were kept before #600, which compose.yaml did not keep. */
    private function legacyDirectory(string $key): string
    {
        return $this->projectDir . '/' . $this->storageDir . '/custom-logos/' . $key;
    }
}
