import { defineConfig, devices } from "@playwright/test";

/**
 * plMail end-to-end configuration.
 *
 * Two ways to run:
 *
 *   npm run test:e2e:docker  — against the compose.test.yaml stack (local default)
 *   npm run test:e2e         — against a PHP built-in server this config starts (CI)
 *
 * Every worker owns a dedicated user and signs in once for itself, through a
 * worker-scoped fixture in tests/e2e/support/test.ts. That is why specs import
 * `test` from there rather than from @playwright/test, and why there is no
 * `setup` project any more — one shared storage-state file cannot serve
 * workers that are logged in as different people.
 */

// Docker mode just supplies defaults for the two variables that already drive
// everything, so the rest of the config stays single-path.
if (process.env.E2E_DOCKER) {
  const port = process.env.TEST_HTTP_PORT ?? "8001";
  process.env.E2E_BASE_URL ??= `http://127.0.0.1:${port}`;
  process.env.E2E_CONSOLE ??=
    "docker compose -f compose.test.yaml exec -T app php bin/console";
}

/** An app we did not start — so this config must not try to start one. */
const EXTERNAL_APP = !!process.env.E2E_BASE_URL;

const BASE_URL = process.env.E2E_BASE_URL ?? "http://127.0.0.1:8000";
// Storage state is per worker now and lives in tests/e2e/support/test.ts —
// there is no single path for the config to name.

export default defineConfig({
  testDir: "./tests/e2e",
  // Parallel across FILES, serial within one. Deliberately not fullyParallel:
  // several specs depend on the order of tests inside their own file — the
  // integration describes build on each other, twofactor enrols before it
  // signs in — and file granularity preserves that for free, without any
  // spec having to declare serial mode. It also means one file sees one
  // worker, so the per-worker user (tests/e2e/support/config.ts) is stable
  // for the whole file.
  fullyParallel: false,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  // Was 1 everywhere the specs owned the database, because they all shared one
  // user and `seed-mail` wipes the mailbox it seeds. Each worker now owns a
  // separate user, so they no longer collide. CI gets 2 (ubuntu-latest is 2
  // vCPU on private repos); locally 4, above which the single app container
  // rather than the runner becomes the limit.
  workers: process.env.CI ? 2 : 4,
  reporter: process.env.CI
    ? [["github"], ["html", { open: "never" }]]
    : [["list"], ["html", { open: "never" }]],

  // A locator that will never match should fail in seconds, not stall for the
  // 30s test budget. Costs a green run nothing; makes a red one far quicker to
  // read.
  expect: { timeout: 5_000 },

  use: {
    baseURL: BASE_URL,
    trace: "on-first-retry",
    screenshot: "only-on-failure",
    // Off, not "retain-on-failure": that still screencasts every test and then
    // throws the recording away on the ~97 that pass. The trace above is the
    // more useful artifact and costs nothing unless a test is retried. For a
    // local failure, re-run the one spec with `--video on`.
    video: "off",
  },

  projects: [
    {
      name: "chromium",
      use: { ...devices["Desktop Chrome"] },
      // integrations.spec.ts and mercure.spec.ts are handled separately below.
      testIgnore: /(integrations|mercure)\.spec\.ts/,
      // No `storageState` here, and no `setup` project: signing in is now a
      // worker-scoped fixture in tests/e2e/support/test.ts, because the path
      // has to differ per worker and project config is static.
    },
    {
      // The specs per-worker users cannot isolate.
      //
      // integrations: IntegrationProviderConfig and MailProviderConfig are both
      // unique on `provider` with no user column — genuinely install-wide
      // state. Two workers touching it would race, and it reaches further than
      // it looks: whether the onboarding wizard offers its integrations step
      // depends on what an admin has configured globally, so this spec can
      // change what onboarding.spec.ts sees.
      //
      // mercure: it stops and restarts the hub container to prove the stream
      // recovers on its own. There is one hub for the whole stack, so running
      // that alongside anything else takes live updates away from specs that
      // did not ask for it — which is exactly how it first showed up, as an
      // integrations failure that passed on its own.
      //
      // One worker, and `dependencies` so it starts only once everything else
      // has finished.
      name: "chromium-exclusive",
      use: { ...devices["Desktop Chrome"] },
      testMatch: /(integrations|mercure)\.spec\.ts/,
      dependencies: ["chromium"],
    },
  ],

  // Only when we are expected to boot the app ourselves: pointing E2E_BASE_URL
  // at a stack somebody else started, and leaving this set anyway, would race a
  // second server onto the same fixtures.
  webServer: EXTERNAL_APP
    ? undefined
    : {
        // PHP's own server, NOT `symfony serve`, and this is not a preference.
        //
        // The Symfony CLI detects running Docker containers and injects its own
        // environment into the app it serves — including DATABASE_URL, which it
        // OVERRIDES even when one is already set. Prove it with:
        //
        //   DATABASE_URL=postgresql://app:app@127.0.0.1:5432/app \
        //     symfony php -r 'echo getenv("DATABASE_URL");'
        //
        // On CI the Postgres service is a Docker container, so the workflow set
        // DATABASE_URL, `symfony serve` replaced it with credentials derived
        // from that container, and the app could not authenticate anybody. The
        // per-worker login fixture failed, and 103 tests failed behind it — a
        // cascade whose only real cause was one environment variable. PHPUnit
        // passed in the same job, because it never goes through the CLI.
        //
        // SYMFONY_SKIP_DOCKER_COMPOSE, SYMFONY_SKIP_DOCKER_SERVICES and
        // SYMFONY_DOCKER_ENV were all tried and none of them stop it; there is
        // no --no-docker flag. The built-in server has no detection to disable
        // and inherits the environment exactly as given, which is the property
        // that matters here.
        //
        // The router is tests/e2e/support/router.php and NOT public/index.php,
        // which would answer every asset request with a kernel boot and a 404 —
        // that file explains the rest.
        //
        // PHP_CLI_SERVER_WORKERS because that server is single-threaded
        // otherwise, and a browser opening a page requests dozens of assets at
        // once.
        // Redirected, not silenced. This server logs a request line plus an
        // Accepted and a Closing to stderr for every one of the ~60 requests a
        // page makes, and Playwright pipes stderr straight into the console:
        // one non-calendar slice of the suite produced 67,709 of those lines,
        // which is how a genuine failure gets buried on CI. The file is kept
        // and uploaded alongside the report, so a PHP fatal that never reached
        // the kernel — and so never reached a trace — is still readable.
        command:
          "php -S 127.0.0.1:8000 -t public tests/e2e/support/router.php 2>var/log/e2e-server.log",
        url: `${BASE_URL}/login`,
        reuseExistingServer: !process.env.CI,
        timeout: 120_000,
        env: {
          APP_ENV: "test",
          APP_DEBUG: "1",
          PHP_CLI_SERVER_WORKERS: "4",
        },
      },
});
