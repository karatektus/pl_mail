import { test, expect } from "./support/test";
import { TEST_USER, login, seed } from "./support/config";

/**
 * Signing out, and being signed out — the two halves of the same story.
 *
 * Both were reported together and they share a cause: the application kept
 * treating a dead session as an ordinary navigation. Turbo carried the logout,
 * so the shell survived it; and Symfony remembered the last unauthenticated URL
 * the browser asked for, whatever it happened to be, as the place to go after
 * the next successful login.
 */
test.beforeEach(() => {
    seed("seed-mail");
});

test.describe("the end of a session", () => {
    /**
     * Logging out through Turbo leaves the application running.
     *
     * The shell stays mounted, its controllers keep polling with a cookie that
     * no longer exists, the Mercure subscription stays open, and Turbo's page
     * cache can put a signed-in snapshot back on screen with the back button.
     * A full page load is the only way to be sure nothing survives a logout.
     */
    test("logout is a full page load, not a Turbo visit", async ({ page }) => {
        await page.goto("/mail/inbox");

        await expect(page.locator('a[href*="logout"]').first())
            .toHaveAttribute("data-turbo", "false");
    });

    /**
     * A session that ends underneath an open page does not become a login form
     * wearing the sidebar.
     *
     * Turbo follows the redirect and swaps /login into the shell, which looks
     * like the app has fallen apart and leaves every controller on the page
     * mounted and failing. The bar says what happened instead.
     */
    test("a session ending underneath the page raises the bar, not a login form", async ({ page }) => {
        await page.goto("/mail/inbox");
        await expect(page.locator("#session-expired")).toBeHidden();

        // Exactly what a logout in another tab does to this one.
        await page.context().clearCookies();

        await page.getByRole("link", { name: "Compose" }).first().click();

        const bar = page.locator("#session-expired");
        await expect(bar).toBeVisible({ timeout: 10_000 });
        await expect(bar).toContainText("signed out");

        // The shell is still the shell: the sidebar did not get replaced by a
        // password field.
        await expect(page.locator("#message-list")).toBeVisible();
        await expect(page.locator("#password")).toHaveCount(0);
    });
});

test.describe("where a login sends you", () => {
    test.use({ storageState: { cookies: [], origins: [] } });

    /**
     * A subresource is not a destination.
     *
     * Symfony saves the target path for any request that is
     * `isMethodSafe() && !isXmlHttpRequest()`, and an `<img src="…">` is both.
     * So the last unauthenticated thing the browser happened to fetch became
     * the place the next login went — reported as landing on your own profile
     * picture after signing in, because the page re-requested the avatar as the
     * session ended.
     *
     * Whichever subresource lost the race decided the destination, which is why
     * it looked intermittent rather than broken.
     */
    test("an image fetched while signed out does not become the login destination", async ({ page }) => {
        // A protected page, requested the way a browser requests a picture:
        // page.request shares this context's cookie jar, so this is the same
        // session the login below will use.
        await page.request.get("/settings?section=appearance", {
            headers: {
                Accept: "image/avif,image/webp,image/apng,image/*,*/*;q=0.8",
                "Sec-Fetch-Dest": "image",
                "Sec-Fetch-Mode": "no-cors",
            },
        });

        await login(page, TEST_USER.email, TEST_USER.password);

        await expect(page).not.toHaveURL(/section=appearance/);
        await expect(page.locator("#message-list")).toBeVisible({ timeout: 10_000 });
    });

    /**
     * The other half, so the fix cannot be "never remember anything".
     *
     * Being returned to the page you asked for is the whole point of the
     * feature, and a Turbo visit is a fetch — it reports `Sec-Fetch-Dest:
     * empty` and is recognised by its Accept header instead.
     */
    test("a page asked for while signed out is still where the login lands", async ({ page }) => {
        await page.goto("/settings?section=appearance");
        await expect(page).toHaveURL(/\/login/);

        await page.locator("#inputEmail").fill(TEST_USER.email);
        await page.locator("#password").fill(TEST_USER.password);
        await page.getByRole("button", { name: "Sign in" }).click();

        await expect(page).toHaveURL(/section=appearance/, { timeout: 10_000 });
    });
});
