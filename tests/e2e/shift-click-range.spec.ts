import { test, expect } from "./support/test";
import { seed } from "./support/config";
import type { Page } from "@playwright/test";

/**
 * Shift-click an avatar to select every conversation in between (#667).
 *
 * A plain click on an avatar marks where a range starts; a shift-click on
 * another gives both rows and every row between them the state of the one
 * shift-clicked. The same shift-click again therefore unticks the run — which
 * is the whole of the "undo", so it is held here as firmly as the ticking.
 *
 * The base seed has four inbox threads; thirty bulk messages add about ten
 * more, enough for a run with untouched rows on both sides of it. The bulk
 * mailbox is cleared before and after, as the other bulk specs do, so this
 * worker's next spec does not inherit it.
 */
test.beforeEach(() => {
    seed("seed-bulk --clear", "seed-bulk --messages=30");
});

test.afterAll(() => {
    seed("seed-bulk --clear");
});

const ROW = "#message-list li:not([hidden])";
const COUNT = '[data-mail--list-toolbar-target="selectionCount"]';

/** The avatar is the checkbox's label and what a person clicks; the input is sr-only. */
const avatar = (page: Page, index: number) => page.locator(`${ROW} label:has([data-thread-select])`).nth(index);

/** Which rows on screen are ticked, by position. */
const ticked = (page: Page) =>
    page.locator(`${ROW} [data-thread-select]`).evaluateAll((boxes) =>
        boxes.flatMap((box, index) => ((box as HTMLInputElement).checked ? [index] : [])),
    );

async function openInbox(page: Page) {
    await page.goto("/mail/inbox");
    await expect(page.locator(ROW).nth(6)).toBeVisible();
}

test.describe("shift-clicking an avatar", () => {
    test("ticks the run down to it, and unticks it when repeated", async ({ page }) => {
        await openInbox(page);

        await avatar(page, 1).click();
        await avatar(page, 4).click({ modifiers: ["Shift"] });

        await expect.poll(() => ticked(page)).toEqual([1, 2, 3, 4]);
        await expect(page.locator(COUNT)).toHaveText("4");

        // The mark stays on the first row, so the same gesture is its own undo.
        await avatar(page, 4).click({ modifiers: ["Shift"] });

        await expect.poll(() => ticked(page)).toEqual([]);
    });

    test("works upwards as well", async ({ page }) => {
        await openInbox(page);

        await avatar(page, 5).click();
        await avatar(page, 2).click({ modifiers: ["Shift"] });

        await expect.poll(() => ticked(page)).toEqual([2, 3, 4, 5]);
    });

    test("with no row clicked before, ticks only its own row", async ({ page }) => {
        await openInbox(page);

        await avatar(page, 3).click({ modifiers: ["Shift"] });

        await expect.poll(() => ticked(page)).toEqual([3]);
        await expect(page, "a shift-click on the avatar opened the mail").toHaveURL(/\/mail\/inbox/);
    });

    test("does not drag the page's text selection over the rows between", async ({ page }) => {
        await openInbox(page);

        await avatar(page, 0).click();
        await avatar(page, 5).click({ modifiers: ["Shift"] });

        expect(await page.evaluate(() => String(window.getSelection() ?? ""))).toBe("");
    });
});
