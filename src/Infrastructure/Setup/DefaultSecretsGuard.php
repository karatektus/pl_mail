<?php

declare(strict_types=1);

namespace App\Infrastructure\Setup;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Refuses to let a production install run on the secrets that ship with the
 * repository.
 *
 * New installs get generated secrets from the entrypoint, so this only fires
 * for the case generation cannot cover: a deployment that carries an old
 * compose file, an old .env, or the documented placeholders forward. Those
 * values work perfectly, which is exactly the problem — nothing fails, and the
 * install stays readable by anyone who has the repository.
 *
 * Checked in prod only. Development is expected to run on the committed
 * values; that is what they are for.
 */
final readonly class DefaultSecretsGuard
{
    /** The literal values committed to .env and the compose files. */
    private const array SHIPPED = [
        'APP_SECRET'           => 'd828015e819548676701972e0f855372',
        'APP_ENCRYPTION_KEY'   => 'chHhLxNFHCRA7exigGBw08i/PWQfIxyWxn63oDUC4/s=',
        'MERCURE_JWT_SECRET'   => '!ChangeThisMercureHubJWTSecretKey!',
    ];

    /** Appears inside DATABASE_URL rather than being the whole value. */
    private const string SHIPPED_DB_PASSWORD = '!ChangeMe!';

    public function __construct(
        #[Autowire('%kernel.environment%')]
        private string $environment,
    ) {
    }

    /**
     * @return list<string> The names still holding a shipped value, empty when
     *                      the install is clean or is not production
     */
    public function findShippedDefaults(): array
    {
        if ('prod' !== $this->environment) {
            return [];
        }

        $offenders = [];

        foreach (self::SHIPPED as $name => $shipped) {
            if ($shipped === trim((string) ($_SERVER[$name] ?? $_ENV[$name] ?? ''))) {
                $offenders[] = $name;
            }
        }

        $databaseUrl = (string) ($_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? '');

        if (str_contains($databaseUrl, self::SHIPPED_DB_PASSWORD)) {
            $offenders[] = 'DATABASE_URL';
        }

        return $offenders;
    }

    /**
     * A single message naming what to do about each offender, or null when
     * there is nothing to report.
     */
    public function describe(): ?string
    {
        $offenders = $this->findShippedDefaults();

        if ([] === $offenders) {
            return null;
        }

        $advice = [
            'APP_SECRET'         => 'unset it and let the entrypoint generate one',
            'APP_ENCRYPTION_KEY' => 'unset it and let the entrypoint generate one — but only on an install with no mail accounts yet, since changing it makes existing credentials unreadable',
            'MERCURE_JWT_SECRET' => 'set your own on both the app services and the mercure service; they must match',
            'DATABASE_URL'       => 'set POSTGRES_PASSWORD to something of your own',
        ];

        $lines = ['These are still set to the values that ship with plMail:'];

        foreach ($offenders as $name) {
            $lines[] = sprintf('  %s — %s', $name, $advice[$name] ?? 'set your own value');
        }

        return implode("\n", $lines);
    }
}
