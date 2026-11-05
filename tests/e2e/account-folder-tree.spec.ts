import { test, expect, type Page } from "./support/test";
import { seed } from "./support/config";

/**
 * The folders under an account, as the server nests them (#221, Adrian's
 * report on v0.4.1): a folder inside another is drawn inside it, and a folder
 * with folders in it collapses on its own and stays so after a reload.
 *
 * Seeded as `E2E Parent` and `E2E Parent/E2E Inside`, names no other spec
 * uses, so the folder rows other specs line up and click are left alone.
 * AccountFolderTreeTest covers the paths themselves (INBOX, Dovecot's `.`).
 */

test.beforeEach(() => seed("seed-mail", "seed-label --path='E2E Parent'", "seed-label --path='E2E Parent/E2E Inside'"));

/** Leave the parent open: the preference outlives the test. */
test.afterEach(async ({ page }) => {
    const key = await parentTree(page).getAttribute("data-collapse-key", { timeout: 2000 }).catch(() => null);

    if (null === key) {
        return;
    }

    const token = await page.locator("meta[name='csrf-token']").getAttribute("content", { timeout: 2000 }).catch(() => null);

    if (null !== token) {
        await page.request.post("/mail/sidebar/section-collapsed", {
            headers: { "Content-Type": "application/json", "X-CSRF-Token": token },
            data: { key, collapsed: false },
        });
    }
});

const link = (page: Page, name: RegExp) => page.locator("a[data-ui--sidebar-target='link']", { hasText: name });

const parentLink = (page: Page) => page.locator("#sidebar").locator(link(page, /^E2E Parent$/));

const insideLink = (page: Page) => page.locator("#sidebar").locator(link(page, /^E2E Inside$/));

/** The parent's <details>, found by the link in its own summary. */
const parentTree = (page: Page) =>
    page.locator("#sidebar details[data-collapse-key^='folder:']")
        .filter({ has: page.locator("> summary").locator(link(page, /^E2E Parent$/)) })
        .first();

async function showFolders(page: Page): Promise<void> {
    await page.goto("/mail/inbox");

    if (await parentLink(page).filter({ visible: true }).count() === 0) {
        await page.getByRole("button", { name: "Show or hide folders in E2E Mailbox" }).first().click();
    }

    await expect(parentLink(page).first()).toBeVisible();
}

test("a folder inside another is drawn inside it, and the parent collapses on its own", async ({ page }) => {
    await showFolders(page);

    const parent = parentTree(page);

    await expect(parent.locator("> summary")).toContainText("E2E Parent");
    await expect(parent.locator("> .nav-rows").locator(link(page, /^E2E Inside$/))).toHaveCount(1);

    const parentLeft = await parentLink(page).first().evaluate((a) => a.getBoundingClientRect().left);
    const insideLeft = await insideLink(page).first().evaluate((a) => a.getBoundingClientRect().left);
    expect(insideLeft, "the inner folder is indented under its parent").toBeGreaterThan(parentLeft + 8);

    const persisted = page.waitForResponse((r) => r.url().includes("/mail/sidebar/section-collapsed") && r.status() === 200);
    await parent.locator("> summary > i.fa-chevron-right").click();
    await persisted;

    await expect(insideLink(page).first()).toBeHidden();
    await expect(parentLink(page).first()).toBeVisible();

    await page.reload();

    await expect(parentLink(page).first()).toBeVisible();
    await expect(insideLink(page).first()).toBeHidden();
});
