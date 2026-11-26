import type { Page } from "@playwright/test";
import { test, expect } from "./support/test";
import { INBOX_SUBJECTS, bulkMore, mailRow, seed } from "./support/config";
import { INBOX_BADGE } from "./support/sidebar";

/**
 * The count on the tab icon (#662), for somebody who switched it on in
 * Settings → Notifications: new mail by default, unread on request. Which
 * account group and which workspaces are TabIconCountTest's (PHPUnit): they
 * change the number, not how it reaches the icon.
 *
 * Read off the icon link itself: the count is drawn into an SVG that the link
 * names as a data: URL, so the number is in the href, and a plain icon is a
 * plain URL. What a browser then paints in the tab strip is the browser's
 * business and is not tested.
 */

const ICON = 'link[rel="icon"][type="image/svg+xml"]';
const DATA = "data:image/svg+xml,";

/** The number on the tab icon, or null for the plain icon. */
async function iconCount(page: Page): Promise<string | null> {
    const href = (await page.locator(ICON).getAttribute("href")) ?? "";

    if (!href.startsWith(DATA)) {
        return null;
    }

    const texts = [...decodeURIComponent(href.slice(DATA.length)).matchAll(/<text[^>]*>([^<]*)<\/text>/g)];

    // The badge is drawn last, over the icon.
    return texts.at(-1)?.[1] ?? null;
}

/**
 * Set one of the Notifications card's choices. The radios are sr-only; the
 * label takes the click, and the form posts itself. A radio already chosen
 * posts nothing, so it is left alone.
 */
async function choose(page: Page, name: string, value: string): Promise<void> {
    if (!page.url().endsWith("/settings/notifications")) {
        await page.goto("/settings/notifications");
    }

    const radio = page.locator(`input[name="${name}"][value="${value}"]`);

    if (await radio.isChecked()) {
        return;
    }

    const posted = page.waitForResponse(
        (response) => response.request().method() === "POST" && response.url().endsWith("/settings/notifications"),
    );

    await page.locator(`label:has(input[name="${name}"][value="${value}"])`).click();
    await posted;
    await page.waitForLoadState("load");
    await expect(radio).toBeChecked();
}

async function switchTabIconCount(page: Page, value: "1" | "0"): Promise<void> {
    await choose(page, "tabIconCount", value);
}

/** Tick conversations by their subjects and mark them read from the toolbar. */
async function markRead(page: Page, ...subjects: string[]): Promise<void> {
    for (const subject of subjects) {
        await mailRow(page, subject).locator("label:has(input[data-thread-select])").click();
    }

    await bulkMore(page, "Mark as read");
}

/** A Drive visit, as a click on a link in the app makes one. */
async function turboVisit(page: Page, url: string): Promise<void> {
    await page.evaluate(
        (target) => (window as unknown as { Turbo: { visit(u: string): void } }).Turbo.visit(target),
        url,
    );
    await page.waitForURL(`**${url}**`);
}

/** What a backgrounded tab reports; see polling.spec.ts for why it is supplied. */
async function hideTab(page: Page): Promise<void> {
    await page.evaluate(() => {
        Object.defineProperty(document, "visibilityState", { configurable: true, get: () => "hidden" });
        Object.defineProperty(document, "hidden", { configurable: true, get: () => true });
    });
}

test.beforeEach(() => {
    seed("seed-mail");
});

test("off by default: the tab icon is the plain picture", async ({ page }) => {
    await page.goto("/mail/inbox");
    await expect(page.locator(INBOX_BADGE).first()).toHaveText("4");

    expect(await iconCount(page)).toBeNull();
});

