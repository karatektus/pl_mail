<?php

declare(strict_types=1);

namespace App\Infrastructure\Backup;

use App\Domain\Exception\ConfigBackupException;
use JsonException;
use SensitiveParameter;

/**
 * The envelope a config backup travels in: Argon2id over the admin's password,
 * secretbox over the document.
 *
 * **Why not the Encryptor next door.** That one encrypts with
 * APP_ENCRYPTION_KEY, which is exactly the thing a config backup exists to
 * carry somewhere else. A file only the instance that wrote it can open is not
 * a backup, it is a second copy of the database. So the key here comes from a
 * password a human chose and typed, and nothing about it is stored.
 *
 * **The format is public on purpose.** These are the admin's own secrets and
 * they must not be locked inside plMail: the header names the KDF, its
 * parameters, the salt and the nonce, so the file can be opened by anything
 * that has libsodium — including a one-liner, which docs/install/config-backup.md
 * spells out. Nothing is derived implicitly and nothing is versioned by
 * position; a reader that can parse JSON has everything.
 *
 *     {
 *       "format":  "plmail-config-backup",
 *       "version": 1,
 *       "kdf":     {"name":"argon2id","opslimit":3,"memlimit":67108864,"salt":"<base64>"},
 *       "cipher":  {"name":"xsalsa20poly1305","nonce":"<base64>"},
 *       "ciphertext": "<base64 of secretbox(document, nonce, key)>"
 *     }
 *
 * **opslimit 3, memlimit 64 MiB** — libsodium's MODERATE ops with INTERACTIVE
 * memory, and the asymmetry is deliberate. plMail's reference deployment is a
 * Raspberry Pi, where memory is the scarce resource and the parameter that
 * turns a slow page into an OOM-killed PHP worker; MODERATE's 256 MiB is a
 * quarter of a 1 GB Pi and is requested in one allocation. Iterations cost only
 * wall-clock, which on a page somebody clicked a button on is affordable, so
 * the ops half is raised to buy back part of what the memory half gives up. The
 * numbers are written into every file rather than assumed by the reader, so
 * raising them later opens old backups unchanged.
 *
 * **No plaintext ever reaches disk.** Everything here is strings in memory; the
 * caller streams the result out as a download. The derived key and the
 * plaintext are wiped with sodium_memzero as soon as they are spent — which is
 * not a guarantee against a core dump, but it is the difference between a
 * secret living for microseconds and living until the request's arena is
 * reused.
 */
