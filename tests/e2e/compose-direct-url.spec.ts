import { test, expect } from "./support/test";
import { INBOX_SUBJECTS, mailRow, seed } from "./support/config";

/**
 * A compose URL opened as a page is a page, not a fragment.
 *
 * The window is a fragment by design — no `<html>`, no stylesheet, no
 * JavaScript, because it is meant to land inside a dock frame that already has
 * all three. Reached directly it rendered as raw HTML: system font, native
 * buttons, and every state of the send pill visible at once as one run of text.
 *
 * Reported from a crawl, and the paths that reach it are ordinary: a bookmark,
 * a middle-click into a new tab, the back button after a session expired, and
 * any Turbo fallback without JavaScript.
 *
 * The fix keeps ONE renderer for the window: a request with no frame behind it
 * is redirected to the mailbox, which is asked to open that same URL into its
 * dock. The frame's own fetch then carries the header and gets the fragment.
 */
test.beforeEach(() => {
    seed("seed-mail");
});

test.describe("a compose URL opened directly", () => {
    test("lands in the mailbox with the composer open, fully styled", async ({ page }) => {
        await page.goto("/compose/new");

        // The application, not a fragment.
        await expect(page.locator("#message-list")).toBeVisible();
        await expect(page.locator(`#compose_dock .compose-window`)).toBeVisible({ timeout: 10_000 });

        // Styled: the fragment had no stylesheet at all, which is the thing
        // that made it unmistakable on screen.
        const stylesheets = await page.evaluate(
            () => document.querySelectorAll('link[rel="stylesheet"]').length,
        );
        expect(stylesheets, "the page loaded no stylesheet").toBeGreaterThan(0);

        // And it is a real document rather than a bare turbo-frame.
        await expect(page.locator("html")).toHaveAttribute("lang", /.+/);
    });

    test("a reply opened directly keeps being that reply", async ({ page }) => {
        await page.goto("/mail/inbox");
        await mailRow(page, INBOX_SUBJECTS.read).click();

        const replyHref = await page
            .getByRole("link", { name: "Reply", exact: true })
            .first()
            .getAttribute("href");

        expect(replyHref, "no reply link to follow").not.toBeNull();

        await page.goto(replyHref!);

        const window_ = page.locator(`#compose_dock .compose-window`);
        await expect(window_).toBeVisible({ timeout: 10_000 });

        // The recipient survived the round trip, which is what says the
        // redirect carried the URL rather than dropping it for a blank compose.
        await expect(window_.locator(".ts-control, input[type='text']").first())
            .not.toBeEmpty();
    });

    /**
     * The parameter is a `src` this page fetches, so it may only ever name our
     * own compose paths. Anything else would be an open redirect with the
     * session attached.
     */
    test("the compose parameter cannot point anywhere else", async ({ page }) => {
        for (const hostile of ["https://example.test/evil", "//example.test/evil", "/settings"]) {
            await page.goto(`/mail/inbox?compose=${encodeURIComponent(hostile)}`);

            const src = await page.locator("#compose_dock").getAttribute("src");
            expect(src, `${hostile} was accepted as a frame source`).toBeNull();
        }
    });
});
