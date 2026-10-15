import type { Page } from "@playwright/test";
import { test, expect, devices } from "./support/test";
import { INBOX_SUBJECTS, ajaxPost, mailRow, seed } from "./support/config";

/**
 * Switching a category tab replaces the history entry, at every width.
 *
 * Every tab is a frame navigation, and the list frame asks Turbo to `advance`,
 * so every tab the user had merely looked at became one more Back step before
 * Back left the inbox. The tab links now ask for `replace`, as Gmail's tabs
 * behave: the URL still follows the tab, the history does not grow.
 *
 * The invariant these guard is the reading pane's remembered list URL: after a
 * switch, opening a row and pressing the in-app Back must land on the tab that
 * was switched to, because that URL is read from the page once the frame has
 * loaded, and a replaced entry is the page's address from then on.
 *
 * Most cases run on a phone, where the system Back gesture made the extra
 * entries most noticeable; the desktop block checks the same holds there, and
 * that Back really leaves for the page before the inbox. The mobile cases
 * assert the viewport width, so a device that stopped being a phone would fail
 * loudly rather than pass for the desktop case.
 */
test.use({ ...devices["Pixel 5"] });

const STRIP = "nav:has(> a[href*='tab='])";
const TABS = `${STRIP} > a[href*='tab=']`;
const CURRENT = `${TABS}[data-ui--scroll-active-target="current"]`;
const SOCIAL = `${TABS}[href*='tab=social']`;
const BACK_BUTTON = "Back";

/** The header the list refresh sends; see FRAGMENT_HEADER in mail_pane_controller.js. */
const FRAGMENT_HEADER = "x-list-fragment";

test.beforeEach(async ({ page }) => {
    seed("seed-mail");

    // The mail fixture is all Primary, and the strip only renders when more than
    // one category holds mail. Same scaffolding category-tabs-mobile.spec.ts uses.
    await page.goto("/mail/inbox");

    for (const [subject, category] of [
        [INBOX_SUBJECTS.star, "social"],
        [INBOX_SUBJECTS.archive, "promotions"],
        [INBOX_SUBJECTS.trash, "updates"],
    ] as const) {
        const id = Number((await mailRow(page, subject).getAttribute("id"))?.replace("thread_", ""));
        expect((await ajaxPost(page, "/status/bulk/category", { ids: [id], category })).ok()).toBe(true);
    }
});

async function historyLength(page: Page): Promise<number> {
    return page.evaluate(() => history.length);
}

test("on a phone, switching tabs does not add a history entry", async ({ page }) => {
    await page.goto("/mail/inbox");

    expect(page.viewportSize()!.width).toBeLessThan(768);

    const before = await historyLength(page);

    await page.locator(SOCIAL).click();
    await expect(page.locator(CURRENT)).toHaveAttribute("href", /tab=social/);

    expect(new URL(page.url()).search).toContain("tab=social");
    expect(await historyLength(page)).toBe(before);
});

test.describe("on a desktop window", () => {
    test.use({ viewport: { width: 1280, height: 800 }, isMobile: false, hasTouch: false });

    test("switching tabs does not add a history entry either", async ({ page }) => {
        await page.goto("/mail/inbox");

        expect(page.viewportSize()!.width).toBeGreaterThanOrEqual(768);

        const before = await historyLength(page);

        await page.locator(SOCIAL).click();
        await expect(page.locator(CURRENT)).toHaveAttribute("href", /tab=social/);

        expect(new URL(page.url()).search).toContain("tab=social");
        expect(await historyLength(page)).toBe(before);
    });

    // The behaviour the issue asked for, end to end: after looking through
    // tabs, one Back leaves the inbox for wherever the user was before it.
    test("after switching tabs, Back leaves the inbox", async ({ page }) => {
        await page.goto("/mail/drafts");
        await page.goto("/mail/inbox");

        for (const tab of ["social", "promotions", "updates"]) {
            await page.locator(`${TABS}[href*='tab=${tab}']`).click();
            await expect(page.locator(CURRENT)).toHaveAttribute("href", new RegExp(`tab=${tab}`));
        }

        await page.goBack();

        await expect.poll(() => new URL(page.url()).pathname).toBe("/mail/drafts");
    });
});

test("after a switch, Back from an opened row lands on the switched-to tab", async ({ page }) => {
    await page.goto("/mail/inbox");

    expect(page.viewportSize()!.width).toBeLessThan(768);

    const social = page.locator(SOCIAL);
    const tabUrl = new URL((await social.getAttribute("href"))!, page.url()).href;

    await social.click();
    await expect(page.locator(CURRENT)).toHaveAttribute("href", /tab=social/);

    await expect(mailRow(page, INBOX_SUBJECTS.star)).toBeVisible();
    await mailRow(page, INBOX_SUBJECTS.star).click();
    await expect(page.locator("#message-list")).toBeHidden();

    await page.getByRole("button", { name: BACK_BUTTON }).first().click();

    await expect(page.locator("#message-list")).toBeVisible();
    await expect.poll(() => page.url()).toBe(tabUrl);
    await expect(page.locator(CURRENT)).toHaveAttribute("href", /tab=social/);
});

test("the switch still replaces the entry after a list refresh has morphed the tabs", async ({ page }) => {
    await page.goto("/mail/inbox");

    expect(page.viewportSize()!.width).toBeLessThan(768);

    const row = mailRow(page, INBOX_SUBJECTS.read);
    await expect(row).toBeVisible();

    const id = Number((await row.getAttribute("id"))?.replace("thread_", ""));
    const unread = (await row.getAttribute("data-unread")) === "true";

    // Armed before the event, so the refresh's own request cannot be missed.
    const refresh = page.waitForRequest((request) => request.headers()[FRAGMENT_HEADER] === "inbox-list-frame");

    // A change the open list shows, made on the server behind the page's back.
    // The refresh is the only thing that can make it visible.
    expect((await ajaxPost(page, `/status/thread/${id}/read`, { read: unread })).ok()).toBe(true);

    // Forced the way list-back-navigation.spec.ts forces it. This is the first
    // refresh since the page loaded, so MIN_REFRESH_MS does not hold it back.
    await page.evaluate(() => {
        document.body.dispatchEvent(
            new CustomEvent("core--mercure:mailbox-synced", {
                bubbles: true,
                detail: { poll: true },
            }),
        );
    });

    await refresh;
    await expect(row).toHaveAttribute("data-unread", String(!unread));

    const before = await historyLength(page);

    await page.locator(SOCIAL).click();
    await expect(page.locator(CURRENT)).toHaveAttribute("href", /tab=social/);

    expect(await historyLength(page)).toBe(before);
});
