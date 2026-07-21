import { defineConfig, devices } from "@playwright/test";

/**
 * plMail end-to-end configuration.
 *
 * The app is booted via the Symfony local server in the `test` environment.
 * A dedicated `setup` project logs in through the real UI once and saves the
 * authenticated storage state, which the main `chromium` project reuses so
 * every non-auth spec starts already signed in.
 */

const BASE_URL = process.env.E2E_BASE_URL ?? "http://127.0.0.1:8000";
const STORAGE_STATE = "playwright/.auth/user.json";

export default defineConfig({
  testDir: "./tests/e2e",
  fullyParallel: true,
  forbidOnly: !!process.env.CI,
  retries: process.env.CI ? 1 : 0,
  workers: process.env.CI ? 1 : undefined,
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

  webServer: {
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
