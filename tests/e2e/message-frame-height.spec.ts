import { test, expect } from "./support/test";
import type { Page } from "@playwright/test";
import { mailRow, seed } from "./support/config";

/**
 * The sandboxed message frame must be as tall as its content on a FULL page
 * load, not only after a soft (Turbo) navigation.
 *
 * The frame's srcdoc is parsed synchronously and posts its measured height at
 * once; on a full load the app's module script is deferred, so the parent's
 * controller connects a beat later and that first report lands with no
 * listener. The frame dedupes its own reports, so the height was never re-sent
 * and the frame stayed at its 80px minimum with the whole message scrolling
 * inside it — unreadable on any F5 or deep link. The fix has the parent ask
 * for a measurement once it is listening, and the frame answer unconditionally.
 *
 * The check is a reload, which is exactly the reported repro: open works,
 * reload collapses. `loading="lazy"` means only the visible expanded frame is
 * in the race, which is why the long-body message (opened expanded) is the one
 * that shows it.
 */
const SUBJECT = "E2E Long Body";
const MIN_HEIGHT = 80;

test.beforeEach(() => {
    seed("seed-mail", "seed-rendering");
});

test.describe("sandboxed message frame height", () => {
    const frame = (page: Page) => page.locator("[data-mail--message-frame-target='frame']").first();

    async function frameHeight(page: Page): Promise<number> {
        await expect(frame(page)).toBeVisible();
        // The parent sizes the frame by writing an inline pixel height; poll it
        // until it exceeds the minimum, so a slow measurement is a wait, not a
        // failure. Returns the settled height.
        await expect
            .poll(async () => (await frame(page).boundingBox())?.height ?? 0, { timeout: 5000 })
            .toBeGreaterThan(MIN_HEIGHT + 1);

        return (await frame(page).boundingBox())?.height ?? 0;
    }

    test("stays as tall as its content across a full reload", async ({ page }) => {
        await page.goto("/mail/inbox");
        await mailRow(page, SUBJECT).click();

        const openedHeight = await frameHeight(page);
        expect(openedHeight).toBeGreaterThan(400); // 60 paragraphs are not 80px

        // The bug: a full reload of the thread URL. Before the fix the frame
        // collapsed to exactly the 80px minimum here.
        await page.reload();

        const reloadedHeight = await frameHeight(page);
        expect(reloadedHeight).toBeGreaterThan(400);
    });
});
