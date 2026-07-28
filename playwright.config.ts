import { defineConfig, devices } from "@playwright/test";

/**
 * plMail end-to-end configuration.
 *
 * Two ways to run:
 *
 *   npm run test:e2e:docker  — against the compose.test.yaml stack (local default)
 *   npm run test:e2e         — against a Symfony local server this config starts (CI)
 *
 * A dedicated `setup` project logs in through the real UI once and saves the
 * authenticated storage state, which the main `chromium` project reuses so
 * every non-auth spec starts already signed in.
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
const STORAGE_STATE = "playwright/.auth/user.json";

export default defineConfig({
  testDir: "./tests/e2e",
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  // Serialised whenever the specs own the database: several reseed shared
  // fixtures in beforeEach, so concurrent workers would clobber each other.
  workers: process.env.CI || EXTERNAL_APP ? 1 : undefined,
  reporter: process.env.CI
    ? [["github"], ["html", { open: "never" }]]
    : [["list"], ["html", { open: "never" }]],

  use: {
    baseURL: BASE_URL,
    trace: "on-first-retry",
    screenshot: "only-on-failure",
    video: "retain-on-failure",
  },

  projects: [
    {
      name: "setup",
      testMatch: /.*\.setup\.ts/,
    },
    {
      name: "chromium",
      use: {
        ...devices["Desktop Chrome"],
        storageState: STORAGE_STATE,
      },
      dependencies: ["setup"],
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
