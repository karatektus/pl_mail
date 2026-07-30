import { test, expect } from "@playwright/test";
import { login, seed } from "./support/config";

/**
 * Guards the whole app against IS_AUTHENTICATED_FULLY creeping back onto a
 * route: that voter rejects a session restored from a "keep me logged in"
 * cookie, so returning users get bounced off features that work fine on a
 * freshly-typed password.
 *
 * The rest of the suite cannot catch this — auth.setup.ts signs in with real
 * credentials, and such a session satisfies FULLY. So this spec opts out of
 * the shared storage state, logs in for itself, then throws the session cookie
 * away and keeps only REMEMBERME, which is exactly the returning-user state.
 */
test.use({ storageState: { cookies: [], origins: [] } });

test.beforeEach(() => {
    seed("seed-mail", "seed-label");
});

/**
 * Drops every cookie except REMEMBERME, leaving the browser in the state a
 * user returns in after their session has expired.
 */
async function expireSession(context: import("@playwright/test").BrowserContext) {
    const remembered = (await context.cookies()).filter(
        (cookie) => cookie.name === "REMEMBERME",
    );

    expect(
        remembered,
        'login did not set a REMEMBERME cookie — is "keep me logged in" still checked by default?',
    ).toHaveLength(1);

    await context.clearCookies();
    await context.addCookies(remembered);
}

test("a remember-me session can still use the app", async ({ page }) => {
    await login(page);
    await expireSession(page.context());

    await page.goto("/mail/inbox");

    // Still signed in, on a cookie alone.
    await expect(
        page.getByRole("button", { name: /User menu for / }),
    ).toBeVisible();

    // LabelController — the regression that prompted this spec. The modal
    // renders the form only if the route authorised the request.
    await page
        .locator("#sidebar")
        .getByRole("button", { name: "Create label" })
        .click();

    const modal = page.locator("#modal-backdrop");
    await expect(modal).toBeVisible();
    await expect(modal.getByLabel("Name")).toBeVisible();
    await modal.getByRole("button", { name: "Cancel" }).click();

    // ThreadStatusController — every list action routes through it.
    const row = page
        .locator('#message-list li[data-controller="mail--message-row"]')
        .first();
    await row.locator('input[type="checkbox"]').check();
    await expect(
        page.locator('[data-mail--list-toolbar-target="actions"]'),
    ).toBeVisible();

    // SearchController.
    await page.locator("#sidebar-search, input[type='search']").first().fill("E2E");
    await page.keyboard.press("Enter");
    await expect(page).toHaveURL(/\/search/);
});
