<?php

declare(strict_types=1);

namespace App\Tests\Doctrine\Type;

use App\Doctrine\Type\EncryptedStringType;
use App\Encryption\Encryptor;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Types\ConversionException;
use Doctrine\DBAL\Types\Type;
use PHPUnit\Framework\TestCase;

final class EncryptedStringTypeTest extends TestCase
{
    private AbstractPlatform $platform;
    private EncryptedStringType $type;

    protected function setUp(): void
    {
        $this->platform = new PostgreSQLPlatform();

        if (false === Type::hasType(EncryptedStringType::NAME)) {
            Type::addType(EncryptedStringType::NAME, EncryptedStringType::class);
        }

        $type = Type::getType(EncryptedStringType::NAME);
        self::assertInstanceOf(EncryptedStringType::class, $type);
        $this->type = $type;

        EncryptedStringType::setEncryptor(
            new Encryptor(base64_encode(sodium_crypto_secretbox_keygen())),
        );
    }

    public function testRoundTripsThroughTheDatabaseValue(): void
    {
        $stored = $this->type->convertToDatabaseValue('hunter2', $this->platform);

        self::assertNotSame('hunter2', $stored);
        self::assertSame('hunter2', $this->type->convertToPHPValue($stored, $this->platform));
    }

    public function testStoredValueIsCiphertext(): void
    {
        $stored = $this->type->convertToDatabaseValue('app-password', $this->platform);

        self::assertIsString($stored);
        self::assertStringStartsWith(Encryptor::PREFIX, $stored);
        self::assertStringNotContainsString('app-password', $stored);
    }

    public function testNullPassesThroughBothWays(): void
    {
        self::assertNull($this->type->convertToDatabaseValue(null, $this->platform));
        self::assertNull($this->type->convertToPHPValue(null, $this->platform));
    }

    public function testEmptyStringPassesThroughBothWays(): void
    {
        self::assertSame('', $this->type->convertToDatabaseValue('', $this->platform));
        self::assertSame('', $this->type->convertToPHPValue('', $this->platform));
    }

    /**
     * Rows written before the column was encrypted carry no prefix and must
     * still be readable, or an upgraded instance cannot open its own accounts
     * page to fix them.
     */
    public function testLegacyPlaintextIsReadUnchanged(): void
    {
        self::assertSame(
            'plaintext-from-before',
            $this->type->convertToPHPValue('plaintext-from-before', $this->platform),
        );
    }

    public function testUndecryptableValueRaisesAConversionError(): void
    {
        $stored = $this->type->convertToDatabaseValue('secret', $this->platform);

        // Simulate the key having changed since the value was written.
        EncryptedStringType::setEncryptor(
            new Encryptor(base64_encode(sodium_crypto_secretbox_keygen())),
        );

        $this->expectException(ConversionException::class);
        $this->expectExceptionMessageMatches('/APP_ENCRYPTION_KEY/');

        $this->type->convertToPHPValue($stored, $this->platform);
    }

    public function testColumnIsStillText(): void
    {
        self::assertSame(
            'TEXT',
            $this->type->getSQLDeclaration([], $this->platform),
        );
    }
}
