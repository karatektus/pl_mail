<?php

declare(strict_types=1);

namespace App\Service\Crypto;

use Closure;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * A GnuPG home that exists for one piece of work and is removed after it,
 * and the gpg binary run in it.
 *
 * One home per check rather than a shared keyring, for the reasons
 * SignatureVerifier gives: workers run side by side, the container runs under
 * any uid, and the keys that count are whatever the database says now — not
 * whatever a keyring on disk remembers from last week. Only public keys ever
 * go in, so no agent and no passphrase are involved.
 *
 * The binary through Symfony Process rather than ext-gnupg. gpgme, under the
 * extension, starts gpgconf itself and reads its answer; inside the
 * FrankenPHP server of 1.12.7 and 1.13.0 it read nothing back, and every
 * `new gnupg()` in a web request died on "Setting engine info failed" while
 * the CLI in the same image was fine. proc_open() was not affected. What is
 * read back is gpg's machine interface — `--with-colons` listings and
 * `--status-fd` lines, both documented in gpg's doc/DETAILS and stable across
 * versions — not its messages to people.
 */
final class GnupgHome
{
    /** gpg imports a few keys or checks one signature; anything slower is stuck. */
    private const int TIMEOUT = 15;

    private static ?bool $works = null;

    private function __construct(
        public readonly string $path,
    ) {
    }

    /**
     * Whether gpg is there and starts. Asked once per process: a binary that
     * was missing at the first check is not installed by the next request.
     */
    public static function available(): bool
    {
        return self::$works ??= self::starts();
    }

    /**
     * Runs $work in a fresh home, and removes the home afterwards whatever
     * happened.
     *
     * @template T
     *
     * @param Closure(self): T $work
     *
     * @return T
     */
    public static function with(Closure $work): mixed
    {
        $home = new self(sys_get_temp_dir() . '/plmail-gnupg-' . bin2hex(random_bytes(8)));
        mkdir($home->path, 0700);

        try {
            return $work($home);
        } finally {
            self::removeTree($home->path);
        }
    }

    /**
     * A gpg run in this home, not started yet: for a caller that wants its
     * exit code and error output, as WkdKeyExport does for its log.
     */
    public function process(string ...$args): Process
    {
        // --no-autostart: only public keys come in here, so an agent would
        // be one more process for nothing, and one left running afterwards.
        $process = new Process(['gpg', '--homedir', $this->path, '--batch', '--no-tty', '--quiet', '--no-autostart', ...$args]);
        $process->setTimeout(self::TIMEOUT);

        return $process;
    }

    /**
     * What gpg wrote to stdout, whatever its exit code — `--verify` exits 1
     * on a bad signature and 2 on a missing key, and the status lines saying
     * which are the answer, not an error. Empty when it hung.
     */
    public function output(?string $input, string ...$args): string
    {
        $process = $this->process(...$args);
        $process->setInput($input);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return '';
        }

        return $process->getOutput();
    }

    /**
     * Writes $data to a file in this home, for gpg to read by name: several
     * keys in one import, a signature beside the text it signs.
     */
    public function file(string $name, string $data): string
    {
        $path = $this->path . '/' . $name;
        file_put_contents($path, $data);

        return $path;
    }

    /**
     * The keys in this home, as gpg lists them: the primary key, its user
     * IDs and its capabilities, from the `pub`, `fpr` and `uid` records.
     *
     * @return list<array{fingerprint: string, validity: string, expires: int, capabilities: string, addresses: list<string>}>
     */
    public function keys(string ...$which): array
    {
        $keys    = [];
        $current = null;
        $primary = false;

        foreach (explode("\n", $this->output(null, '--with-colons', '--list-keys', ...$which)) as $line) {
            $fields = explode(':', rtrim($line, "\r"));

            switch ($fields[0]) {
                case 'pub':
                    if (null !== $current) {
                        $keys[] = $current;
                    }

                    $current = [
                        'fingerprint'  => '',
                        'validity'     => $fields[1] ?? '',
                        'expires'      => (int) ($fields[6] ?? 0),
                        'capabilities' => $fields[11] ?? '',
                        'addresses'    => [],
                    ];
                    $primary = true;
                    break;

                case 'sub':
                    $primary = false;
                    break;

                case 'fpr':
                    // The first fpr after pub is the primary key's; the ones
                    // after each sub are the subkeys'.
                    if (null !== $current && true === $primary && '' === $current['fingerprint']) {
                        $current['fingerprint'] = strtolower($fields[9] ?? '');
                    }
                    break;

                case 'uid':
                    // r revoked, i invalid, e expired: a user ID gpg would not
                    // stand behind is no address of the key's.
                    $email = self::address(self::unescape($fields[9] ?? ''));

                    if (null !== $current && null !== $email && false === in_array($fields[1] ?? '', ['r', 'i', 'e'], true)) {
                        $current['addresses'][] = $email;
                    }
                    break;
            }
        }

        if (null !== $current) {
            $keys[] = $current;
        }

        return array_values(array_filter($keys, static fn (array $key): bool => '' !== $key['fingerprint']));
    }

    private static function starts(): bool
    {
        $process = new Process(['gpg', '--version']);
        $process->setTimeout(self::TIMEOUT);

        try {
            return 0 === $process->run();
        } catch (ProcessTimedOutException) {
            return false;
        }
    }

    /**
     * The address of a user ID: the part in angle brackets, or the whole of
     * it when it is a bare address — the way gpgme read it before.
     */
    private static function address(string $uid): ?string
    {
        if (1 === preg_match('/<([^<>\s]+@[^<>\s]+)>/', $uid, $match)) {
            return strtolower($match[1]);
        }

        $uid = trim($uid);

        return 1 === preg_match('/^[^<>\s]+@[^<>\s]+$/', $uid) ? strtolower($uid) : null;
    }

    /** Colon listings escape a colon, and other bytes, as \xNN. */
    private static function unescape(string $value): string
    {
        return (string) preg_replace_callback('/\\\\x([0-9a-fA-F]{2})/', static fn (array $m): string => chr((int) hexdec($m[1])), $value);
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
