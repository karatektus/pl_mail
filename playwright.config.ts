import { defineConfig, devices } from "@playwright/test";

/**
 * plMail end-to-end configuration.
 *
 * Two ways to run:
 *
 *   npm run test:e2e:docker  — against the compose.test.yaml stack (local default)
 *   npm run test:e2e         — against a Symfony local server this config starts (CI)
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
      // integrations.spec.ts is handled separately below.
      testIgnore: /integrations\.spec\.ts/,
      // No `storageState` here, and no `setup` project: signing in is now a
      // worker-scoped fixture in tests/e2e/support/test.ts, because the path
      // has to differ per worker and project config is static.
    },
    {
      // The one spec per-worker users cannot isolate.
      //
      // IntegrationProviderConfig and MailProviderConfig are both unique on
      // `provider` with no user column — genuinely install-wide state. Two
      // workers touching it would race, and it reaches further than it looks:
      // whether the onboarding wizard offers its integrations step depends on
      // what an admin has configured globally, so this spec can change what
      // onboarding.spec.ts sees.
      //
      // One worker, and `dependencies` so it starts only once everything else
      // has finished.
      name: "chromium-exclusive",
      use: { ...devices["Desktop Chrome"] },
      testMatch: /integrations\.spec\.ts/,
      dependencies: ["chromium"],
    },
  ],

  // Only when we are expected to boot the app ourselves. `symfony serve` does
  // not exist in the container image, and pointing E2E_BASE_URL elsewhere while
  // leaving this set would still race a second server onto the same fixtures.
  webServer: EXTERNAL_APP
    ? undefined
    : {
        command: "symfony serve --port=8000 --no-tls --allow-http",
        url: `${BASE_URL}/login`,
        reuseExistingServer: !process.env.CI,
        timeout: 120_000,
        env: {
          APP_ENV: "test",
          APP_DEBUG: "1",
        },
      },
});
