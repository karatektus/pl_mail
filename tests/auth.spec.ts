import { test, expect } from "@playwright/test";
import { TEST_USER, login } from "./support/config";

/**
 * Login is exercised in a fresh, unauthenticated context — separate from the
 * shared storage state produced by auth.setup.ts.
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
