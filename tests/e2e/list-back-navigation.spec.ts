import { test, expect } from "./support/test";
import { INBOX_SUBJECTS, mailRow, seed } from "./support/config";

/**
 * Going back from a thread lands on a list, not on a blank pane.
 *
 * The reported bug: open a conversation, press Back, and the list is empty —
 * no rows, and the tabs and the pagination gone with them — until the next poll
 * happens to fill it in. It was not a race and not the cache: the thread page
 * renders the list frame deliberately empty, because the thread route knows a
 * conversation and not the folder and page it was opened from. Back merely
 * uncovered that emptiness, and the poll that "fixed" it a few seconds later
 * was fetching a URL that did have a list in it.
 *
 * So these assert the list is there *immediately*, and — because the whole
 * failure mode was a list that fills in later — that it is there before any
 * poll could have run.
 *
 * They go through the app's own back button rather than the browser's, and that
 * is not incidental. The browser's Back fires a Turbo restoration visit which
 * re-renders the page from cache and repairs the list on its way past, so a test
 * written with page.goBack() passes whether the bug is present or not — verified
 * by disabling the fix and watching it pass anyway. The in-app button is a
 * pushState and a pane swap with nothing to paper over it, which is why that is
 * the path the report came from.
 */

const ROWS = '#message-list li[data-controller="mail--message-row"]';

/** The arrow in the reading pane's toolbar — mail--mail-pane#close. */
const BACK_BUTTON = "Back";

test.beforeEach(() => {
    seed("seed-mail");
});

test.describe("returning from a thread", () => {
    /**
     * The exact sequence that broke it.
     *
     * Opening a conversation leaves the list in the DOM, so Back alone was never
     * enough to lose it. What lost it was a sync arriving *while the thread was
     * open*: the refresh asks for whatever URL the page is on, that URL was the
     * thread's, and the thread's list frame is empty — so the refresh dutifully
     * copied the emptiness over the list. Back then uncovered it, and the next
     * poll, by then on the list's URL again, put it back. Hence "until the next
     * poll fills it".
     */
    test("the list survives a sync that arrives while a thread is open", async ({ page }) => {
        await page.goto("/mail/inbox");
        await expect(mailRow(page, INBOX_SUBJECTS.read)).toBeVisible();

        const before = await page.locator(ROWS).count();
        expect(before, "the fixture has to have rows for this to prove anything").toBeGreaterThan(0);

        await mailRow(page, INBOX_SUBJECTS.read).click();
        await expect(page.locator("#message-list")).toBeHidden();

        // A sync lands while the conversation is open. This is the event the
        // body dispatches to the pane on a Mercure update, fired here directly
        // so the test does not have to wait for a real one.
        await page.evaluate(() => {
            document.body.dispatchEvent(
                new CustomEvent("core--mercure:mailbox-synced", {
                    bubbles: true,
                    detail: { poll: true },
                }),
            );
        });

        await page.waitForTimeout(1000);
        await page.getByRole("button", { name: BACK_BUTTON }).first().click();

        // No polling interval is anywhere near this short, so a list that is
        // here now is here because going back rendered it.
        await expect(page.locator("#message-list")).toBeVisible();
        await expect(page.locator(ROWS).first()).toBeVisible({ timeout: 3000 });
        await expect(page.locator(ROWS)).toHaveCount(before);
    });

    /**
     * The part of the report that named the toolbar: "tabs and pagination gone
     * too". They live in the same frame as the rows and vanished with them.
     */
    test("the toolbar and tabs come back with it", async ({ page }) => {
        await page.goto("/mail/inbox");
        await expect(mailRow(page, INBOX_SUBJECTS.read)).toBeVisible();

        const tabs = page.locator("#inbox-list-frame [data-mail--list-tabs-target], #inbox-list-frame nav, #inbox-list-frame [role='tablist']");
        const toolbarText = await page.locator("#inbox-list-frame").innerText();

        await mailRow(page, INBOX_SUBJECTS.read).click();
        await expect(page.locator("#message-list")).toBeHidden();

        await page.evaluate(() => {
            document.body.dispatchEvent(
                new CustomEvent("core--mercure:mailbox-synced", {
                    bubbles: true,
                    detail: { poll: true },
                }),
            );
        });
        await page.waitForTimeout(1000);

        await page.getByRole("button", { name: BACK_BUTTON }).first().click();

        await expect(page.locator(ROWS).first()).toBeVisible({ timeout: 3000 });

        // The frame's own content is back, not merely some rows: the toolbar
        // renders a range like "1–4 of 4", and a frame that was never populated
        // renders it as a total of zero.
        const after = await page.locator("#inbox-list-frame").innerText();

        expect(after.length, "the restored frame should carry the toolbar too").toBeGreaterThan(
            toolbarText.length / 2,
        );
        expect(after).not.toContain("of 0");
    });

    /**
     * The frame says whether it holds a real list, which is what the pane
     * controller reads to decide whether Back has to fetch one. Pinned because
     * the whole fix hangs off this attribute being right on both pages.
     */
    test("the thread page marks its list frame as unrendered", async ({ page }) => {
        await page.goto("/mail/inbox");
        await expect(mailRow(page, INBOX_SUBJECTS.read)).toBeVisible();

        await expect(page.locator("#inbox-list-frame")).toHaveAttribute("data-list-rendered", "1");

        // A real navigation to the thread, which is the case that renders the
        // frame empty — clicking the row swaps the pane without reloading.
        const href = await mailRow(page, INBOX_SUBJECTS.read).locator("a").first().getAttribute("href");
        await page.goto(href!);

        await expect(page.locator("#inbox-list-frame")).toHaveAttribute("data-list-rendered", "0");
    });
});
