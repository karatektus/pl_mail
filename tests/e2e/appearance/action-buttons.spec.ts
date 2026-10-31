import { test, expect, type Page } from "../support/test";
import { INBOX_SUBJECTS, mailRow, seed } from "../support/config";

/**
 * The three tiers of #459, on the page's own preview buttons and after a reload:
 * the choice lives on the user row, not in this tab. Restores Icons on the way
 * out, because the account is shared with the rest of the suite.
 */

const PANEL = '[data-controller="settings--appearance"]';
const SAMPLE = '[data-testid="action-buttons-sample"] .action-btn';

const pick = async (page: Page, value: string) => {
    const radio = page.locator(`${PANEL} input[name="actionButtons"][value="${value}"]`);

    if (true === (await radio.isChecked())) {
        return;
    }

    // The panel debounces its POST: arm the wait before the click, so a reload
    // afterwards cannot beat the write.
    const written = page.waitForResponse(
        (r) => r.url().includes("/settings/appearance") && r.request().method() === "POST",
    );

    await page.locator(`${PANEL} label:has(input[name="actionButtons"][value="${value}"])`).click();
    await expect(radio).toBeChecked();
    await written;
};

test.describe("action button style", () => {
    test.afterEach(async ({ page }) => {
        await page.goto("/settings?section=appearance");
        await pick(page, "icons");
    });

    test("shows icons, both or words, and keeps the choice", async ({ page }) => {
        await page.goto("/settings?section=appearance");
        const button = page.locator(SAMPLE).first();

        await pick(page, "icons");
        await expect(button.locator("i")).toBeVisible();
        await expect(button.locator(".action-btn-label")).toBeHidden();
        await expect(button).toHaveCSS("width", "32px");

        await pick(page, "both");
        await expect(button.locator("i")).toBeVisible();
        await expect(button.locator(".action-btn-label")).toBeVisible();

        await pick(page, "text");
        await expect(button.locator("i")).toBeHidden();
        await expect(button.locator(".action-btn-label")).toBeVisible();

        await page.reload();
        await expect(page.locator(`${PANEL} input[name="actionButtons"][value="text"]`)).toBeChecked();
        await expect(page.locator(SAMPLE).first().locator("i")).toBeHidden();
    });

    test("puts the words on the conversation's own toolbar, and it still fits a phone", async ({ page }) => {
        seed("seed-mail");
        await page.goto("/settings?section=appearance");
        await pick(page, "text");

        await page.setViewportSize({ width: 375, height: 700 });
        await page.goto("/");
        await mailRow(page, INBOX_SUBJECTS.read).click();

        const archive = page.locator('[data-action="click->mail--message-actions#archive"]').first();
        await expect(archive.locator(".action-btn-label")).toBeVisible();
        await expect(archive.locator("i")).toBeHidden();

        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
        expect(overflow).toBeLessThanOrEqual(0);
    });
});
