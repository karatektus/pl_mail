import { test, expect } from "./support/test";
import { INBOX_SUBJECTS, ajaxPost, mailRow, seed } from "./support/config";

/**
 * A category tab's unread count opens that tab's unread mail (#670).
 *
 * The server side (InboxTabUnreadCountTest) pins the links. Only a browser can
 * say which one a click lands on: the tab's own link is stretched over the
 * whole tab, and the count has to sit above that layer or every click on it
 * opens the whole tab, which is the report.
 *
 * Fixture: two seeded threads moved to Social, one of them marked read, so
 * Social says 1 and its unread view lists one of the two.
 */

const SOCIAL = "[data-category-tab='social']";

test.beforeEach(async ({ page }) => {
    seed("seed-mail");

    await page.goto("/mail/inbox");

    const ids: number[] = [];

    for (const subject of [INBOX_SUBJECTS.star, INBOX_SUBJECTS.archive]) {
        ids.push(Number((await mailRow(page, subject).getAttribute("id"))?.replace("thread_", "")));
    }

    expect((await ajaxPost(page, "/status/bulk/category", { ids, category: "social" })).ok()).toBe(true);
    expect((await ajaxPost(page, "/status/bulk/read", { ids: [ids[1]], read: true })).ok()).toBe(true);
    expect((await ajaxPost(page, "/status/bulk/read", { ids: [ids[0]], read: false })).ok()).toBe(true);
});

test.afterEach(() => {
    seed("seed-mail");
});

test("clicking the count opens the tab with only its unread mail", async ({ page }) => {
    await page.goto("/mail/inbox");

    const count = page.locator(`${SOCIAL} a[data-tab-unread]`);
    await expect(count).toHaveText("1");

    await count.click();

    await expect(page).toHaveURL(/tab=social/);
    await expect(page).toHaveURL(/unread=1/);
    await expect(mailRow(page, INBOX_SUBJECTS.star)).toBeVisible();
    await expect(mailRow(page, INBOX_SUBJECTS.archive)).toHaveCount(0);
});

test("clicking the rest of the tab still opens all of it", async ({ page }) => {
    await page.goto("/mail/inbox");

    // Where the icon is drawn, well away from the count: the stretched link
    // lies over it and answers there. A mouse click at that point rather than
    // locator.click(), which refuses an element something else covers — and
    // covering it is the design.
    const icon = (await page.locator(`${SOCIAL} svg`).first().boundingBox())!;
    await page.mouse.click(icon.x + icon.width / 2, icon.y + icon.height / 2);

    await expect(page).toHaveURL(/tab=social/);
    await expect(page).not.toHaveURL(/unread=1/);
    await expect(mailRow(page, INBOX_SUBJECTS.star)).toBeVisible();
    await expect(mailRow(page, INBOX_SUBJECTS.archive)).toBeVisible();
});

test("the count answers the pointer and the keyboard like the sidebar's", async ({ page }) => {
    await page.goto("/mail/inbox");

    const count = page.locator(`${SOCIAL} a[data-tab-unread]`);

    await count.hover();
    await expect(count).toHaveCSS("opacity", "0.8");

    await count.focus();
    await page.keyboard.press("Enter");

    await expect(page).toHaveURL(/tab=social.*unread=1|unread=1.*tab=social/);
});
