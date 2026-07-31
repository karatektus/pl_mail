import { expect, type Locator, type Page } from "@playwright/test";
import { execSync } from "node:child_process";

/**
 * Single source of truth for the seeded test user. Mirrors the
 * APP_DEV_USER_EMAIL / APP_DEV_USER_PASSWORD env vars consumed by the
 * `app:test:seed-user` console command.
 */
export const TEST_USER = {
    email: process.env.APP_DEV_USER_EMAIL ?? "e2e@plmail.test",
    password: process.env.APP_DEV_USER_PASSWORD ?? "e2e-password-change-me",
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
 * Runs `app:test:<task>` for each task, in order, against the test environment.
 */
export function seed(...tasks: string[]): void {
    for (const task of tasks) {
        execSync(`${CONSOLE} app:test:${task}`, {
            stdio: "inherit",
            env: { ...process.env, APP_ENV: "test" },
        });
    }
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
