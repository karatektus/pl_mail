<?php

declare(strict_types=1);

namespace App\Tests\Encryption;

use App\Encryption\EncryptionException;
use App\Encryption\Encryptor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EncryptorTest extends TestCase
{
    private static function key(): string
    {
        return base64_encode(sodium_crypto_secretbox_keygen());
    }

    public function testRoundTripsAValue(): void
    {
        $encryptor = new Encryptor(self::key());

        self::assertSame('hunter2', $encryptor->decrypt($encryptor->encrypt('hunter2')));
    }

    #[DataProvider('awkwardValues')]
    public function testRoundTripsAwkwardValues(string $value): void
    {
        $encryptor = new Encryptor(self::key());

        self::assertSame($value, $encryptor->decrypt($encryptor->encrypt($value)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function awkwardValues(): iterable
    {
        yield 'empty string'  => [''];
        yield 'utf-8'         => ['pässwörd–✓'];
        yield 'newlines'      => ["line one\nline two"];
        yield 'null byte'     => ["before\0after"];
        yield 'long'          => [str_repeat('a', 8192)];
        yield 'looks base64'  => ['aGVsbG8gd29ybGQ='];
    }

    public function testCiphertextDoesNotContainThePlaintext(): void
    {
        $encrypted = new Encryptor(self::key())->encrypt('correct-horse-battery-staple');

        self::assertStringNotContainsString('correct-horse-battery-staple', $encrypted);
        self::assertStringStartsWith(Encryptor::PREFIX, $encrypted);
    }

    /**
     * A fresh nonce per call, so equal inputs must not produce equal output —
     * otherwise the ciphertext would leak which accounts share a password.
     */
    public function testSameValueEncryptsDifferentlyEachTime(): void
    {
        $encryptor = new Encryptor(self::key());

        self::assertNotSame($encryptor->encrypt('same'), $encryptor->encrypt('same'));
    }

    public function testEncryptingAnEncryptedValueIsANoOp(): void
    {
        $encryptor = new Encryptor(self::key());
        $once      = $encryptor->encrypt('value');

        self::assertSame($once, $encryptor->encrypt($once));
    }

    public function testDecryptingWithTheWrongKeyFails(): void
    {
        $encrypted = new Encryptor(self::key())->encrypt('secret');

        $this->expectException(EncryptionException::class);

        new Encryptor(self::key())->decrypt($encrypted);
    }

    public function testTamperedCiphertextFails(): void
    {
        $encryptor = new Encryptor(self::key());
        $encrypted = $encryptor->encrypt('secret');

        // Flip one bit of the sealed bytes, leaving the prefix and the nonce
        // structurally intact, so only the Poly1305 tag can catch it.
        $raw            = base64_decode(substr($encrypted, strlen(Encryptor::PREFIX)), true);
        self::assertIsString($raw);
        $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 0x01);

        $tampered = Encryptor::PREFIX . base64_encode($raw);

        $this->expectException(EncryptionException::class);

        $encryptor->decrypt($tampered);
    }

    public function testDecryptingPlaintextIsRefused(): void
    {
        $this->expectException(EncryptionException::class);

        new Encryptor(self::key())->decrypt('not-encrypted');
    }

    public function testRecognisesEncryptedValues(): void
    {
        $encryptor = new Encryptor(self::key());

        self::assertTrue($encryptor->isEncrypted($encryptor->encrypt('x')));
        self::assertFalse($encryptor->isEncrypted('x'));
    }

    public function testRejectsAKeyOfTheWrongLength(): void
    {
        $this->expectException(EncryptionException::class);
        $this->expectExceptionMessageMatches('/must decode to 32 bytes/');

        new Encryptor(base64_encode('too-short'));
    }

    public function testRejectsANonBase64Key(): void
    {
        $this->expectException(EncryptionException::class);

        new Encryptor('not base64 !!!');
    }
}
