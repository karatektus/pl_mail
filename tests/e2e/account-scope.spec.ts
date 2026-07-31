import { test, expect } from "./support/test";
import { seed } from "./support/config";

/**
 * Per-account navigation in the sidebar.
 *
 * Labels are user-scoped and bind to several accounts at once, so a label on
 * its own means "across the whole mailbox". Under an account it is read as
 * "this account's" — and it used to link to the unscoped view, listing every
 * account's mail under a heading that said otherwise.
 */
test.beforeEach(() => {
    seed("seed-mail");
});

test.describe("account scoping", () => {
    const accountLink = (page: import("@playwright/test").Page) =>
        page.locator('#sidebar a[href^="/mail/account/"]').first();

    test("clicking an account shows that account rather than expanding it", async ({ page }) => {
        await page.goto("/mail/inbox");

        const link = accountLink(page);
        await expect(link).toBeVisible();

        const href = await link.getAttribute("href");
        await link.click();

        await page.waitForURL(new RegExp(href!.replace(/[/]/g, "\\/")));
        await expect(page.locator("h2").first()).toHaveText("E2E Mailbox");
    });

    /**
     * The chevron kept the old job. Clicking the row used to be the only thing
     * an account could do, which left no way to ask for the account itself.
     */
    test("the chevron still expands the account's labels", async ({ page }) => {
        await page.goto("/mail/inbox");

        await page.locator('#sidebar button[aria-label="Toggle account folders"]').first().click();

        const labels = page.locator('#sidebar turbo-frame[id^="account-folders-"] a');
        await expect(labels.first()).toBeVisible();
    });

    test("labels under an account are scoped to it", async ({ page }) => {
        await page.goto("/mail/inbox");
        await page.locator('#sidebar button[aria-label="Toggle account folders"]').first().click();

        const scoped = page.locator('#sidebar turbo-frame a[href*="/mail/label/"]');
        await expect(scoped.first()).toBeVisible();

        // Every one of them, not just the first: a single unscoped entry is the
        // bug all over again for whichever label it happens to be.
        const hrefs = await scoped.evaluateAll((links) =>
            links.map((link) => link.getAttribute("href") ?? ""),
        );

        expect(hrefs.length).toBeGreaterThan(0);
        expect(hrefs.every((href) => href.includes("account="))).toBe(true);
    });

    test("an account-scoped label view says so, and offers the way back out", async ({ page }) => {
        await page.goto("/mail/inbox");
        await page.locator('#sidebar button[aria-label="Toggle account folders"]').first().click();

        const scoped = page.locator('#sidebar turbo-frame a[href*="/mail/label/"]').first();
        await expect(scoped).toBeVisible();
        await scoped.click();

        await page.waitForURL(/\/mail\/label\/\d+\?account=/);

        const header = page.locator("h2").first().locator("..");
        await expect(header).toContainText("E2E Mailbox");

        // Back to the label across every account.
        const allAccounts = header.getByRole("link");
        await expect(allAccounts).toBeVisible();
        await allAccounts.click();

        await page.waitForURL(/\/mail\/label\/\d+$/);
    });

    /**
     * `?account=` arrives off the query string, so it is checked rather than
     * trusted — otherwise appending someone else's account id would scope a
     * label you own to threads you do not.
     */
    test("refuses an account the user does not own", async ({ page }) => {
        await page.goto("/mail/inbox");
        await page.locator('#sidebar button[aria-label="Toggle account folders"]').first().click();

        const scoped = page.locator('#sidebar turbo-frame a[href*="/mail/label/"]').first();
        await expect(scoped).toBeVisible();

        const href = (await scoped.getAttribute("href"))!;
        const foreign = href.replace(/account=\d+/, "account=999999");

        const response = await page.request.get(foreign);
        expect(response.status()).toBe(403);
    });
});

test.describe("archive", () => {
    /**
     * The Archive route existed and nothing linked to it: the sidebar entry was
     * gated on the user having switched the Archive label visible in settings,
     * and it is created hidden.
     */
    test("is reachable from the sidebar without touching label settings", async ({ page }) => {
        await page.goto("/mail/inbox");

        const more = page
            .locator("#sidebar details > summary")
            .filter({ hasText: /^\s*(More|Mehr)\s*$/ });
        await expect(more).toBeVisible();

        // Collapsed by default — it is not somewhere you go often.
        await expect(page.locator('#sidebar a[href="/mail/archive"]')).toBeHidden();

        await more.click();

        const archive = page.locator('#sidebar a[href="/mail/archive"]');
        await expect(archive).toBeVisible();
        await archive.click();

        await page.waitForURL(/\/mail\/archive/);
    });
});
