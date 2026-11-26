import { test, expect } from "./support/test";
import { mailRow, seed } from "./support/config";
import { settled } from "./support/motion";

/**
 * A table in a mail keeps whole words in its columns on a phone (#747).
 *
 * The reading frame's sheet used `word-break: break-word`, which tells the
 * browser it may break between any two letters when it works out how narrow a
 * column can go. A Bitbucket commit mail has fixed-width Author, Commit and
 * Date columns that already fill a phone's sheet, so the one free column,
 * Message, was given a letter's width and its heading read M-e-s-s-a-g-e down
 * the screen. With `overflow-wrap: break-word` a column is never narrower than
 * its longest word, and the table scrolls sideways instead, as in Gmail.
 *
 * Measured, not inspected: the claim is about the layout the browser made, so
 * what is checked is how many lines the column's heading takes.
 */

/** Kept in sync with App\Command\Test\SeedRenderingCommand::SUBJECT_TABLE. */
const SUBJECT = "E2E Commit Table";

test.beforeEach(() => {
    seed("seed-mail", "seed-rendering");
});

test("a table column keeps whole words at phone width", async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto("/mail/inbox");
    await settled(page);
    await mailRow(page, SUBJECT).click();
    await page.waitForURL(/\/mail\/thread\/\d+/);

    const frame = page.locator("[data-mail--message-frame-target='frame']").first().contentFrame();
    const heading = frame.locator("th", { hasText: "Message" });

    await expect(heading).toBeVisible();

    // A range over wrapped text has one client rect per line it sits on, so
    // one rect means the word was not split between letters. Before the fix
    // there were seven.
    const lines = await heading.evaluate((th) => {
        const range = document.createRange();
        range.selectNodeContents(th);

        return range.getClientRects().length;
    });

    expect(lines).toBe(1);
});
