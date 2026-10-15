import { test, expect } from "./support/test";
import type { Page } from "@playwright/test";
import { acceptConfirm } from "./support/confirm";

/**
 * Settings → Categories, in a browser.
 *
 * The server side — which keys the order keeps, what switching off releases
 * and queues — is InboxCategoryControllerTest. What only a browser can say is
 * that the page does it: a tab made here is on the list, moving it with the
 * buttons is saved without a submit, a switch takes effect on its own, and a
 * delete asks first.
 */

const NAME = "Shipping e2e";

async function open(page: Page): Promise<void> {
    await page.goto("/settings?section=categories");
    await expect(page.locator("[data-tab-fixed='primary']")).toBeVisible();
}

function keys(page: Page): Promise<string[]> {
    return page.locator("[data-tab-key]").evaluateAll((rows) => rows.map((row) => (row as HTMLElement).dataset.tabKey ?? ""));
}

function rowNamed(page: Page, name: string) {
    return page.locator("[data-tab-key]").filter({ has: page.locator(`input[name="name"][value="${name}"]`) });
}

test.afterEach(async ({ page }) => {
    // Leave the worker's user as it found it: no tab of its own, Forums on.
    await open(page);

    const row = rowNamed(page, NAME);

    if ((await row.count()) > 0) {
        await row.getByRole("button", { name: "Delete category" }).click();
        await acceptConfirm(page);
        await expect(rowNamed(page, NAME)).toHaveCount(0);
    }

    const forumsOn = page.locator("[data-tab-key='forums'] input[name='on'][value='1']");

    if (false === (await forumsOn.isChecked())) {
        await Promise.all([page.waitForURL(/section=categories/), page.locator("[data-tab-key='forums']").getByText("On", { exact: true }).click()]);
    }
});

test("a tab made here can be moved, and the order is kept", async ({ page }) => {
    await open(page);

    await page.getByPlaceholder("e.g. Shipping").fill(NAME);
    await Promise.all([page.waitForURL(/section=categories/), page.getByRole("button", { name: "Add", exact: true }).click()]);

    const row = rowNamed(page, NAME);
    await expect(row).toBeVisible();

    const key = (await row.getAttribute("data-tab-key")) ?? "";
    expect(key).toMatch(/^c\d+$/);
    expect((await keys(page)).at(-1)).toBe(key);

    // Up twice, with the buttons: what a keyboard or a phone uses.
    const saved = page.waitForResponse((response) => response.url().includes("/settings/categories/order"));
    await row.getByRole("button", { name: `Move ${NAME} up` }).click();
    await saved;

    const savedAgain = page.waitForResponse((response) => response.url().includes("/settings/categories/order"));
    await rowNamed(page, NAME).getByRole("button", { name: `Move ${NAME} up` }).click();
    await savedAgain;

    const arranged = await keys(page);
    expect(arranged.indexOf(key)).toBe(arranged.length - 3);

    await open(page);
    expect(await keys(page)).toEqual(arranged);
});

test("a built-in tab is switched off and back on", async ({ page }) => {
    await open(page);

    const forums = page.locator("[data-tab-key='forums']");

    await Promise.all([page.waitForURL(/section=categories/), forums.getByText("Off", { exact: true }).click()]);

    await expect(page.locator("[data-tab-key='forums'] input[name='on'][value='0']")).toBeChecked();
    await expect(page.locator("[data-tab-key='forums']")).toContainText("switched off");

    // And the inbox has no Forums tab to offer.
    await page.goto("/mail/inbox");
    await expect(page.locator('nav a[href*="tab=forums"]')).toHaveCount(0);
});
