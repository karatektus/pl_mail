import { expect, type Locator, type Page } from "@playwright/test";

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
 * The inbox row (`<li id="thread_{id}">`) carrying the given subject.
 */
export function mailRow(page: Page, subject: string): Locator {
    return page
        .locator('#message-list li[data-controller="message-row"]')
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
