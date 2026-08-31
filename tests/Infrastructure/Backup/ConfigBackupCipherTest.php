<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Backup;

use App\Domain\Enum\Backup\ConfigBackupFailure;
use App\Domain\Exception\ConfigBackupException;
use App\Infrastructure\Backup\ConfigBackupCipher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The envelope opens with the right password and with nothing else.
 *
 * This is the one class in the feature whose failure is silent: a seal that
 * does not really encrypt, a header whose parameters are ignored, or an open()
 * that accepts a tampered ciphertext all produce a file that LOOKS like a
 * backup and is either readable by anyone or trusted when it should not be. So
 * the assertions are about the bytes, not about the round trip alone — the
 * round trip passes for a cipher that does nothing.
 *
 * A plain TestCase and no container: this class takes no services, and booting
 * a kernel to derive an Argon2id key would only make the suite slower.
 */
final class ConfigBackupCipherTest extends TestCase
{
    private const string PASSWORD = 'correct horse battery staple';

    private ConfigBackupCipher $cipher;

    protected function setUp(): void
    {
        $this->cipher = new ConfigBackupCipher();
    }

    public function testTheDocumentComesBackExactlyAsItWentIn(): void
    {
        $document = [
            'format'  => ConfigBackupCipher::FORMAT,
            'version' => 1,
            'env'     => ['APP_SECRET' => 'a-secret', 'MAILER_DSN' => 'smtp://user:pa$$@host:587'],
            'files'   => ['jwt/private.pem' => base64_encode("-----BEGIN PRIVATE KEY-----\n")],
            // Nested, because the database section is two levels deep and a
            // shallow copy would pass a flat fixture.
            'database' => ['mailProviders' => ['google' => ['clientSecret' => 'GOCSPX-x', 'settings' => ['tenant' => null]]]],
        ];

        self::assertSame($document, $this->cipher->open($this->cipher->seal($document, self::PASSWORD), self::PASSWORD));
    }

    /**
     * The property a round-trip test cannot see: that the file is actually
     * encrypted rather than base64 with extra steps.
     */
    public function testNoValueFromTheDocumentAppearsInTheSealedFile(): void
    {
        $sealed = $this->cipher->seal(['env' => ['APP_SECRET' => 'sentinel-value-9f3a']], self::PASSWORD);

        self::assertStringNotContainsString('sentinel-value-9f3a', $sealed);
        self::assertStringNotContainsString(base64_encode('sentinel-value-9f3a'), $sealed);
        self::assertStringNotContainsString('APP_SECRET', $sealed);
    }

    public function testTheSameDocumentSealedTwiceProducesDifferentFiles(): void
    {
        $document = ['env' => ['APP_SECRET' => 'a-secret']];

        // A fresh salt and nonce per seal. Without it, two backups of one
        // install are byte-identical, which leaks that nothing changed and
        // reuses a nonce under the same derived key — the one mistake
        // XSalsa20-Poly1305 does not survive.
        self::assertNotSame(
            $this->cipher->seal($document, self::PASSWORD),
            $this->cipher->seal($document, self::PASSWORD),
        );
    }

    public function testAWrongPasswordIsRefusedRatherThanReturningRubbish(): void
    {
        $sealed = $this->cipher->seal(['env' => ['APP_SECRET' => 'a-secret']], self::PASSWORD);

        $this->expectException(ConfigBackupException::class);

        try {
            $this->cipher->open($sealed, self::PASSWORD . '!');
        } catch (ConfigBackupException $e) {
            self::assertSame(ConfigBackupFailure::WrongPassword, $e->failure);

            throw $e;
        }
    }

    /**
     * A flipped byte in the ciphertext has to fail the same way a wrong
     * password does. Poly1305 is what makes that true, and a version of this
     * class that used raw XSalsa20 would pass every other test here.
     */
    public function testATamperedCiphertextIsRefused(): void
    {
        $sealed = $this->cipher->seal(['env' => ['APP_SECRET' => 'a-secret']], self::PASSWORD);

        /** @var array{ciphertext: string} $header */
        $header = json_decode($sealed, true, 512, JSON_THROW_ON_ERROR);
        $raw    = (string) base64_decode($header['ciphertext'], true);

        $raw[10]               = $raw[10] === "\x00" ? "\x01" : "\x00";
        $header['ciphertext']  = base64_encode($raw);

        $this->expectExceptionObject(ConfigBackupException::wrongPassword());

        $this->cipher->open(json_encode($header, JSON_THROW_ON_ERROR), self::PASSWORD);
    }

