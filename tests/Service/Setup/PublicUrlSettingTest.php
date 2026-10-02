<?php

declare(strict_types=1);

namespace App\Tests\Service\Setup;

use App\Infrastructure\Setup\GeneratedSecretsFile;
use App\Service\Monitoring\WorkerRestartSignal;
use App\Service\Setup\PublicUrlSetting;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * Where the effective public URL comes from.
 *
 * current() exists because push subscriptions are built by long-running
 * workers, and the URL is typically saved from the setup screen after those
 * workers booted. Freezing the value into a service at container build — the
 * old Autowire(env:) approach — left every worker convinced there was no
 * public URL at all, and push silently stayed on polling.
 */
final class PublicUrlSettingTest extends TestCase
{
    private string $secretsFile;

    /** @var array{server: mixed, env: mixed} */
    private array $original;

    protected function setUp(): void
    {
        $this->secretsFile = sys_get_temp_dir().'/plmail-publicurl-'.bin2hex(random_bytes(6)).'/generated.env';
        mkdir(\dirname($this->secretsFile), 0700);

        $this->original = [
            'server' => $_SERVER['APP_PUBLIC_URL'] ?? null,
            'env'    => $_ENV['APP_PUBLIC_URL'] ?? null,
        ];

        unset($_SERVER['APP_PUBLIC_URL'], $_ENV['APP_PUBLIC_URL']);
    }

    protected function tearDown(): void
    {
        if (null === $this->original['server']) {
            unset($_SERVER['APP_PUBLIC_URL']);
        } else {
            $_SERVER['APP_PUBLIC_URL'] = $this->original['server'];
        }

        if (null === $this->original['env']) {
            unset($_ENV['APP_PUBLIC_URL']);
        } else {
            $_ENV['APP_PUBLIC_URL'] = $this->original['env'];
        }

        if (is_file($this->secretsFile)) {
            unlink($this->secretsFile);
        }

        if (is_dir(\dirname($this->secretsFile))) {
            rmdir(\dirname($this->secretsFile));
        }
    }

    public function testARealEnvironmentValueWinsOverTheStoredFile(): void
    {
        file_put_contents($this->secretsFile, "APP_PUBLIC_URL=https://stored.example.com\n");
        $_SERVER['APP_PUBLIC_URL'] = 'https://operator.example.com/';

        self::assertSame('https://operator.example.com', $this->setting()->current());
    }

    public function testAnEmptyEnvironmentValueCountsAsAbsent(): void
    {
        // The TrueNAS compose passes every unconfigured value through as an
        // empty string; that must not shadow what setup stored.
        file_put_contents($this->secretsFile, "APP_PUBLIC_URL=https://stored.example.com\n");
        $_SERVER['APP_PUBLIC_URL'] = '';

        self::assertSame('https://stored.example.com', $this->setting()->current());
    }

    public function testAValueSavedAfterBootIsVisibleWithoutARestart(): void
    {
        $setting = $this->setting();

        self::assertNull($setting->current());

        // Stands in for the admin completing the setup screen while this
        // process — a worker — has long been running.
        $setting->save('https://mail.example.com/');

        self::assertSame('https://mail.example.com', $setting->current());
    }

    public function testNullWhileNothingIsConfigured(): void
    {
        self::assertNull($this->setting()->current());
    }

    public function testASavedAddressIsOnFileWhileTheOldOneIsStillInForce(): void
    {
        // What a running container looks like after a change in Admin →
        // Address: the entrypoint exported the old address at start, and the
        // environment wins until the next one.
        file_put_contents($this->secretsFile, "APP_PUBLIC_URL=https://old.example.com\n");
        $_SERVER['APP_PUBLIC_URL'] = 'https://old.example.com';

        $setting = $this->setting();
        $setting->save('https://new.example.com/');

        self::assertSame('https://old.example.com', $setting->current(), 'the running process keeps what it started with');
        self::assertSame('https://new.example.com', $setting->stored(), 'the next start reads the new one');
    }

    public function testAHubAddressThatOnlyFollowedTheOldAddressIsDropped(): void
    {
        // A restored backup carries one. Left on file it would keep every
        // browser subscribing at the address that was just replaced.
        file_put_contents(
            $this->secretsFile,
            "APP_PUBLIC_URL=https://old.example.com\nMERCURE_PUBLIC_URL=https://old.example.com/.well-known/mercure\nMERCURE_JWT_SECRET=kept\n",
        );

        $this->setting()->save('https://new.example.com');

        $stored = (new GeneratedSecretsFile($this->secretsFile))->read();

        self::assertArrayNotHasKey('MERCURE_PUBLIC_URL', $stored, 'so that it is derived from the new address again');
        self::assertSame('kept', $stored['MERCURE_JWT_SECRET'] ?? null, 'nothing else on file is touched');
    }

    public function testAHubAddressOfItsOwnSurvivesAChange(): void
    {
        file_put_contents(
            $this->secretsFile,
            "APP_PUBLIC_URL=https://old.example.com\nMERCURE_PUBLIC_URL=https://hub.example.net/.well-known/mercure\n",
        );

        $this->setting()->save('https://new.example.com');

        self::assertSame(
            'https://hub.example.net/.well-known/mercure',
            (new GeneratedSecretsFile($this->secretsFile))->read()['MERCURE_PUBLIC_URL'] ?? null,
        );
    }

    private function setting(): PublicUrlSetting
    {
        return new PublicUrlSetting(
            new GeneratedSecretsFile($this->secretsFile),
            new WorkerRestartSignal(new ArrayAdapter()),
            new NullLogger(),
        );
    }
}
