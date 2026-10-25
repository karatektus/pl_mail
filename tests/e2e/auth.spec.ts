import { test, expect } from "./support/test";
import type { Page } from "@playwright/test";
import { TEST_USER, login, logout, seedUser } from "./support/config";

/**
 * Login is exercised in a fresh, unauthenticated context — separate from the
 * session the worker fixture signs in for every other spec. This is the only
 * place the login form itself is covered.
 */
test.use({ storageState: { cookies: [], origins: [] } });

test.describe("login", () => {
    test("signs in with valid credentials and lands on the inbox", async ({
                                                                              page,
                                                                          }) => {
        await login(page);

        // The login form is gone once we're in the authenticated shell.
        await expect(page.locator("#inputEmail")).toHaveCount(0);
    });

    test("rejects invalid credentials and stays on the login page", async ({
                                                                               page,
                                                                           }) => {
        await page.goto("/login");

        await page.locator("#inputEmail").fill(TEST_USER.email);
        await page.locator("#password").fill("definitely-the-wrong-password");
        await page.getByRole("button", { name: "Sign in" }).click();

        // The authenticator redirects back to /login on failure.
        await expect(page).toHaveURL(/\/login/);
        await expect(page.locator("#password")).toBeVisible();
    });

    test("redirects anonymous users away from a protected page", async ({
                                                                            page,
                                                                        }) => {
        await page.goto("/settings?section=accounts");

        await expect(page).toHaveURL(/\/login/);
    });
});

/**
 * The password is stretched in the browser and never posted — see
 * assets/password_kdf.js and App\Security\PasswordKdf.
 *
 * The first test is also the one place a real browser's arithmetic is checked
 * against PHP's: the account is seeded with a hash PasswordKdf::derive() made,
 * so if the two ever stretched differently it could not sign in.
 */
test.describe("the password never leaves the browser", () => {
    const PASSWORD = "a-password-only-the-browser-sees";
    const CHOSEN = "one-they-picked-for-themselves";

    test("signs a moved account in without posting the password", async ({ page }) => {
        const email = uniqueEmail("moved");

        seedUser({ email, password: PASSWORD, stretched: true });

        const posted = loginPost(page);

        await login(page, email, PASSWORD);

        const body = await posted;

        expect(body.get("password"), "the password was posted").toBeNull();
        expect(body.get("auth_hash")).toMatch(/^[A-Za-z0-9+/]{43}=$/);
    });

    test("moves an account over on its first sign-in, and never posts the password again", async ({ page }) => {
        const email = uniqueEmail("migrating");

        seedUser({ email, password: PASSWORD });

        const first = loginPost(page);

        await login(page, email, PASSWORD);

        const firstBody = await first;

        expect(firstBody.get("password"), "the one migrating sign-in did not carry the password").toBe(PASSWORD);
        expect(firstBody.get("auth_hash")).toMatch(/^[A-Za-z0-9+/]{43}=$/);

        await logout(page);

        const second = loginPost(page);

        await login(page, email, PASSWORD);

        expect((await second).get("password"), "the password was posted after the account had moved").toBeNull();
    });

    test("makes somebody choose their own password when an administrator chose it", async ({ page }) => {
        const email = uniqueEmail("one-time");

        seedUser({ email, password: PASSWORD, changeRequired: true });

        await page.goto("/login");
        await page.locator("#inputEmail").fill(email);
        await page.locator("#password").fill(PASSWORD);
        await page.getByRole("button", { name: "Sign in" }).click();

        await expect(page).toHaveURL(/\/password\/required/);

        // Nothing else is reachable until they have.
        await page.goto("/mail/inbox");
        await expect(page).toHaveURL(/\/password\/required/);

        // Too short: the browser refuses it, since the server never sees it.
        await page.getByLabel("New password", { exact: true }).fill("short");
        await page.getByLabel("Repeat the new password").fill("short");
        await page.getByRole("button", { name: "Save and continue" }).click();
        await expect(page).toHaveURL(/\/password\/required/);

        await page.getByLabel("New password", { exact: true }).fill(CHOSEN);
        await page.getByLabel("Repeat the new password").fill(CHOSEN);
        await page.getByRole("button", { name: "Save and continue" }).click();

        await expect(page).toHaveURL(/\/mail\/inbox/);

        await logout(page);
        await login(page, email, CHOSEN);
    });

    test("changes the password from the settings without posting either one", async ({ page }) => {
        const email = uniqueEmail("changing");

        seedUser({ email, password: PASSWORD, stretched: true });

        await login(page, email, PASSWORD);
        await page.goto("/settings?section=security");

        const card = page.locator("#settings-password");
        const posted = page.waitForRequest((request) => "POST" === request.method() && "/settings/password" === new URL(request.url()).pathname);

        await card.getByLabel("Current password").fill(PASSWORD);
        await card.getByLabel("New password", { exact: true }).fill(CHOSEN);
        await card.getByLabel("Repeat the new password").fill(CHOSEN);
        await card.getByRole("button", { name: "Change password" }).click();

        const body = (await posted).postData() ?? "";

        expect(body, "a password was posted").not.toContain(PASSWORD);
        expect(body, "a password was posted").not.toContain(CHOSEN);
        await expect(page.getByText("Password changed")).toBeVisible();

        await logout(page);
        await login(page, email, CHOSEN);
    });
});

function uniqueEmail(prefix: string): string {
    return `kdf-${prefix}-${Date.now()}-${Math.floor(Math.random() * 1e6)}@plmail.test`;
}

/**
 * The next POST to /login, as the fields it carried.
 */
async function loginPost(page: Page): Promise<URLSearchParams> {
    const request = await page.waitForRequest(
        (candidate) => "POST" === candidate.method() && "/login" === new URL(candidate.url()).pathname,
    );

    return new URLSearchParams(request.postData() ?? "");
}
