import { defineConfig } from "@playwright/test";
import { dirname, join } from "node:path";
import { APP_TIMEZONE, BASE_URL } from "../../../tests/e2e/support/config";
import { AUTH_STATE } from "./support";

const OUT = process.env.VERIFY_OUT ?? "evidence/scratch-run/test-results";

export default defineConfig({
  testDir: ".",
  testMatch: ["pages.spec.ts", "scratch/**/*.spec.ts"],
  outputDir: OUT,
  fullyParallel: false,
  workers: 1,
  retries: 0,
  timeout: 90_000,
  expect: { timeout: 10_000 },
  reporter: [["list"], ["json", { outputFile: join(dirname(OUT), "results.json") }]],
  use: {
    baseURL: BASE_URL,
    timezoneId: APP_TIMEZONE,
    locale: "en-GB",
    screenshot: "only-on-failure",
    trace: "retain-on-failure",
    video: "off",
  },
  projects: [
    { name: "signin", testMatch: "signin.setup.ts" },
    {
      name: "desktop",
      dependencies: ["signin"],
      use: { storageState: AUTH_STATE, viewport: { width: 1280, height: 800 } },
    },
    {
      name: "phone",
      dependencies: ["signin"],
      use: {
        storageState: AUTH_STATE,
        viewport: { width: 390, height: 844 },
        deviceScaleFactor: 2,
        isMobile: true,
        hasTouch: true,
      },
    },
  ],
});
