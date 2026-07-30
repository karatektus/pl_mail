<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Setup;

use App\Infrastructure\Setup\DefaultSecretsGuard;
use PHPUnit\Framework\TestCase;

/**
 * The check that stops a production install running on the secrets that ship
 * with the repository.
 *
 * Generation covers new installs; this covers the case it cannot — an upgrade
 * that carries an old compose file or .env forward. Those values work, which is
 * why nobody notices, and APP_ENCRYPTION_KEY being one of them means the stored
 * mail credentials are readable by anyone who has cloned plMail.
 */
final class DefaultSecretsGuardTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $restore = [];

    protected function tearDown(): void
    {
        foreach ($this->restore as $name => $value) {
            if (false === $value) {
                unset($_SERVER[$name], $_ENV[$name]);

                continue;
            }

            $_SERVER[$name] = $value;
            $_ENV[$name]    = $value;
        }

        $this->restore = [];
    }

    public function testItNamesEveryShippedSecretStillInUse(): void
    {
        $this->setEnv('APP_SECRET', 'd828015e819548676701972e0f855372');
        $this->setEnv('APP_ENCRYPTION_KEY', 'chHhLxNFHCRA7exigGBw08i/PWQfIxyWxn63oDUC4/s=');
        $this->setEnv('MERCURE_JWT_SECRET', '!ChangeThisMercureHubJWTSecretKey!');
        $this->setEnv('DATABASE_URL', 'postgresql://app:!ChangeMe!@database:5432/app');

        self::assertSame(
            ['APP_SECRET', 'APP_ENCRYPTION_KEY', 'MERCURE_JWT_SECRET', 'DATABASE_URL'],
            (new DefaultSecretsGuard('prod'))->findShippedDefaults(),
        );
    }

    public function testAnInstallWithItsOwnSecretsPasses(): void
    {
        $this->setEnv('APP_SECRET', base64_encode(random_bytes(32)));
        $this->setEnv('APP_ENCRYPTION_KEY', base64_encode(random_bytes(32)));
        $this->setEnv('MERCURE_JWT_SECRET', 'a-secret-of-my-own');
        $this->setEnv('DATABASE_URL', 'postgresql://app:hunter2@database:5432/app');

        $guard = new DefaultSecretsGuard('prod');

        self::assertSame([], $guard->findShippedDefaults());
        self::assertNull($guard->describe());
    }

    /**
     * Development is expected to run on the committed values — that is what
     * they are for, and failing there would break every contributor's stack.
     */
    public function testDevelopmentIsLeftAlone(): void
    {
        $this->setEnv('APP_SECRET', 'd828015e819548676701972e0f855372');
        $this->setEnv('APP_ENCRYPTION_KEY', 'chHhLxNFHCRA7exigGBw08i/PWQfIxyWxn63oDUC4/s=');

        self::assertSame([], (new DefaultSecretsGuard('dev'))->findShippedDefaults());
        self::assertSame([], (new DefaultSecretsGuard('test'))->findShippedDefaults());
    }

    public function testTheMessageSaysWhatToDoAboutEachOne(): void
    {
        $this->setEnv('APP_ENCRYPTION_KEY', 'chHhLxNFHCRA7exigGBw08i/PWQfIxyWxn63oDUC4/s=');
        $this->setEnv('APP_SECRET', 'mine');
        $this->setEnv('MERCURE_JWT_SECRET', 'mine');
        $this->setEnv('DATABASE_URL', 'postgresql://app:mine@database:5432/app');

        $message = (new DefaultSecretsGuard('prod'))->describe();

        self::assertNotNull($message);
        self::assertStringContainsString('APP_ENCRYPTION_KEY', $message);
        self::assertStringContainsString('no mail accounts yet', $message);
        self::assertStringNotContainsString('APP_SECRET —', $message);
    }

    private function setEnv(string $name, string $value): void
    {
        if (false === \array_key_exists($name, $this->restore)) {
            $this->restore[$name] = $_SERVER[$name] ?? false;
        }

        $_SERVER[$name] = $value;
        $_ENV[$name]    = $value;
    }
}
