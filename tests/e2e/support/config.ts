import { expect, type Locator, type Page } from "@playwright/test";
import { execSync } from "node:child_process";

/**
 * Which parallel slot this process is.
 *
 * Playwright sets TEST_PARALLEL_INDEX in every worker before it loads a test
 * file, so a module-level constant here is already worker-specific.
 *
 * Deliberately the *parallel* index and not TEST_WORKER_INDEX: the latter keeps
 * counting up when a worker is replaced after a crash, which would seed an
 * unbounded number of users over a long run. The parallel index is bounded by
 * the worker count, so the pool of fixture users stays small and fixed.
 */
export const WORKER_SLOT = process.env.TEST_PARALLEL_INDEX ?? "0";

const SLOT = WORKER_SLOT;

/**
 * The user this worker owns.
 *
 * One user per parallel slot, not one user for the suite. `app:test:seed-mail`
 * deletes every thread on the account it seeds — harmless when a worker only
 * ever wipes its own mailbox, and destructive the moment two workers share one.
 * Giving each slot its own user is what makes `workers > 1` safe, and it is why
 * the seed commands grew an `--email` option (see App\Command\Test\TargetsTestUser).
 *
 * A side effect worth having: the suite becomes re-runnable against a warm
 * database, because nothing depends on a fixed address whose rows accumulate.
 */
export const TEST_USER = {
    email: `e2e-w${SLOT}@plmail.test`,
    password: process.env.APP_DEV_USER_PASSWORD ?? "e2e-password-change-me",
};

/**
 * The admin this worker owns, for the specs that need ROLE_ADMIN.
 *
 * Per-slot for the same reason: admin-panels.spec.ts and integrations.spec.ts
 * both used one hardcoded address, so with file-level parallelism they could
 * land on different workers and overwrite each other's setup.
 *
 * Note this is NOT the `e2e-admin@plmail.test` the PHPUnit controller tests
 * look for — that one is seeded by the test stack itself (compose.test.yaml),
 * so those tests no longer depend on the browser suite having run first.
 */
export const TEST_ADMIN = {
    email: `e2e-admin-w${SLOT}@plmail.test`,
    password: "e2e-admin-password",
};

/**
 * Subjects seeded by `app:test:seed-mail` (kept in sync with the PHP command).
 * Each maps to one row the mail-UI specs act on independently.
 */
export const INBOX_SUBJECTS = {
    star: "E2E Star Me",
    archive: "E2E Archive Me",
    trash: "E2E Trash Me",
    read: "E2E Read Me",
} as const;

/**
 * How to invoke the Symfony console. Override E2E_CONSOLE when `php` is not on
 * PATH or the app runs elsewhere, e.g.
 *
 *   E2E_CONSOLE="docker compose exec -T php php bin/console"
 *   E2E_CONSOLE="symfony console"
 *
 * Each spec names the seed tasks it needs, so the override stays orthogonal to
 * *which* seeds run — a single "whole command" override could not express that,
 * and silently dropped label seeding when the label spec used it.
 */
const CONSOLE = process.env.E2E_CONSOLE ?? "php bin/console";

/**
 * Runs `app:test:<task>` for each task, in order, against this worker's user.
 *
 * The `--email` is not optional decoration: without it every worker would seed
 * the same mailbox, and `seed-mail` wipes the account it seeds.
 */
export function seed(...tasks: string[]): void {
    for (const task of tasks) {
        execSync(`${CONSOLE} app:test:${task} --email=${TEST_USER.email}`, {
            stdio: "inherit",
            env: { ...process.env, APP_ENV: "test" },
        });
    }
}

/**
 * Creates (or refreshes) a fixture user, defaulting to this worker's own.
 *
 * `--pending-onboarding` leaves the setup wizard unfinished; everything else
 * gets it marked complete, because the wizard opens over a backdrop that
 * swallows every click.
 */
export function seedUser(
    options: { email?: string; password?: string; admin?: boolean; pendingOnboarding?: boolean } = {},
): void {
    const email = options.email ?? TEST_USER.email;
    const password = options.password ?? TEST_USER.password;

    consoleCommand(
        [
            `app:test:seed-user --email=${email} --password=${password}`,
            options.admin ? "--admin" : "",
            options.pendingOnboarding ? "--pending-onboarding" : "",
        ]
            .filter(Boolean)
            .join(" "),
    );
}

/**
 * Runs an arbitrary console command, for the few specs that need one which is
 * not an `app:test:` seed — currently only the two-factor spec, which has to
 * be able to put the shared test user back the way it found them even when the
 * test that enabled 2FA failed halfway.
 */
export function consoleCommand(command: string): void {
    execSync(`${CONSOLE} ${command}`, {
        stdio: "inherit",
        env: { ...process.env, APP_ENV: "test" },
    });
}

/**
 * The inbox row (`<li id="thread_{id}">`) carrying the given subject.
 */
export function mailRow(page: Page, subject: string): Locator {
    return page
        .locator('#message-list li[data-controller="mail--message-row"]')
        .filter({ hasText: subject });
}

/**
 * Drives the real login form at /login and waits for the authenticated
 * shell to land on the inbox.
 */
export async function login(
    page: Page,
    email: string = TEST_USER.email,
    password: string = TEST_USER.password,
): Promise<void> {
    await page.goto("/login");

    // The template may prefill from APP_DEV_USER_*; fill explicitly so the
    // test exercises real input regardless.
    await page.locator("#inputEmail").fill(email);
    await page.locator("#password").fill(password);

    await page.getByRole("button", { name: "Sign in" }).click();

    await expect(page).toHaveURL(/\/mail\/inbox/);
    await expect(
        page.getByRole("button", { name: `User menu for ${email}` }),
    ).toBeVisible();
}
