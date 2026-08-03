import { test as base } from "@playwright/test";
import { mkdirSync, writeFileSync } from "node:fs";
import { TEST_USER, login, seed, seedUser } from "./config";

/**
 * The `test` every spec imports, instead of `@playwright/test` directly.
 *
 * It exists for one reason: each parallel worker owns its own user, so each
 * worker needs its own signed-in session. The old arrangement had a single
 * `auth.setup.ts` writing one storage-state file for the whole run, which only
 * works while there is exactly one worker.
 *
 * Two fixtures do the work:
 *
 *   `workerAuth` — worker-scoped and automatic. Creates this slot's user, seeds
 *   its mailbox, signs in once through the real form, and saves the cookies.
 *   Runs once per worker, not once per test.
 *
 *   `storageState` — the built-in fixture, overridden to point at that file.
 *   Overriding it (rather than setting `storageState` in the project config) is
 *   what makes the path per-worker, since project config is static.
 *
 * A spec that wants to be signed out still says
 * `test.use({ storageState: { cookies: [], origins: [] } })` and that wins, as
 * before — but it now gets a per-worker *user* to log in as, via TEST_USER.
 */

const AUTH_DIR = "playwright/.auth";

/** Raw V8 coverage, one file per test — aggregated by bin/js-coverage.mjs. */
const COVERAGE_DIR = "var/js-coverage";

export const test = base.extend<object, { workerAuth: string }>({
    workerAuth: [
        async ({ browser }, use, workerInfo) => {
            const statePath = `${AUTH_DIR}/user-${workerInfo.parallelIndex}.json`;

            // Idempotent: re-running the suite against a warm database finds
            // the user already there and just refreshes the password.
            seedUser();

            // Before anything else can seed. seed-label and seed-attachment
            // both hard-fail when the account seed-mail creates is missing, and
            // a freshly minted per-worker user owns nothing yet.
            seed("seed-mail");

            // baseURL explicitly: a context made straight off `browser` does
            // not inherit the project's `use` block, and login() navigates to
            // the relative "/login".
            const context = await browser.newContext({
                baseURL: workerInfo.project.use.baseURL,
            });
            const page = await context.newPage();

            await login(page, TEST_USER.email, TEST_USER.password);

            mkdirSync(AUTH_DIR, { recursive: true });
            await context.storageState({ path: statePath });
            await context.close();

            await use(statePath);
        },
        { scope: "worker", auto: true },
    ],

    storageState: async ({ workerAuth }, use) => {
        await use(workerAuth);
    },

    /**
     * V8 coverage for the application's own JavaScript, when asked for.
     *
     * Off unless E2E_JS_COVERAGE is set: collecting it slows every navigation
     * and writes a file per test, which is a poor trade for a suite usually
     * being run to find out whether something works.
     *
     * Written raw and aggregated afterwards rather than reported per test,
     * because the numbers only mean anything once every test's ranges for a
     * given script have been unioned — a controller exercised by three specs
     * is not three separate coverages of it.
     */
    page: async ({ page }, use, testInfo) => {
        if (undefined === process.env.E2E_JS_COVERAGE) {
            await use(page);

            return;
        }

        await page.coverage.startJSCoverage();

        await use(page);

        // A page the test closed itself has nothing left to report, which is
        // not a reason to fail the run.
        let entries: unknown[] = [];

        try {
            entries = await page.coverage.stopJSCoverage();
        } catch {
            return;
        }

        mkdirSync(COVERAGE_DIR, { recursive: true });
        writeFileSync(`${COVERAGE_DIR}/${testInfo.testId}.json`, JSON.stringify(entries));
    },
});

/**
 * Re-exported so a spec has one import for everything Playwright, rather than
 * importing `test` from here and `Page` from the package next to it.
 */
export { expect, devices } from "@playwright/test";
export type {
    BrowserContext,
    Locator,
    Page,
    Response,
} from "@playwright/test";