final readonly class ConfigBackupCipher
{
    /** Marks the file as ours, before anything else is believed about it. */
    public const string FORMAT = 'plmail-config-backup';

    /** The envelope layout this class writes, and the newest it can read. */
    public const int VERSION = 1;

    public const string KDF = 'argon2id';

    public const string CIPHER = 'xsalsa20poly1305';

    /** See the class docblock: MODERATE ops, INTERACTIVE memory. */
    public const int OPSLIMIT = SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE;

    public const int MEMLIMIT = SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE;

    /**
     * argon2id's own lower bound for memlimit, in bytes.
     *
     * libsodium refuses anything smaller by THROWING rather than by returning a
     * weaker key, so this bounds what may reach sodium at all — it is not a
     * policy about strength. bounded() had a ceiling and no floor, and an
     * envelope declaring `memlimit: 1` therefore passed every check here and
     * took a SodiumException out through open(), past two controllers that
     * catch only ConfigBackupException, onto a blank error page. One of those
     * doors is the anonymous /install/restore.
     */
    private const int MEMLIMIT_FLOOR = 8192;

    /**
     * The shortest password this will seal a file with.
     *
     * Argon2id makes a short password expensive to guess, not safe: this file
     * is offline, so an attacker who has it can try forever at 64 MiB a go. A
     * floor is the only part of that plMail controls.
     */
    public const int MINIMUM_PASSWORD_LENGTH = 12;

    /**
     * @param array<string, mixed> $document
     *
     * @return string the complete file, ready to be sent as a download
     */
    public function seal(array $document, #[SensitiveParameter] string $password): string
    {
        $salt  = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $key   = $this->deriveKey($password, $salt);

        // try/finally, because the guarantee this makes is about the ABNORMAL
        // path as much as the normal one. json_encode() throws on malformed
        // UTF-8, and a throw between here and the memzero below left the
        // derived key — and the whole decrypted document — sitting in the
        // request arena for the rest of the process, which is the one case the
        // wiping exists for.
        try {
            $plaintext = json_encode($document, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

            $sealed = sodium_crypto_secretbox($plaintext, $nonce, $key);
        } finally {
            if (true === isset($plaintext) && is_string($plaintext)) {
                sodium_memzero($plaintext);
            }

            sodium_memzero($key);
        }

        return json_encode([
            'format'  => self::FORMAT,
            'version' => self::VERSION,
            'kdf'     => [
                'name'     => self::KDF,
                'opslimit' => self::OPSLIMIT,
                'memlimit' => self::MEMLIMIT,
                'salt'     => base64_encode($salt),
            ],
            'cipher' => [
                'name'  => self::CIPHER,
                'nonce' => base64_encode($nonce),
            ],
            'ciphertext' => base64_encode($sealed),
        ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }

    /**
     * The reverse, with every check stated before the expensive one.
     *
     * The KDF parameters are read from the file rather than from the constants
     * above — that is the whole reason they are written into it — but they are
     * also bounded. A file claiming memlimit 8 GB is not a backup from an older
     * plMail, it is a way to make one HTTP request exhaust the host, and it is
     * refused before sodium is asked to honour it.
     *
     * @return array<string, mixed>
     *
     * @throws ConfigBackupException
     */
    public function open(string $envelope, #[SensitiveParameter] string $password): array
    {
        $header = $this->decodeJson($envelope, 'envelope');

        if (self::FORMAT !== ($header['format'] ?? null)) {
            throw ConfigBackupException::notABackup('Missing or unrecognised "format" field.');
        }

        $version = $header['version'] ?? null;

        if (false === is_int($version)) {
            throw ConfigBackupException::notABackup('The "version" field is missing or not a number.');
        }

        if ($version > self::VERSION) {
            throw ConfigBackupException::unsupportedVersion($version, self::VERSION);
        }

        $kdf    = is_array($header['kdf'] ?? null) ? $header['kdf'] : [];
        $cipher = is_array($header['cipher'] ?? null) ? $header['cipher'] : [];

        if (self::KDF !== ($kdf['name'] ?? null) || self::CIPHER !== ($cipher['name'] ?? null)) {
            throw ConfigBackupException::notABackup('The envelope names a KDF or cipher this version cannot use.');
        }

        $salt       = $this->decodeBase64($kdf['salt'] ?? null, SODIUM_CRYPTO_PWHASH_SALTBYTES, 'salt');
        $nonce      = $this->decodeBase64($cipher['nonce'] ?? null, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES, 'nonce');
        $ciphertext = $this->decodeBase64($header['ciphertext'] ?? null, null, 'ciphertext');

        $key = $this->deriveKey($password, $salt, $this->bounded($kdf, 'opslimit'), $this->bounded($kdf, 'memlimit'));

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $key);

        sodium_memzero($key);

        // False for a wrong password AND for a tampered file, and there is no
        // way to tell which — the tag covers both. Reported as the one an admin
        // can act on.
        if (false === $plaintext) {
            throw ConfigBackupException::wrongPassword();
        }

        // Same reason as seal(): decodeJson() throws on a document that opened
        // but is not JSON, and the plaintext must be gone either way.
        try {
            return $this->decodeJson($plaintext, 'document');
        } finally {
            sodium_memzero($plaintext);
        }
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * A parameter as the file states it, refused rather than clamped when it is
     * outside what this host will spend on one upload.
     *
     * Refused, because clamping down would make the derivation produce a
     * different key and report it as a wrong password — the single most
     * confusing failure this feature could have.
     *
     * @param array<string, mixed> $kdf
     */
    private function bounded(array $kdf, string $name): int
    {
        $value = $kdf[$name] ?? null;

        if (false === is_int($value) || $value < 1) {
            throw ConfigBackupException::notABackup(sprintf('The KDF "%s" is missing or not a positive integer.', $name));
        }

        // libsodium has floors of its own, and below them argon2id does not
        // return a weaker key — it throws. There was a ceiling here and no
        // floor, so an envelope declaring `memlimit: 1` passed every check in
        // this class and then took a SodiumException out through open(), past
        // two controllers that catch only ConfigBackupException, and onto an
        // error page with no message. One of those doors is the anonymous
        // /install/restore.
        //
        // Stated here rather than caught later, which is what the class docblock
        // promises: every check before the expensive one, and nothing reaching
        // sodium that sodium will refuse.
        // Spelled out rather than read from a constant: libsodium defines
        // crypto_pwhash_MEMLIMIT_MIN in its C headers and PHP's extension does
        // not re-export it, so there is nothing to reference. opslimit needs no
        // floor of its own — its minimum is 1, which the positive-integer check
        // above already enforces.
        if ('memlimit' === $name && $value < self::MEMLIMIT_FLOOR) {
            throw ConfigBackupException::notABackup(sprintf(
                'The KDF "memlimit" of %d is below what argon2id accepts (%d).',
                $value,
                self::MEMLIMIT_FLOOR,
            ));
        }

        // Four times what this writes: room for a backup made by a future
        // plMail that raised the cost, and nowhere near enough to be a lever.
        $ceiling = 'memlimit' === $name ? 4 * self::MEMLIMIT : 4 * self::OPSLIMIT;

        if ($value > $ceiling) {
            throw ConfigBackupException::notABackup(sprintf(
                'The KDF "%s" of %d exceeds what this host will spend on one upload (%d).',
                $name,
                $value,
                $ceiling,
            ));
        }

        return $value;
    }

    private function deriveKey(
        #[SensitiveParameter] string $password,
        string $salt,
        int $opslimit = self::OPSLIMIT,
        int $memlimit = self::MEMLIMIT,
    ): string {
        return sodium_crypto_pwhash(
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
            $password,
            $salt,
            $opslimit,
            $memlimit,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJson(string $raw, string $what): array
    {
        try {
            $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            // The envelope not being JSON means "this is not a backup"; the
            // DOCUMENT not being JSON means the password was right and the
            // contents are wrong, which is a different sentence.
            throw 'envelope' === $what
                ? ConfigBackupException::notABackup('The file is not JSON: ' . $e->getMessage())
                : ConfigBackupException::malformed('The decrypted document is not JSON: ' . $e->getMessage());
        }

        if (false === is_array($decoded)) {
            throw 'envelope' === $what
                ? ConfigBackupException::notABackup('The file is JSON but not an object.')
                : ConfigBackupException::malformed('The decrypted document is JSON but not an object.');
        }

        return $decoded;
    }

    private function decodeBase64(mixed $value, ?int $expectedBytes, string $what): string
    {
        if (false === is_string($value)) {
            throw ConfigBackupException::notABackup(sprintf('The "%s" field is missing or not a string.', $what));
        }

        $raw = base64_decode($value, true);

        if (false === $raw || '' === $raw) {
            throw ConfigBackupException::notABackup(sprintf('The "%s" field is not valid base64.', $what));
        }

        if (null !== $expectedBytes && $expectedBytes !== strlen($raw)) {
            throw ConfigBackupException::notABackup(sprintf(
                'The "%s" field decodes to %d bytes; %d expected.',
                $what,
                strlen($raw),
                $expectedBytes,
            ));
        }

        return $raw;
    }
}
