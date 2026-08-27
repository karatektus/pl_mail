<?php

declare(strict_types=1);

namespace App\Tests\Infrastructure\Deployment;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Dotenv\Dotenv;

/**
 * The shipped compose files must not be reconfigured by Symfony's `.env`.
 *
 * `docker compose` reads the `.env` file sitting next to compose.yaml to
 * resolve `${VAR}`, and the `.env` sitting next to ours is Symfony's: committed,
 * full of development defaults, and not written with compose in mind at all.
 * The consequence is that a `${VAR:-default}` in compose.yaml is not a default —
 * it applies only where `.env` happens to be silent, and anything `.env` does
 * say silently wins for every operator who installed the way the README says to.
 *
 * That is not hypothetical. `APP_ENV: ${APP_ENV:-prod}` met `APP_ENV=dev`, so
 * `git clone && docker compose up -d` booted the published prod image in dev
 * mode; bundles.php loads DebugBundle there, `composer install --no-dev` never
 * put it in the image, and all six app services crash-looped on a missing class
 * while the entrypoint blamed the database. Nobody caught it because both paths
 * the maintainers use avoid the file — truenas.compose.yaml hardcodes prod and
 * has no `.env` beside it, and a development checkout has an override that
 * builds an image which does have the dev dependencies.
 *
 * So the rule, and it is one rule rather than a list of variables to remember:
 * a compose file an operator runs against a checkout may interpolate a variable
 * only where doing so cannot change what runs — `.env` does not set it, or sets
 * it to exactly what the compose default already says. Anything that must be
 * one specific value in production is hardcoded, and dev overrides it from
 * compose.override.yaml.dist, which is the file whose whole job that is.
 *
 * Text rather than `docker compose config`: this has to fail in CI and on a
 * machine with no Docker daemon, and the parse is what is being asserted about.
 */
final class ComposeEnvironmentTest extends TestCase
{
    /**
     * The files an operator runs from a checkout, where `.env` is present.
     *
     * compose.override.yaml.dist is deliberately absent: it is the development
     * override, it is *meant* to follow `.env` into dev, and holding it to this
     * rule would break the thing the rule exists to protect.
     *
     * truenas.compose.yaml is absent for the opposite reason — it is pasted into
     * an appliance's YAML box with no `.env` anywhere near it, so interpolation
     * there resolves to the compose defaults and nothing else.
     */
    public const array OPERATOR_COMPOSE_FILES = [
        'compose.yaml',
        'compose.prod.yaml',
        // The demo overlay, and it belongs here for a sharper version of the
        // reason above: `.env` ships APP_DEMO_MODE=0, so a `${APP_DEMO_MODE:-1}`
        // in that file would resolve to 0 for every operator who cloned the
        // repo. The stack would come up healthy and simply not be a demo —
        // no /demo route, no button, and IMAP sync running against accounts
        // that point at documentation domains. It hardcodes the switch, and
        // this is what keeps it hardcoded.
        'compose.demo.yaml',
    ];

    /**
     * Overlays that define INFRASTRUCTURE rather than the app.
     *
     * They get the `.env` check and not the APP_ENV one, and the split is the
     * point rather than an exemption: an overlay with no app service has no
     * APP_ENV to hardcode, so putting it in the list above asserts something
     * that cannot be true and fails for a reason unrelated to what it guards.
     *
     * compose.pgvector.yaml redefines `database` alone. It passes the `.env`
     * check today for a reason that could quietly stop being true: neither
     * variable it interpolates — POSTGRES_VERSION and PGVECTOR_VERSION —
     * appears in `.env`, so an operator gets the compose defaults. Add either
     * one there and the tag this file BUILDS and the tag it RUNS can disagree,
     * which is a stack that comes up healthy on yesterday's extension.
     */
    public const array INFRASTRUCTURE_COMPOSE_FILES = [
        'compose.pgvector.yaml',
    ];

    /**
     * DATABASE_URL is the one variable allowed to disagree, on purpose.
     *
     * `.env` sets a credential-less DSN because Doctrine reads the driver out of
     * the scheme and a blank one has none, which breaks the prod cache warmup
     * during the image build. The entrypoint therefore tests the DSN for a
     * password rather than for emptiness, and splices the generated one in when
     * it finds none — see database_url_has_password() in docker-entrypoint.sh,
     * which carries the same explanation from the other side.
     */
    private const array EXEMPT = ['DATABASE_URL'];

    /** @return iterable<string, array{string}> */
    /** Every committed overlay an operator runs, app-defining or not. */
    public static function allComposeFiles(): iterable
    {
        foreach ([...self::OPERATOR_COMPOSE_FILES, ...self::INFRASTRUCTURE_COMPOSE_FILES] as $file) {
            yield $file => [$file];
        }
    }

    public static function operatorComposeFiles(): iterable
    {
        foreach (self::OPERATOR_COMPOSE_FILES as $file) {
            yield $file => [$file];
        }
    }