    /**
     * The header is public and therefore attacker-controlled. Each of these is
     * a way to get sodium to do something on behalf of an unauthenticated blob,
     * and each has to be refused before the KDF is asked to run.
     *
     * @return iterable<string, array{array<string, mixed>, ConfigBackupFailure}>
     */
    public static function badEnvelopes(): iterable
    {
        $valid = [
            'format'     => ConfigBackupCipher::FORMAT,
            'version'    => 1,
            'kdf'        => ['name' => 'argon2id', 'opslimit' => 3, 'memlimit' => 67108864, 'salt' => base64_encode(str_repeat('s', 16))],
            'cipher'     => ['name' => 'xsalsa20poly1305', 'nonce' => base64_encode(str_repeat('n', 24))],
            'ciphertext' => base64_encode('not really a ciphertext'),
        ];

        yield 'someone else\'s file' => [['format' => 'borg-repo', 'version' => 1], ConfigBackupFailure::NotABackup];

        yield 'no version' => [['format' => ConfigBackupCipher::FORMAT], ConfigBackupFailure::NotABackup];

        yield 'from a newer plMail' => [
            ['format' => ConfigBackupCipher::FORMAT, 'version' => ConfigBackupCipher::VERSION + 1],
            ConfigBackupFailure::UnsupportedVersion,
        ];

        yield 'a KDF we do not have' => [[...$valid, 'kdf' => [...$valid['kdf'], 'name' => 'pbkdf2']], ConfigBackupFailure::NotABackup];

        // The lever: memlimit is an allocation this host performs on request.
        yield 'a memlimit that would exhaust the host' => [
            [...$valid, 'kdf' => [...$valid['kdf'], 'memlimit' => 8589934592]],
            ConfigBackupFailure::NotABackup,
        ];

        // The other end, which had no guard at all. Below libsodium's floor
        // argon2id does not weaken — it throws — so this used to leave the
        // class as an uncaught SodiumException, past both controllers, onto a
        // blank error page. One of the doors it reached is anonymous.
        yield 'a memlimit below what argon2id accepts' => [
            [...$valid, 'kdf' => [...$valid['kdf'], 'memlimit' => 1]],
            ConfigBackupFailure::NotABackup,
        ];

        yield 'an opslimit that would never finish' => [
            [...$valid, 'kdf' => [...$valid['kdf'], 'opslimit' => 4000000]],
            ConfigBackupFailure::NotABackup,
        ];

        yield 'a truncated salt' => [
            [...$valid, 'kdf' => [...$valid['kdf'], 'salt' => base64_encode('short')]],
            ConfigBackupFailure::NotABackup,
        ];

        yield 'a nonce of the wrong size' => [
            [...$valid, 'cipher' => [...$valid['cipher'], 'nonce' => base64_encode(str_repeat('n', 12))]],
            ConfigBackupFailure::NotABackup,
        ];
    }

    /**
     * @param array<string, mixed> $envelope
     */
    #[DataProvider('badEnvelopes')]
    public function testAnUntrustworthyHeaderIsRefusedBeforeTheKdfRuns(array $envelope, ConfigBackupFailure $expected): void
    {
        try {
            $this->cipher->open(json_encode($envelope, JSON_THROW_ON_ERROR), self::PASSWORD);

            self::fail('the envelope was accepted');
        } catch (ConfigBackupException $e) {
            self::assertSame($expected, $e->failure);
        }
    }

    public function testSomethingThatIsNotJsonAtAllIsNotABackup(): void
    {
        try {
            $this->cipher->open("\x7fELF\x02\x01\x01", self::PASSWORD);

            self::fail('a binary blob was accepted');
        } catch (ConfigBackupException $e) {
            self::assertSame(ConfigBackupFailure::NotABackup, $e->failure);
        }
    }

    /**
     * The parameters in the file are what is used, not the constants — which is
     * the promise that lets an old backup keep opening after they are raised.
     * Sealed here at the cheapest legal setting and opened by the same code
     * that would otherwise use 64 MiB.
     */
    public function testAFileIsOpenedWithTheParametersItStatesRatherThanTheCurrentOnes(): void
    {
        $salt      = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $nonce     = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $opslimit  = 1;
        // libsodium's floor for argon2id, spelled out because PHP exposes no
        // constant for it — and deliberately nowhere near what seal() uses, so
        // a version of open() that ignored the header would derive a different
        // key and this test would fail rather than pass by coincidence.
        $memlimit  = 8192;
        $key       = sodium_crypto_pwhash(
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
            self::PASSWORD,
            $salt,
            $opslimit,
            $memlimit,
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13,
        );

        $envelope = [
            'format'     => ConfigBackupCipher::FORMAT,
            'version'    => 1,
            'kdf'        => ['name' => 'argon2id', 'opslimit' => $opslimit, 'memlimit' => $memlimit, 'salt' => base64_encode($salt)],
            'cipher'     => ['name' => 'xsalsa20poly1305', 'nonce' => base64_encode($nonce)],
            'ciphertext' => base64_encode(sodium_crypto_secretbox('{"format":"plmail-config-backup","version":1}', $nonce, $key)),
        ];

        self::assertSame(
            ['format' => 'plmail-config-backup', 'version' => 1],
            $this->cipher->open(json_encode($envelope, JSON_THROW_ON_ERROR), self::PASSWORD),
        );
    }
}
