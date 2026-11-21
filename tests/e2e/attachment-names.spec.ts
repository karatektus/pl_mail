import { test, expect, type Page } from "./support/test";
import { consoleCommand, seed } from "./support/config";

/**
 * An attachment whose mailer named it only in Content-Type (The Arch
 * Company's statements) was stored under webklex's eight-character hash: the
 * chip read "57d5c7cb", the download had no extension, and the PDF had no
 * preview. The seed stores it exactly that way, with its original beside it;
 * the backfill puts the name back.
 *
 * The first test is the positive control for the seed: without it, "the chip
 * shows the real name" would pass just as happily if the seed had never
 * reproduced the bug at all.
 */
const SUBJECT = "E2E Account Statement";
const NAME = "TNNTST02_10561121.PDF";
const PAGES = '[data-mail--pdf-viewer-target="stack"] canvas';

test.beforeEach(() => {
    seed("seed-mail", "seed-statement-attachment");
});

async function openStatement(page: Page) {
    await page.goto("/mail/inbox");
    await page.locator("#message-list li").filter({ hasText: SUBJECT }).first().click();
}

test("before the repair the attachment is shown under its hash, with no preview", async ({ page }) => {
    await openStatement(page);

    await expect(page.getByRole("link", { name: /\b[0-9a-f]{8}\b/ })).toBeVisible();
    await expect(page.getByRole("button", { name: new RegExp(NAME.replace(".", "\\.")) })).toHaveCount(0);
});

test("after the repair it has its real name, previews as a PDF and downloads under that name", async ({ page }) => {
    consoleCommand("app:backfill attachment-content-type-names");

    await openStatement(page);

    const chip = page.getByRole("button", { name: new RegExp(NAME.replace(".", "\\.")) });
    await expect(chip).toBeVisible();

    await chip.click();
    await expect(page.locator(PAGES)).toHaveCount(2, { timeout: 20000 });
});

test("the download carries the real name, so the operating system can open it", async ({ page }) => {
    consoleCommand("app:backfill attachment-content-type-names");

    await openStatement(page);

    const href = await page
        .locator('a[href*="/mail/attachment/"][href*="download"]')
        .first()
        .getAttribute("href");
    expect(href).not.toBeNull();

    const response = await page.request.get(href!);
    expect(response.status()).toBe(200);
    expect(response.headers()["content-disposition"]).toContain(NAME);
});