    #[DataProvider('allComposeFiles')]
    public function testNoComposeDefaultIsOverruledByTheCommittedDotEnv(string $file): void
    {
        $env = self::committedEnvironment();

        foreach (self::interpolations(self::projectDir() . '/' . $file) as $variable => $default) {
            if (in_array($variable, self::EXEMPT, true)) {
                continue;
            }

            if (!array_key_exists($variable, $env)) {
                continue;
            }

            self::assertSame(
                $default,
                $env[$variable],
                sprintf(
                    '%s interpolates ${%s} with the default "%s", but .env sets it to "%s" — and '
                    . 'compose reads .env, so "%s" is what every operator who cloned the repo '
                    . 'actually gets. Hardcode the production value in %s and let '
                    . 'compose.override.yaml.dist supply the development one.',
                    $file,
                    $variable,
                    $default,
                    $env[$variable],
                    $env[$variable],
                    $file,
                ),
            );
        }
    }

    /**
     * APP_ENV specifically, spelled out.
     *
     * The rule above already covers it, but only for as long as `.env` says
     * `dev` — and the failure this guards is bad enough (six services in a
     * restart loop, reported as a database outage) that it should not depend on
     * a value in another file to be caught. A hardcoded prod is also the only
     * answer here: unlike a URL or a DSN, there is no operator who wants to
     * choose this one.
     */
    #[DataProvider('operatorComposeFiles')]
    public function testTheAppEnvironmentIsHardcodedRatherThanInterpolated(string $file): void
    {
        $contents = self::withoutComments(self::read(self::projectDir() . '/' . $file));

        self::assertStringNotContainsString(
            '${APP_ENV',
            $contents,
            $file . ' must hardcode APP_ENV: prod. Interpolating it lets Symfony\'s committed '
            . '.env boot the prod image in dev mode, where DebugBundle is missing.',
        );
        self::assertMatchesRegularExpression(
            '/^\s*APP_ENV:\s*prod\s*$/m',
            $contents,
            $file . ' sets no APP_ENV at all; the image would fall back to its own.',
        );
    }

    /**
     * Every `${VAR}` and `${VAR:-default}` in a compose file, as name => default.
     *
     * Hand-scanned rather than regexed because the defaults nest — NTFY_BASE_URL
     * is built out of `${SERVER_NAME:-localhost}` and `${NTFY_PORT:-8090}` — and
     * because `$$VAR` inside the Mercure entrypoint script is an escaped dollar
     * that compose hands to the shell, not an interpolation. A regex gets one of
     * those two wrong.
     *
     * A variable appearing twice keeps its first default; the pair of them
     * disagreeing is a different bug than the one this file is about.
     *
     * @return array<string, string>
     */
    private static function interpolations(string $path): array
    {
        $contents = self::withoutComments(self::read($path));
        $length   = strlen($contents);
        $found    = [];

        for ($i = 0; $i < $length - 1; ++$i) {
            if ('$' !== $contents[$i]) {
                continue;
            }

            // An escaped dollar: compose emits a literal `$` and moves on, so
            // whatever follows is the container's business, not ours.
            if ('$' === $contents[$i + 1]) {
                ++$i;
                continue;
            }

            if ('{' !== $contents[$i + 1]) {
                continue;
            }

            $depth = 1;
            $end   = $i + 2;

            for (; $end < $length && $depth > 0; ++$end) {
                if ('{' === $contents[$end]) {
                    ++$depth;
                } elseif ('}' === $contents[$end]) {
                    --$depth;
                }
            }

            $expression = substr($contents, $i + 2, $end - $i - 3);
            $i          = $end - 1;

            // `${VAR:-default}`, `${VAR-default}`, `${VAR:?message}` and the
            // bare `${VAR}`. Only the first two carry a default worth comparing;
            // the others resolve to nothing when .env is silent.
            $separator = strcspn($expression, ':-?');
            $name      = substr($expression, 0, $separator);
            $rest      = substr($expression, $separator);
            $default   = str_starts_with($rest, ':-') || str_starts_with($rest, '-')
                ? ltrim($rest, ':-')
                : '';

            $found[$name] ??= $default;
        }

        return $found;
    }

    /**
     * `.env` as compose sees it: the committed file alone.
     *
     * Not `.env.local` and not `.env.$APP_ENV` — Symfony layers those, compose
     * does not read them at all, and a contributor's uncommitted local file must
     * not be able to make this pass or fail.
     *
     * @return array<string, string>
     */
    private static function committedEnvironment(): array
    {
        return (new Dotenv())->parse(self::read(self::projectDir() . '/.env'), '.env');
    }

    /**
     * The file with its full-line comments removed.
     *
     * Because compose.yaml explains this very rule in prose, and the prose has
     * to quote the `${APP_ENV:-prod}` it is warning about. A scanner that cannot
     * tell a comment from configuration reads the cautionary tale as the bug.
     *
     * Full-line only: a `#` mid-line may be inside a quoted value or a shell
     * script in a block scalar, and nothing in these files puts an interpolation
     * behind a trailing comment.
     */
    private static function withoutComments(string $contents): string
    {
        return preg_replace('/^[ \t]*#.*$/m', '', $contents) ?? $contents;
    }

    private static function read(string $path): string
    {
        $contents = file_get_contents($path);
        self::assertIsString($contents, $path . ' is missing');

        return $contents;
    }

    private static function projectDir(): string
    {
        return dirname(__DIR__, 3);
    }
}
