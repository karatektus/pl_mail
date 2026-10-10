import type { Page } from "@playwright/test";
import { test, expect, devices } from "./support/test";
import { INBOX_SUBJECTS, ajaxPost, mailRow, seed } from "./support/config";

/**
 * The category tab strip on a phone.
 *
 * Four tabs, each an icon, a label and room for a pill, are wider than a phone's
 * card. They used to be allowed to shrink (`min-w-0`), so every label kept its
 * one line and ran underneath the next tab's icon. The strip now keeps each tab
 * at its own width and scrolls inside itself, and opens with the tab you are on
 * in view.
 *
 * Only a browser can say any of this: it is layout, and the strings in the
 * template that produce it prove nothing about what a 393px card does with them.
 */
test.use({ ...devices["Pixel 5"] });

const STRIP = "nav:has(> a[href*='tab='])";
const TABS = `${STRIP} > a[href*='tab=']`;

test.beforeEach(async ({ page }) => {
    seed("seed-mail");

    // The mail fixture is all Primary, and the strip only renders when more than
    // one category holds mail. Same scaffolding dnd.spec.ts uses, for the same
    // reason: there is no console seed that sets a category.
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

/** Whether the strip is wider than the box showing it, and by how much. */
async function overflow(page: Page): Promise<number> {
    return page.locator(STRIP).evaluate((nav) => nav.scrollWidth - nav.clientWidth);
}

test("the tabs overflow into a scroll rather than squeezing into each other", async ({ page }) => {
    await page.goto("/mail/inbox");

    await expect(page.locator(TABS)).toHaveCount(4);

    // Presence companion: with nothing overflowing, "no tab is squeezed" would
    // pass for a strip that was never too wide to begin with.
    expect(await overflow(page)).toBeGreaterThan(0);

    // A squeezed tab is one whose content is wider than its box.
    const squeezed = await page.locator(TABS).evaluateAll((tabs) =>
        tabs.filter((tab) => tab.scrollWidth > tab.clientWidth).map((tab) => tab.textContent?.trim()),
    );

    expect(squeezed).toEqual([]);
});

test("every tab can be scrolled to", async ({ page }) => {
    await page.goto("/mail/inbox");

    expect(await overflow(page)).toBeGreaterThan(0);

    // The card clips what the strip pushes past its edge, so a strip that does
    // not scroll leaves its last tabs where nobody can reach them. Scrolled as
    // far as it goes, the last tab has to sit inside the strip.
    await page.locator(STRIP).evaluate((nav) => {
        nav.scrollLeft = nav.scrollWidth;
    });

    const nav = await page.locator(STRIP).boundingBox();
    const last = await page.locator(TABS).last().boundingBox();

    expect(nav).not.toBeNull();
    expect(last).not.toBeNull();
    expect(last!.x + last!.width).toBeLessThanOrEqual(nav!.x + nav!.width + 1);
});

test("the tab you are on is in view when the strip opens", async ({ page }) => {
    // Updates is the last tab, so it is the one a strip that starts at the left
    // edge leaves off-screen.
    await page.goto("/mail/inbox?tab=updates");

    expect(await overflow(page)).toBeGreaterThan(0);

    const current = page.locator(`${TABS}[data-ui--scroll-active-target="current"]`);
    await expect(current).toHaveCount(1);

    await expect
        .poll(async () => {
            const nav = await page.locator(STRIP).boundingBox();
            const tab = await current.boundingBox();

            return null !== nav && null !== tab && tab.x >= nav.x - 1 && tab.x + tab.width <= nav.x + nav.width + 1;
        })
        .toBe(true);
});
