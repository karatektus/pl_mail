<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Setup;

use App\Infrastructure\Setup\EncryptionKeyProbe;
use App\Infrastructure\Setup\GeneratedSecretsFile;
use App\Repository\Mail\AccountRepository;
use Doctrine\DBAL\Types\ConversionException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The startup check that refuses to run against credentials the current
 * encryption key cannot read.
 *
 * The message is the feature. Whoever hits this is looking at a container that
 * will not start, and the two things worth saying — a service is missing the
 * shared volume, or the key was dropped from the environment — are the two
 * things they cannot see from the outside.
 */
final class EncryptionKeyProbeTest extends TestCase
{
    public function testItPassesWhenTheStoredCredentialsDecrypt(): void
    {
        $accounts = $this->createStub(AccountRepository::class);
        $accounts->method('findOneWithStoredCredentials')->willReturn(null);

        $this->expectNotToPerformAssertions();

        $this->probe($accounts)->verify();
    }

    public function testItRefusesWhenTheStoredCredentialsDoNotDecrypt(): void
    {
        $accounts = $this->createStub(AccountRepository::class);
        $accounts->method('findOneWithStoredCredentials')
            ->willThrowException(new ConversionException('Could not decrypt encrypted_string column'));

        try {
            $this->probe($accounts)->verify();
            self::fail('the probe must refuse to start');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('cannot decrypt the credentials already stored', $e->getMessage());
            // The two causes, both invisible from inside the container.
            self::assertStringContainsString('mounted on every plMail service', $e->getMessage());
            self::assertStringContainsString('changed while the stack was running', $e->getMessage());
            // And a way out. A guard that only describes the problem strands
            // whoever hits it — this one names the command that clears it.
            self::assertStringContainsString('app:reset --full', $e->getMessage());
        }
    }

    private function probe(AccountRepository $accounts): EncryptionKeyProbe
    {
        return new EncryptionKeyProbe(
            $accounts,
            new GeneratedSecretsFile(sys_get_temp_dir().'/plmail-probe-test/generated.env'),
        );
    }
}
