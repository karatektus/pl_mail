<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Setup;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The pre-Dotenv bootstrap that surfaces the generated-config file to PHP.
 *
 * What matters here is MERCURE_PUBLIC_URL: nobody configures it on a normal
 * install. The admin enters one public URL during setup, and the hub — proxied
 * on that same origin — must follow it. If the derivation broke, every install
 * would come up working but never refresh by itself, which is the kind of
 * breakage nobody reports because nothing errors.
 *
 * The bootstrap mutates process globals once, so each case runs it in a child
 * process with exactly the environment under test.
 */
final class GeneratedSecretsBootstrapTest extends TestCase
{
    private string $secretsFile;

    protected function setUp(): void
    {
        $this->secretsFile = sys_get_temp_dir().'/plmail-bootstrap-'.bin2hex(random_bytes(6)).'/generated.env';
        mkdir(\dirname($this->secretsFile), 0700);
    }

    protected function tearDown(): void
    {
        if (is_file($this->secretsFile)) {
            unlink($this->secretsFile);
        }

        if (is_dir(\dirname($this->secretsFile))) {
            rmdir(\dirname($this->secretsFile));
        }
    }

    public function testTheHubUrlIsDerivedFromThePublicUrlTheAdminChoseDuringSetup(): void
    {
        file_put_contents($this->secretsFile, "APP_PUBLIC_URL=https://mail.example.com\n");

        self::assertSame(
            'https://mail.example.com/.well-known/mercure',
            $this->mercurePublicUrlSeenBy([]),
        );
    }

    public function testATrailingSlashInAnEnvironmentSuppliedPublicUrlDoesNotDoubleUp(): void
    {
        // PublicUrlSetting trims before writing, but an operator-supplied
        // environment value arrives exactly as typed.
        self::assertSame(
            'https://mail.example.com/.well-known/mercure',
            $this->mercurePublicUrlSeenBy(['APP_PUBLIC_URL' => 'https://mail.example.com/']),
        );
    }

    public function testAnExplicitHubUrlIsNeverOverriddenByTheDerivation(): void
    {
        file_put_contents($this->secretsFile, "APP_PUBLIC_URL=https://mail.example.com\n");

        self::assertSame(
            'https://hub.elsewhere.example/.well-known/mercure',
            $this->mercurePublicUrlSeenBy(['MERCURE_PUBLIC_URL' => 'https://hub.elsewhere.example/.well-known/mercure']),
        );
    }

    public function testAnEmptyHubVariableCountsAsAbsent(): void
    {
        // The TrueNAS compose passes every unconfigured value through as an
        // empty string; treating that as "configured" would kill the
        // derivation on exactly the installs that rely on it.
        file_put_contents($this->secretsFile, "APP_PUBLIC_URL=https://mail.example.com\n");

        self::assertSame(
            'https://mail.example.com/.well-known/mercure',
            $this->mercurePublicUrlSeenBy(['MERCURE_PUBLIC_URL' => '', 'APP_PUBLIC_URL' => '']),
        );
    }

    public function testNothingIsDerivedBeforeSetupHasStoredAPublicUrl(): void
    {
        self::assertSame('(unset)', $this->mercurePublicUrlSeenBy([]));
    }

    /**
     * Run the bootstrap in a child PHP process carrying exactly $env, and
     * return the MERCURE_PUBLIC_URL it left behind.
     *
     * @param array<string, string> $env
     */
    private function mercurePublicUrlSeenBy(array $env): string
    {
        $bootstrap = \dirname(__DIR__, 3).'/config/bootstrap_generated_secrets.php';

        $code = sprintf(
            'require %s; echo $_SERVER["MERCURE_PUBLIC_URL"] ?? $_ENV["MERCURE_PUBLIC_URL"] ?? "(unset)";',
            var_export($bootstrap, true),
        );

        $process = proc_open(
            [PHP_BINARY, '-r', $code],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env + ['APP_SECRETS_FILE' => $this->secretsFile],
        );

        if (false === \is_resource($process)) {
            throw new RuntimeException('Could not start the child PHP process.');
        }

        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        if (0 !== proc_close($process)) {
            throw new RuntimeException('The bootstrap crashed: '.$errors);
        }

        return $output;
    }
}
