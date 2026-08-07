<?php

declare(strict_types=1);

namespace App\Infrastructure\Setup;

/**
 * The variables this process was actually started with — as distinct from the
 * ones something later put into `$_SERVER`.
 *
 * The distinction is the whole reason this class exists, and it is not
 * cosmetic. By the time application code runs, `$_SERVER` is a merge of three
 * sources with no marker saying which is which:
 *
 *   1. the real process environment (compose, the shell, `docker run -e`);
 *   2. `var/secrets/generated.env`, folded in by
 *      `config/bootstrap_generated_secrets.php` before Symfony's Runtime boots;
 *   3. `.env` / `.env.local`, folded in by Dotenv afterwards — and Dotenv skips
 *      any name the two steps above already set, so it is the weakest of the
 *      three inside PHP.
 *
 * `getenv()` sees only the first, because neither of the other two calls
 * `putenv()`. That is what makes it possible to answer the one question the
 * config-backup import has to answer honestly: *if I write this value into the
 * generated secrets file, will the next container start actually use it, or is
 * there something in the process environment that will win over it again?*
 *
 * An empty value counts as absent, for the same reason it does everywhere else
 * in this codebase: compose passes `APP_SECRET: ${APP_SECRET:-}` through as an
 * empty string when nobody set one, and the entrypoint's own
 * `load_generated_secrets` tests `[ -n "$(printenv "$key")" ]`. Treating "" as
 * configured would mark every generated secret as pinned by compose.
 */
interface ProcessEnvironment
{
    /** The value this process was started with, or null when there is none. */
    public function get(string $name): ?string;
}
