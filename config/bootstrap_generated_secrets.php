<?php

declare(strict_types=1);

/**
 * Make the secrets generated on first run visible to PHP, whichever way PHP was
 * started.
 *
 * The container entrypoint exports them before it execs the server, which
 * covers the web process and the workers — but `docker compose exec` bypasses
 * the entrypoint entirely, so `docker compose exec php php bin/console …` would
 * otherwise run with an empty APP_ENCRYPTION_KEY and fail on the first
 * credential it touched. That is the normal way to run a console command
 * against a plMail install, so it has to work.
 *
 * Loaded through composer's autoload.files, which runs before Symfony's Runtime
 * boots Dotenv. Order of precedence, highest first:
 *
 *   1. a real environment variable — an operator who supplies one is never
 *      overridden;
 *   2. the generated file;
 *   3. the defaults in .env, which for these names are deliberately empty.
 *
 * An empty value counts as absent: compose passes APP_ENCRYPTION_KEY through as
 * an empty string when nobody set it, and treating that as "already configured"
 * would defeat the whole arrangement.
 */

(static function (): void {
    $projectDir = \dirname(__DIR__);

    $path = $_SERVER['APP_SECRETS_FILE'] ?? $_ENV['APP_SECRETS_FILE'] ?? 'var/secrets/generated.env';

    if (false === str_starts_with((string) $path, '/')) {
        $path = $projectDir.'/'.$path;
    }

    $isSet = static fn (string $name): bool => '' !== trim((string) ($_SERVER[$name] ?? $_ENV[$name] ?? ''));

    $put = static function (string $name, string $value) use ($isSet): void {
        if ($isSet($name)) {
            return;
        }

        $_SERVER[$name] = $value;
        $_ENV[$name]    = $value;
    };

    if (is_readable($path) && is_file($path)) {
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (str_starts_with($line, '#')) {
                continue;
            }

            [$name, $value] = array_pad(explode('=', $line, 2), 2, '');

            if ('' !== $name) {
                $put($name, $value);
            }
        }
    }

    // The database password is generated too, so the connection string has to
    // be assembled after the fact — the same rule the entrypoint applies, kept
    // here as well so an `exec` session reaches the same database.
    if (false === $isSet('DATABASE_URL') && true === $isSet('POSTGRES_PASSWORD')) {
        $get = static fn (string $name, string $default): string => '' !== trim((string) ($_SERVER[$name] ?? $_ENV[$name] ?? ''))
            ? trim((string) ($_SERVER[$name] ?? $_ENV[$name]))
            : $default;

        $put('DATABASE_URL', sprintf(
            'postgresql://%s:%s@%s:5432/%s?serverVersion=%s&charset=%s',
            $get('POSTGRES_USER', 'app'),
            rawurlencode($get('POSTGRES_PASSWORD', '')),
            $get('POSTGRES_HOST', 'database'),
            $get('POSTGRES_DB', 'app'),
            $get('POSTGRES_VERSION', '18'),
            $get('POSTGRES_CHARSET', 'utf8'),
        ));
    }
})();
