import { type Page } from "@playwright/test";
import { test, expect } from "./support/test";

/**
 * The clock the browser's Back button shows is the time now, not the time the
 * page was made.
 *
 * THE REPORTED BUG
 * ────────────────
 * "When going back in the browser the clock goes back too." Back does not
 * always ask the server again. A page left by a full navigation comes back out
 * of the browser's own caches, and a page Turbo snapshotted comes back as the
 * snapshot; either way the reading in it is as old as the page. ui--clock only
 * wrote the time when the minute turned, so the old reading stood until then.
 *
 * ── The one thing simulated ──────────────────────────────────────────────────
 * Which cache answers Back is the browser's business and differs between them,
 * and waiting for a page to grow old would make this minutes long. So the
 * browser's own clock is moved instead: whatever markup Back brings, the server
 * wrote it at the real time, and a clock that reads the time on connecting
 * shows the browser's. The offset is not a whole number of hours, so the
 * minutes differ whatever zone or format the user has.
 *
 * Verified by removing the tick from connect() and recompiling the assets
 * (`asset-map:compile`; the test stack serves the compiled copy, and an edit
 * that is not compiled changes nothing here): both tests then read the
 * server's minutes back.
 */

const CLOCK = "[data-controller~='ui--clock']";
const READING = `${CLOCK} [data-ui--clock-target='bare']`;

/** Three hours and seventeen minutes on. */
const OFFSET_MS = (3 * 60 + 17) * 60 * 1000;

/** The minutes the page's clock has to show at $at, in the zone it keeps. */
async function minutesAt(page: Page, at: number): Promise<string> {
    return page.evaluate(
        ([selector, time]) => {
            const zone = document.querySelector(selector as string)?.getAttribute("data-ui--clock-zone-value") || undefined;

            return new Intl.DateTimeFormat("en-CA", { timeZone: zone, minute: "2-digit" })
                .format(new Date(time as number))
                .padStart(2, "0");
        },
        [CLOCK, at],
    );
}

async function leaveTheBrowserClockAhead(page: Page): Promise<RegExp> {
    const later = Date.now() + OFFSET_MS;
    const minutes = await minutesAt(page, later);

    await page.clock.setFixedTime(later);

    return new RegExp(`^\\d{1,2}:${minutes}$`);
}

test.describe("the browser's back button does not bring an old time back with it", () => {
    test("to a page Turbo left", async ({ page }) => {
        await page.goto("/settings");
        await expect(page.locator(READING).first(), "the clock has to be on this page").toHaveText(/^\d{1,2}:\d{2}$/);

        const now = await leaveTheBrowserClockAhead(page);

        await page.evaluate(() =>
            (window as unknown as { Turbo: { visit(u: string): void } }).Turbo.visit("/mail/inbox"),
        );
        await page.waitForURL("**/mail/inbox**");

        await page.goBack();
        await page.waitForURL("**/settings**");

        await expect(page.locator(READING).first()).toHaveText(now);
    });

    test("to a page left by a full navigation", async ({ page }) => {
        await page.goto("/settings");
        await expect(page.locator(READING).first(), "the clock has to be on this page").toHaveText(/^\d{1,2}:\d{2}$/);

        const now = await leaveTheBrowserClockAhead(page);

        // A document the browser loads itself, with no Turbo on the way out.
        await page.goto("/icons/favicon.svg");

        await page.goBack();
        await page.waitForURL("**/settings**");

        await expect(page.locator(READING).first()).toHaveText(now);
    });
});
