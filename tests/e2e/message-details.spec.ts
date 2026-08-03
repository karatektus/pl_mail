import { test, expect, type Page } from "./support/test";
import { seed } from "./support/config";

/**
 * The "to me ▾" popover on an open message.
 *
 * It is the only place the app explains itself: who a message was really
 * addressed to, who signed it, and — since this session — which tab it landed
 * in and what put it there. That last one is recomputed from the stored
 * headers on every open rather than read off the row, so the thing worth
 * pinning is that it renders at all and says something about *this* message.
 *
 * Also the one popover that must not toggle the message it lives in: it sits
 * inside the collapsed-message click target, so a click that bubbles collapses
 * the thing the reader just opened.
 */

const DETAILS = '[data-controller="mail--message-details"]';
const PANEL = '[data-mail--message-details-target="panel"]';

test.beforeAll(() => {
    seed("seed-mail");
});

/** Opens the seeded thread and returns its message header row. */
async function openThread(page: Page): Promise<void> {
    await page.goto("/mail/inbox");
    await page.locator("#message-list li").filter({ hasText: "E2E Read Me" }).first().click();
    await expect(page.locator(DETAILS).first()).toBeVisible();
}

test.describe("message details", () => {
    test("opens on its trigger and closes again", async ({ page }) => {
        await openThread(page);

        const details = page.locator(DETAILS).first();

        await expect(details.locator(PANEL)).toBeHidden();

        await details.getByRole("button").first().click();
        await expect(details.locator(PANEL)).toBeVisible();

        await details.getByRole("button").first().click();
        await expect(details.locator(PANEL)).toBeHidden();
    });

    /**
     * The popover is inside the message's own click target. Opening it must
     * not collapse the message underneath — which is what happens if the
     * click is allowed to bubble.
     */
    test("opening it does not collapse the message it belongs to", async ({ page }) => {
        await openThread(page);

        // The recipients row is inside the message's expanded header, so it is
        // present exactly while the message is open — a collapse would take it
        // away along with the body.
        const details = page.locator(DETAILS).first();
        const expanded = page.locator('[data-mail--thread-message-target="recipients"]').first();

        await expect(expanded).toBeVisible();

        await details.getByRole("button").first().click();

        await expect(details.locator(PANEL)).toBeVisible();
        await expect(expanded).toBeVisible();
    });

    test("shows who it was from and to", async ({ page }) => {
        await openThread(page);

        const details = page.locator(DETAILS).first();
        await details.getByRole("button").first().click();

        const panel = details.locator(PANEL);

        await expect(panel).toContainText("sender@e2e.test");
        await expect(panel).toContainText(/from/i);
    });

    /**
     * Why this message is in the tab it is in. Recomputed from the same class
     * that filed it, so a message with no bulk headers has to explain itself
     * as Primary rather than as nothing.
     */
    test("says which category it landed in and what decided that", async ({ page }) => {
        await openThread(page);

        const details = page.locator(DETAILS).first();
        await details.getByRole("button").first().click();

        const panel = details.locator(PANEL);

        await expect(panel).toContainText(/category/i);
        // The seeded mail carries no list or bulk headers, so it is Primary by
        // the absence of every signal — which the reason has to say, not leave
        // blank.
        await expect(panel).toContainText(/Primary/i);
        await expect(panel).toContainText(/nothing marked it/i);
    });

    /** The raw headers are behind a second click, so the panel stays readable. */
    test("the full headers are available but not in the way", async ({ page }) => {
        await openThread(page);

        const details = page.locator(DETAILS).first();
        await details.getByRole("button").first().click();

        const all = details.locator('[data-mail--message-details-target="allHeaders"]');
        await expect(all).toBeHidden();

        await details.getByRole("button", { name: /show all headers/i }).click();
        await expect(all).toBeVisible();
    });
});