test.describe("switched on", () => {
    test.beforeEach(async ({ page }) => {
        await switchTabIconCount(page, "1");
    });

    // The switch is the user's, not the test's: put it back for the next spec.
    test.afterEach(async ({ page }) => {
        await choose(page, "tabIconCountKind", "new");
        await switchTabIconCount(page, "0");
    });

    /**
     * The default: new mail. The seeded conversations have never been listed,
     * so Settings counts all four; the Inbox shows them, which retires them,
     * and the icon goes plain without anything being read.
     */
    test("new by default: a look at the Inbox clears it", async ({ page }) => {
        await page.goto("/settings/notifications");
        await expect.poll(() => iconCount(page)).toBe("4");

        await turboVisit(page, "/mail/inbox");
        await expect(mailRow(page, INBOX_SUBJECTS.read)).toBeVisible();
        await expect.poll(() => iconCount(page), { timeout: 5000, message: "listed, so no longer new" }).toBeNull();

        // Still unread: the badge keeps its number.
        await expect(page.locator(INBOX_BADGE).first()).toHaveText("4");
    });

    test("unread: the icon carries the Inbox count and follows it", async ({ page }) => {
        await choose(page, "tabIconCountKind", "unread");
        await page.goto("/mail/inbox");
        await expect(mailRow(page, INBOX_SUBJECTS.read)).toBeVisible();

        await expect.poll(() => iconCount(page), { message: "drawn from the page's own number" }).toBe("4");

        await markRead(page, INBOX_SUBJECTS.read, INBOX_SUBJECTS.star);
        await expect(page.locator(INBOX_BADGE).first()).toHaveText("2", { timeout: 5000 });
        await expect.poll(() => iconCount(page), { timeout: 5000 }).toBe("2");

        // A Turbo visit puts the page's own, plain icon in; it is drawn again,
        // and still there once Settings has rendered a second time for its
        // redirect, which keeps the sidebar and reconnects nothing.
        await turboVisit(page, "/settings");
        await page.waitForLoadState("networkidle");
        expect(await iconCount(page)).toBe("2");

        // Mail read in another tab: this one hears it from the hub.
        const elsewhere = await page.context().newPage();
        await elsewhere.goto("/mail/inbox");
        await markRead(elsewhere, INBOX_SUBJECTS.archive);
        await expect(elsewhere.locator(INBOX_BADGE).first()).toHaveText("1", { timeout: 5000 });
        await elsewhere.close();
        await expect.poll(() => iconCount(page), { timeout: 10_000 }).toBe("1");

        // Back to Settings, which was loaded at 2.
        await turboVisit(page, "/mail/inbox");
        await page.goBack();
        await page.waitForURL("**/settings**");
        await expect.poll(() => iconCount(page)).toBe("1");

        // Drafts' title counts drafts; the icon still answers for the Inbox.
        await page.goto("/mail/drafts");
        await expect.poll(() => iconCount(page)).toBe("1");

        // Nothing unread: the plain icon again.
        await page.goto("/mail/inbox");
        await markRead(page, INBOX_SUBJECTS.trash);
        await expect.poll(() => iconCount(page), { timeout: 5000 }).toBeNull();
        expect(await page.locator(ICON).getAttribute("href")).toContain("/branding/");
    });

    /**
     * The case the icon is for. A hidden tab used to skip every counts refresh
     * that was not its own write, title included, so a pinned tab kept the
     * number it had when it was left. The mail is read in a second tab, and
     * the hub tells the hidden one, as it would for mail read on a phone.
     */
    test("a hidden tab still counts mail that changed elsewhere", async ({ page }) => {
        await choose(page, "tabIconCountKind", "unread");
        await page.goto("/mail/inbox");
        await expect(mailRow(page, INBOX_SUBJECTS.read)).toBeVisible();
        await expect.poll(() => iconCount(page)).toBe("4");

        await hideTab(page);

        const elsewhere = await page.context().newPage();
        await elsewhere.goto("/mail/inbox");
        await expect(mailRow(elsewhere, INBOX_SUBJECTS.read)).toBeVisible();
        await markRead(elsewhere, INBOX_SUBJECTS.read, INBOX_SUBJECTS.star);
        await expect(elsewhere.locator(INBOX_BADGE).first()).toHaveText("2", { timeout: 5000 });

        // A hidden tab counts at the sync's fifteen-second floor.
        await expect.poll(() => iconCount(page), { timeout: 25_000, message: "counted while hidden" }).toBe("2");
        await expect(page).toHaveTitle(/^\(2\) /);

        await elsewhere.close();
    });
});
