import { test, expect } from "./support/test";
import { seed } from "./support/config";
import type { Page } from "@playwright/test";

/**
 * Shift-click an avatar to select every conversation in between (#667).
 *
 * A plain click on an avatar ticks its row and makes it the start; a
 * shift-click selects from that start to the row clicked. The start stays put
 * across shift-clicks, so each one redraws the range from it — longer or
 * shorter — and rows the last range changed but this one leaves out go back
 * to what they were. That is how a file manager does it, and how the owner
 * described it on the issue: first row, shift row 10, shift row 8 → rows 1–8.
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
    await expect(page.locator(ROW).nth(10)).toBeVisible();
}

test.describe("shift-clicking an avatar", () => {
    test("selects from the start, and a later shift-click redraws the range from the same start", async ({ page }) => {
        await openInbox(page);

        await avatar(page, 0).click();
        await avatar(page, 9).click({ modifiers: ["Shift"] });

        await expect.poll(() => ticked(page)).toEqual([0, 1, 2, 3, 4, 5, 6, 7, 8, 9]);
        await expect(page.locator(COUNT)).toHaveText("10");

        // Shorter: row 8 was ticked, and shift-clicking it means "up to here", not "untick".
        await avatar(page, 7).click({ modifiers: ["Shift"] });

        await expect.poll(() => ticked(page)).toEqual([0, 1, 2, 3, 4, 5, 6, 7]);
        await expect(page.locator(COUNT)).toHaveText("8");

        // Longer again, from the same start.
        await avatar(page, 10).click({ modifiers: ["Shift"] });

        await expect.poll(() => ticked(page)).toEqual([0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10]);
    });

    test("shift-clicking the row the range ended on again flips the whole range", async ({ page }) => {
        await openInbox(page);

        await avatar(page, 0).click();
        await avatar(page, 9).click({ modifiers: ["Shift"] });
        await expect.poll(() => ticked(page)).toEqual([0, 1, 2, 3, 4, 5, 6, 7, 8, 9]);

        await avatar(page, 9).click({ modifiers: ["Shift"] });
        await expect.poll(() => ticked(page), "repeating the shift-click did not untick the run").toEqual([]);

        await avatar(page, 9).click({ modifiers: ["Shift"] });
        await expect.poll(() => ticked(page), "a third time did not tick it again").toEqual([0, 1, 2, 3, 4, 5, 6, 7, 8, 9]);

        // A different end still redraws from the same start.
        await avatar(page, 7).click({ modifiers: ["Shift"] });
        await expect.poll(() => ticked(page)).toEqual([0, 1, 2, 3, 4, 5, 6, 7]);
    });

    test("works upwards as well", async ({ page }) => {
        await openInbox(page);

        await avatar(page, 6).click();
        await avatar(page, 2).click({ modifiers: ["Shift"] });

        await expect.poll(() => ticked(page)).toEqual([2, 3, 4, 5, 6]);

        // Across the start to the other side: only the new side is selected.
        await avatar(page, 8).click({ modifiers: ["Shift"] });

        await expect.poll(() => ticked(page)).toEqual([6, 7, 8]);
    });

    test("puts rows a shorter range leaves out back as they were before it", async ({ page }) => {
        await openInbox(page);

        // Row 6 ticked on its own first, then a start at row 1.
        await avatar(page, 6).click();
        await avatar(page, 1).click();

        await avatar(page, 8).click({ modifiers: ["Shift"] });
        await expect.poll(() => ticked(page)).toEqual([1, 2, 3, 4, 5, 6, 7, 8]);

        await avatar(page, 3).click({ modifiers: ["Shift"] });
        await expect.poll(() => ticked(page), "row 6 was ticked before the range and must stay so").toEqual([1, 2, 3, 6]);
    });

    test("from an unticked start, unticks the run", async ({ page }) => {
        await openInbox(page);

        await avatar(page, 1).click();
        await avatar(page, 5).click({ modifiers: ["Shift"] });
        await expect.poll(() => ticked(page)).toEqual([1, 2, 3, 4, 5]);

        // A plain click unticks the first row and makes it the new start.
        await avatar(page, 1).click();
        await avatar(page, 5).click({ modifiers: ["Shift"] });

        await expect.poll(() => ticked(page)).toEqual([]);
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
