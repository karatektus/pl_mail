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
 *   2. the generated file, and what is derived from it below (the database
 *      URL, the hub URL, the JWT key paths);
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

    // The JWT keypair lives beside the generated secrets, because that is the
    // one directory every service shares and every layout keeps: the standard
    // compose mounts it as a volume, the TrueNAS layouts point APP_SECRETS_FILE
    // into their data dataset. .env used to name var/secrets/jwt outright,
    // which is that directory only while APP_SECRETS_FILE keeps its default —
    // on TrueNAS it was each container's own throwaway filesystem, so every
    // service minted a keypair of its own on every start, and a token one of
    // them signed was one the others, and the next restart, would refuse.
    $secretsDir = \dirname($path);

    $put('JWT_SECRET_KEY', $secretsDir.'/jwt/private.pem');
    $put('JWT_PUBLIC_KEY', $secretsDir.'/jwt/public.pem');

    // The database password is generated too, so the connection string has to
    // be assembled after the fact — the same rule the entrypoint applies, kept
    // here as well so an `exec` session reaches the same database.
    //
    // "Is it set" is not the test, because it is always set: compose resolves
    // ${DATABASE_URL:-} against the project .env, whose default is deliberately
    // a credential-less DSN (Doctrine needs a driver in the scheme, and a blank
    // DSN has none — see the note in .env). Only a DSN carrying a password
    // represents a database an operator actually chose; the placeholder must
    // not suppress the generated one, or nothing can authenticate.
    $databaseUrlHasPassword = static function (): bool {
        $password = parse_url(trim((string) ($_SERVER['DATABASE_URL'] ?? $_ENV['DATABASE_URL'] ?? '')), PHP_URL_PASS);

        return \is_string($password) && '' !== $password;
    };

    if (false === $databaseUrlHasPassword() && true === $isSet('POSTGRES_PASSWORD')) {
        $get = static fn (string $name, string $default): string => '' !== trim((string) ($_SERVER[$name] ?? $_ENV[$name] ?? ''))
            ? trim((string) ($_SERVER[$name] ?? $_ENV[$name]))
            : $default;

        // Assigned rather than $put(): the placeholder DSN counts as "set", so
        // $put would decline to replace it.
        $assembled = sprintf(
            'postgresql://%s:%s@%s:5432/%s?serverVersion=%s&charset=%s',
            $get('POSTGRES_USER', 'app'),
            rawurlencode($get('POSTGRES_PASSWORD', '')),
            $get('POSTGRES_HOST', 'database'),
            $get('POSTGRES_DB', 'app'),
            $get('POSTGRES_VERSION', '18'),
            $get('POSTGRES_CHARSET', 'utf8'),
        );

        $_SERVER['DATABASE_URL'] = $assembled;
        $_ENV['DATABASE_URL']    = $assembled;
    }

    // The hub is proxied on the app's own origin (frankenphp/Caddyfile routes
    // /.well-known/mercure to the mercure container), so the browser-facing hub
    // URL follows APP_PUBLIC_URL — the address the admin chose during setup.
    // Derived here rather than configured: it is one less thing to fill in, and
    // it cannot drift from the public URL. An explicit MERCURE_PUBLIC_URL still
    // wins, for the install whose hub really does live somewhere else.
    //
    // "Is it set" is not quite the test, for the reason it is not for the
    // database URL above: on the stock compose file it is always set.
    // compose.yaml defaults it to https://localhost/.well-known/mercure, which
    // is right for the install opened at https://localhost and for no other —
    // one set up from another machine on the LAN told every browser to
    // subscribe on its own loopback, and the lists never refreshed. That one
    // value is the placeholder nobody chose, so a public address stored during
    // setup replaces it. With no address stored it stays, and an install from
    // before setup asked the question keeps working where it always did.
    $publicUrl = trim((string) ($_SERVER['APP_PUBLIC_URL'] ?? $_ENV['APP_PUBLIC_URL'] ?? ''));
    $hubUrl    = trim((string) ($_SERVER['MERCURE_PUBLIC_URL'] ?? $_ENV['MERCURE_PUBLIC_URL'] ?? ''));

    if ('' !== $publicUrl && ('' === $hubUrl || 'https://localhost/.well-known/mercure' === $hubUrl)) {
        $derived = rtrim($publicUrl, '/').'/.well-known/mercure';

        $_SERVER['MERCURE_PUBLIC_URL'] = $derived;
        $_ENV['MERCURE_PUBLIC_URL']    = $derived;
    }

    // The subscriber cookie takes the `__Secure-` prefix where a browser will
    // accept it, which is exactly where the page arrives over HTTPS, and the
    // public address is the one thing here that says whether it does. Without
    // an https address the name is left unset and services.yaml's prefix-less
    // default answers: an install opened on http://ip:port — every NAS app on
    // its first day — would otherwise set a cookie the browser throws away, and
    // live updates would never start. It used to take an operator who knew to
    // set MERCURE_COOKIE_NAME. One who does still wins; this only fills a gap.
    //
    // The hub is not told about the prefix at all. The web server renames the
    // cookie on the way through (frankenphp/Caddyfile) and the hub reads the
    // bare name (BackgroundProcesses), so the two cannot disagree while one of
    // them has restarted since the address changed and the other has not.
    if (false === $isSet('MERCURE_COOKIE_NAME') && true === str_starts_with(strtolower($publicUrl), 'https://')) {
        $put('MERCURE_COOKIE_NAME', '__Secure-mercure_access_token');
    }

    // APP_STORAGE_DIR is relative to the project root everywhere it is used,
    // and the paths stored with every attachment begin with it. An absolute
    // path is what a deployment that mounts one directory would rather write,
    // and some catalogues insist on it, so one inside the project is accepted
    // and brought back to the relative form here — before anything reads it,
    // which is what keeps the stored paths the same either way. One outside the
    // project has no relative form and is refused rather than half-honoured.
    $storageDir = trim((string) ($_SERVER['APP_STORAGE_DIR'] ?? $_ENV['APP_STORAGE_DIR'] ?? ''));

    if (true === str_starts_with($storageDir, '/')) {
        $relative = trim(substr(rtrim($storageDir, '/'), \strlen($projectDir)), '/');

        if (false === str_starts_with(rtrim($storageDir, '/').'/', $projectDir.'/') || '' === $relative) {
            throw new RuntimeException(sprintf('APP_STORAGE_DIR is "%s", which is not a directory inside %s. Use a path below it, absolute or relative.', $storageDir, $projectDir));
        }

        $_SERVER['APP_STORAGE_DIR'] = $relative;
        $_ENV['APP_STORAGE_DIR']    = $relative;
    }
})();
