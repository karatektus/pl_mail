import { test, expect } from "@playwright/test";

/**
 * Captures the README screenshots against the demo mailbox.
 *
 * Not part of the regression suite — it asserts nothing about behaviour, it
 * just drives the UI to the states worth showing and writes PNGs into
 * docs/screenshots/. It also cannot run on the E2E fixtures: it looks for the
 * demo mailbox by subject and sender, and no seed command in this repo
 * produces that data, so on the test stack it can only fail and overwrite the
 * committed PNGs with pictures of an empty inbox.
 *
 * Hence the opt-in. Point the suite at an app holding the demo mailbox and:
 *
 *   npm run test:e2e:screenshots
 */
const OUT = "docs/screenshots";

test.use({ viewport: { width: 1440, height: 900 } });

test.describe("README screenshots", () => {
    test.skip(
        undefined === process.env.E2E_SCREENSHOTS,
        'Demo mailbox required — run "npm run test:e2e:screenshots".',
    );

    test("inbox", async ({ page }) => {
        await page.goto("/mail/inbox");
        await expect(page.locator("#message-list li").first()).toBeVisible();
        await page.waitForTimeout(600);
        await page.screenshot({ path: `${OUT}/inbox.png` });
    });

    test("thread", async ({ page }) => {
        await page.goto("/mail/inbox");
        await page
            .locator("#message-list li")
            .filter({ hasText: "Bookshelf dimensions" })
            .first()
            .click();
        await expect(page.getByText("alcove is 182cm").first()).toBeVisible();
        await page.waitForTimeout(600);
        await page.screenshot({ path: `${OUT}/thread.png` });
    });

    test("compose", async ({ page }) => {
        await page.goto("/mail/inbox");
        await page.getByRole("link", { name: /compose/i }).first().click();
        await page.waitForTimeout(900);

        // An empty composer shows the chrome but not the point of it.
        // Recipients are Tom Select chips, not a plain input.
        // Pick from the autocomplete rather than pressing Enter — Enter in the
        // recipient field submits the form and actually sends the message.
        const dock = page.locator("#compose_dock");
        const to = dock.locator(".ts-control").first();
        await to.locator("input").fill("priya");
        await page.locator(".ts-dropdown .option").first().click();

        await dock.locator('input[name="compose[subject]"]')
            .fill("Re: Bookshelf dimensions");
        await dock.locator('[data-compose-toolbar-target="editor"]')
            .fill(
                "That clears it nicely — let's go with the 175cm unit. " +
                "I'll order the trim at the same time so it all arrives together. " +
                "No rush on the photos, whenever you get a chance is fine.",
            );
        await page.waitForTimeout(400);
        await page.screenshot({ path: `${OUT}/compose.png` });
    });

    test("inbox dark", async ({ page }) => {
        await page.emulateMedia({ colorScheme: "dark" });
        await page.goto("/mail/inbox");
        await expect(page.locator("#message-list li").first()).toBeVisible();
        await page.waitForTimeout(600);
        await page.screenshot({ path: `${OUT}/inbox-dark.png` });
    });

    test("settings", async ({ page }) => {
        await page.goto("/settings");
        await page.waitForTimeout(900);
        await page.screenshot({ path: `${OUT}/settings.png` });
    });

    test("admin", async ({ page }) => {
        await page.goto("/admin");
        await page.waitForTimeout(1200);
        await page.screenshot({ path: `${OUT}/admin.png` });
    });
});
