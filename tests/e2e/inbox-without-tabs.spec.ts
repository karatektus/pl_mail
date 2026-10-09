import { test, expect } from "./support/test";
import type { Page } from "@playwright/test";
import { ajaxPost, seed } from "./support/config";

/**
 * The inbox as one list, for somebody who does not want it in tabs (#29).
 *
 * The server-side half — which query runs, what "select all in this view"
 * resolves to — is asserted in InboxWithoutTabsTest. What only a browser can
 * say is the part a person sees: the control is where the card says it is, one
 * click is the whole of it, and the strip is gone and the mail it was hiding
 * is on the page.
 */
const STRIP = 'nav a[href*="tab="]';

async function setTabs(page: Page, option: "Tabs" | "One list"): Promise<void> {
    await page.goto("/settings?section=general");

    const group = page.getByRole("radiogroup", { name: "Inbox tabs" });

    // The visible segment, which is the radio's label: the input is sr-only.
    // The control submits on change and comes back to this page.
    if (false === (await group.getByRole("radio", { name: option, exact: true }).isChecked())) {
        await Promise.all([
            page.waitForURL(/section=general/),
            group.getByText(option, { exact: true }).click(),
        ]);
    }

    await expect(
        page.getByRole("radiogroup", { name: "Inbox tabs" }).getByRole("radio", { name: option, exact: true }),
    ).toBeChecked();
}

test.beforeEach(() => {
    seed("seed-mail");
});

// The setting is this worker's user's, and every later spec in the worker
// inherits it: an inbox left without tabs would fail whatever looks for one.
test.afterEach(async ({ page }) => {
    await setTabs(page, "Tabs");
});

test("switching the tabs off shows the whole inbox in one list", async ({ page }) => {
    await setTabs(page, "Tabs");
    await page.goto("/mail/inbox");

    const rows = page.locator("#message-list li[data-mail--message-row-id-value]");
    await expect(rows.first()).toBeVisible();

    // The seed files everything under Primary, and one category is no strip at
    // all. One conversation is re-filed the way a drop on a tab does it, so
    // there is a second tab and a row that Primary does not show.
    const moved = Number(await rows.first().getAttribute("data-mail--message-row-id-value"));
    expect((await ajaxPost(page, "/status/bulk/category", { ids: [moved], category: "promotions" })).ok()).toBe(true);

    await page.goto("/mail/inbox");
    await expect(rows.first()).toBeVisible();
    await expect(page.locator(STRIP).first()).toBeVisible();

    const inPrimary = await rows.count();

    await setTabs(page, "One list");
    await page.goto("/mail/inbox");

    await expect(rows.first()).toBeVisible();
    await expect(page.locator(STRIP)).toHaveCount(0);
    expect(await rows.count(), "the mail from the other tabs is not in the list").toBeGreaterThan(inPrimary);

    // A link from before the switch is the inbox too, not an empty tab.
    await page.goto("/mail/inbox?tab=promotions");
    await expect(rows).toHaveCount(await rows.count());
    await expect(page.locator(STRIP)).toHaveCount(0);
});
