<?php

declare(strict_types=1);

namespace App\Service\Crypto;

use Closure;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * A GnuPG home that exists for one piece of work and is removed after it.
 *
 * One home per check rather than a shared keyring, for the reasons
 * SignatureVerifier gives: workers run side by side, the container runs under
 * any uid, and the keys that count are whatever the database says now — not
 * whatever a keyring on disk remembers from last week. Only public keys ever
 * go in, so no agent and no passphrase are involved.
 *
 * Extracted when reading contacts' keys (PublicKeyReader) needed the same
 * throwaway home the signature check already made for itself.
 */
final class GnupgHome
{
    public static function available(): bool
    {
        return class_exists(\gnupg::class);
    }

    /**
     * Runs $work with a fresh gnupg instance in silent error mode, in a home
     * of its own, and removes the home afterwards whatever happened.
     *
     * @template T
     *
     * @param Closure(\gnupg): T $work
     *
     * @return T
     */
    public static function with(Closure $work): mixed
    {
        return self::directory(static function (string $home) use ($work): mixed {
            $gpg = new \gnupg(['home_dir' => $home]);
            $gpg->seterrormode(\gnupg::ERROR_SILENT);

            return $work($gpg);
        });
    }

    /**
     * Runs $work with the path of a fresh home, for what only the gpg binary
     * can do — an export filter (WkdKeyExport) has no gpgme call behind it,
     * so the extension cannot ask for one. Removed afterwards like the other.
     *
     * @template T
     *
     * @param Closure(string): T $work
     *
     * @return T
     */
    public static function directory(Closure $work): mixed
    {
        $home = sys_get_temp_dir() . '/plmail-gnupg-' . bin2hex(random_bytes(8));
        mkdir($home, 0700);

        try {
            return $work($home);
        } finally {
            self::removeTree($home);
        }
    }

    private static function removeTree(string $path): void
    {
        if (false === is_dir($path)) {
            return;
        }

        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            true === $entry->isDir() && false === $entry->isLink() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }

        @rmdir($path);
    }
}
