<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Setup;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * What the first-run generator mints, and the one secret it leaves alone.
 *
 * A database an operator brought has its password in DATABASE_URL. The
 * generator used to mint a POSTGRES_PASSWORD beside it regardless and write it
 * to a file for a Postgres container that was not there: a credential nothing
 * reads, sitting in the secrets directory and travelling in every backup of it.
 *
 * Run as the real script in a child shell, because the thing under test is a
 * shell script and its only interface is the directory it leaves behind.
 */
final class GenerateSecretsScriptTest extends TestCase
{
    private string $secretsDir;

    protected function setUp(): void
    {
        $this->secretsDir = sys_get_temp_dir().'/plmail-generate-'.bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->secretsDir.'/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (true === is_file($file)) {
                unlink($file);
            }
        }

        if (true === is_dir($this->secretsDir)) {
            rmdir($this->secretsDir);
        }
    }

    public function testAStackWithItsOwnPostgresGetsADatabasePassword(): void
    {
        // The DSN .env ships: a user and no password. That is nobody's
        // database yet, and must not count as one.
        $generated = $this->generate(['DATABASE_URL' => 'postgresql://app@database:5432/app?serverVersion=18&charset=utf8']);

        self::assertArrayHasKey('POSTGRES_PASSWORD', $generated);
        self::assertSame($generated['POSTGRES_PASSWORD'], file_get_contents($this->secretsDir.'/postgres_password'));
    }

    public function testADatabaseSomebodyElseSetUpGetsNoPasswordOfOurs(): void
    {
        $generated = $this->generate(['DATABASE_URL' => 'postgresql://app:chosen@postgres:5432/app']);

        self::assertArrayNotHasKey('POSTGRES_PASSWORD', $generated);
        self::assertFileDoesNotExist($this->secretsDir.'/postgres_password');

        // Everything else is still plMail's to mint.
        self::assertArrayHasKey('APP_SECRET', $generated);
        self::assertArrayHasKey('APP_ENCRYPTION_KEY', $generated);
        self::assertArrayHasKey('MERCURE_JWT_SECRET', $generated);
    }

    /**
     * @param array<string, string> $env
     *
     * @return array<string, string> what the run left in generated.env
     */
    private function generate(array $env): array
    {
        $process = proc_open(
            ['sh', \dirname(__DIR__, 3).'/frankenphp/generate-secrets.sh'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env + ['APP_SECRETS_DIR' => $this->secretsDir, 'PATH' => (string) getenv('PATH')],
        );

        if (false === \is_resource($process)) {
            throw new RuntimeException('Could not start the generator.');
        }

        stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        if (0 !== proc_close($process)) {
            throw new RuntimeException('The generator failed: '.$errors);
        }

        $values = [];

        foreach (file($this->secretsDir.'/generated.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            [$name, $value] = explode('=', $line, 2);
            $values[$name]  = $value;
        }

        return $values;
    }
}
