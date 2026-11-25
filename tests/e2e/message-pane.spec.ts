import { test, expect, type Page } from "./support/test";
import { INBOX_SUBJECTS, ajaxPost, mailRow, seed } from "./support/config";
import { INBOX_LINK } from "./support/sidebar";
import { only, row, useWorkspace } from "./support/workspace";

/**
 * The open message, in a pane of its own beside the list or in the list's place
 * (#755).
 *
 * This was reading-pane.spec, for the reading pane's Off and Right setting; a
 * workspace with or without a Message pane is that choice now. What is
 * measured is what a person would notice: whether the list is still on screen
 * after a click, where the message is drawn, what the list does while a
 * message is open beside it. The divider and its share were the reading
 * pane's own and went with it — the workspace's dividers are workspaces.spec's.
 *
 * Every test puts the workspace back to the list alone on the way out. The
 * account is shared with the rest of the suite, and a pane left beside the
 * list is a layout change in a spec that never asked for one.
 */

const WIDE = { width: 1920, height: 1000 };

/** Below lg: the panes take turns, and the message opens in the list's place. */
const NARROW = { width: 1000, height: 800 };

const list = (page: Page) => page.locator("#message-list");
const reading = (page: Page) => page.locator('[data-mail--mail-pane-target="reading"]');
const placeholder = (page: Page) => page.locator("[data-reading-placeholder]");

/**
 * Click something that navigates the list frame, and return once the frame's
 * own `turbo:frame-load` has been handled.
 *
 * Not the URL and not the new rows, which is what a test would naturally wait
 * for: Turbo advances the address and renders the list BEFORE it fires the
 * event the mail pane answers, so a test that asserted "the message is still
 * open" the moment the new rows appeared would pass in the gap — before the
 * pane had decided anything — and keep passing with the fix removed. The
 * listener is added after the pane's own, so by the time the flag is set the
 * pane has run.
 */
const swapList = async (page: Page, link: string) => {
    await page.evaluate(() => {
        const flagged = window as unknown as { __listSwapped: boolean };

        flagged.__listSwapped = false;
        document.addEventListener("turbo:frame-load", (event) => {
            if ("inbox-list-frame" === (event.target as Element).id) {
                flagged.__listSwapped = true;
            }
        });
    });

    await page.locator(link).first().click();
    await page.waitForFunction(() => (window as unknown as { __listSwapped: boolean }).__listSwapped);
};

const openMessage = async (page: Page) => {
    await mailRow(page, INBOX_SUBJECTS.read).click();
    await expect(reading(page)).toBeVisible();
};


// The four seeded threads, put back before every test. This file opens them,
// and one test archives one.
test.beforeEach(() => {
    seed("seed-mail");
});

test.afterEach(async ({ page }) => {
    await useWorkspace(page, only("list"));
});

test.describe("without a Message pane", () => {
    test.use({ viewport: WIDE });

    test("opening a message takes the place of the list", async ({ page }) => {
        await useWorkspace(page, only("list"));

        await openMessage(page);

        await expect(list(page)).toBeHidden();
        await expect(placeholder(page)).toBeHidden();
        await expect(page.locator('[data-layout-pane="list"] [data-mail--mail-pane-target="reading"]')).toBeVisible();
    });
});

test.describe("with a Message pane beside the list", () => {
    test.use({ viewport: WIDE });

    test.beforeEach(async ({ page }) => {
        await useWorkspace(page, row("list", "message"));
    });

    test("the list stays beside the message that was opened", async ({ page }) => {
        // Before anything is open: the hint stands where the message will be.
        await expect(list(page)).toBeVisible();
        await expect(placeholder(page)).toBeVisible();
        await expect(reading(page)).toBeHidden();

        await openMessage(page);

        await expect(list(page)).toBeVisible();
        await expect(placeholder(page)).toBeHidden();

        // Beside, not stacked: the message starts where the list ends.
        const listBox = (await list(page).boundingBox())!;
        const readingBox = (await reading(page).boundingBox())!;

        expect(readingBox.x).toBeGreaterThanOrEqual(listBox.x + listBox.width - 1);
        expect(Math.abs(readingBox.y - listBox.y)).toBeLessThan(2);
    });

    test("the open row says which message is showing", async ({ page }) => {
        await expect(page.locator('li[data-selected="true"]')).toHaveCount(0);

        await openMessage(page);

        const selected = page.locator('li[data-selected="true"]');

        await expect(selected).toHaveCount(1);
        await expect(selected).toContainText(INBOX_SUBJECTS.read);
        await expect(selected).toHaveAttribute("aria-current", "true");
    });

    test("the open row is still marked after the server replaces it", async ({ page }) => {
        // Opening a message posts that it was read, and the answer is a
        // turbo-stream that swaps the whole <li> for one the server rendered —
        // which knows nothing about what is open. The mark was set on the old
        // node and went with it about a hundred milliseconds later, and every
        // other assertion in this file looks at the row before that happens.
        // So this one waits for the replacement it is about, and fails loudly
        // rather than passing early if the row is ever not replaced.
        const before = await mailRow(page, INBOX_SUBJECTS.read).elementHandle();

        await mailRow(page, INBOX_SUBJECTS.read).click();
        await expect(page.locator('li[data-selected="true"]')).toHaveCount(1);

        await page.waitForFunction((row) => false === row?.isConnected, before);

        const selected = page.locator('li[data-selected="true"]');

        await expect(selected).toHaveCount(1);
        await expect(selected).toContainText(INBOX_SUBJECTS.read);
        await expect(selected).toHaveAttribute("aria-current", "true");
    });

    test("the list keeps following the mailbox while a message is open, asking for its own address", async ({ page }) => {
        await openMessage(page);

        // A refresh asks for the page's own address by default, and with a
        // message open that is the MESSAGE's, whose list frame is empty by
        // design and is dropped on arrival — so the list would stop updating
        // for as long as anything was open. Beside the list it must ask for the
        // list's address instead. Observed as the request itself: nothing in
        // the seeded mailbox changes on cue, and a refresh that fetched the
        // wrong address also changes nothing on screen, which is what made it
        // easy to ship.
        const refresh = page.waitForRequest(
            (request) => "inbox-list-frame" === request.headers()["x-list-fragment"],
        );

        await page.evaluate(() => document.dispatchEvent(new Event("visibilitychange")));

        expect(new URL((await refresh).url()).pathname).toBe("/mail/inbox");
    });

    test("the back arrow closes the message and leaves the list where it was", async ({ page }) => {
        await openMessage(page);

        await reading(page).getByRole("button", { name: /back/i }).first().click();

        await expect(list(page)).toBeVisible();
        await expect(reading(page)).toBeHidden();
        await expect(placeholder(page)).toBeVisible();
        await expect(page.locator('li[data-selected="true"]')).toHaveCount(0);
    });

    test("switching folders leaves the open message where it is, and only the list moves on", async ({ page }) => {
        // A folder, a category tab, the pager, a sort and a search all swap the
        // list frame, and the pane sits outside it. The swap used to close the
        // message, which is right while the message has the whole row and
        // wrong beside the list, where the list is not what it was covering.
        await openMessage(page);

        await swapList(page, "#sidebar a[href='/mail/sent']");
        await expect(page).toHaveURL(/\/mail\/sent/);

        // The list really moved: the inbox row is gone from it. Without this
        // the assertions below would also hold for a click that did nothing.
        await expect(mailRow(page, INBOX_SUBJECTS.read)).toHaveCount(0);

        await expect(reading(page)).toBeVisible();
        await expect(reading(page)).toContainText(INBOX_SUBJECTS.read);
        await expect(list(page)).toBeVisible();
        await expect(placeholder(page)).toBeHidden();

        // Not a row of this folder, so nothing in it can claim to be the open one…
        await expect(page.locator('li[data-selected="true"]')).toHaveCount(0);

        // …but the pane still knows which message it is showing: back in the
        // folder it came from, the row is marked again.
        await swapList(page, INBOX_LINK);
        await expect(page).toHaveURL(/\/mail\/inbox/);
        await expect(page.locator('li[data-selected="true"]')).toContainText(INBOX_SUBJECTS.read);
        await expect(reading(page)).toContainText(INBOX_SUBJECTS.read);
    });

    test("switching a category tab leaves the open message where it is", async ({ page }) => {
        // The tab strip only renders when more than one category holds mail,
        // and the seeded mailbox is all Primary. Scaffolded the way dnd.spec.ts
        // does it, for the same reason: no console seed sets a category.
        const id = Number((await mailRow(page, INBOX_SUBJECTS.star).getAttribute("id"))?.replace("thread_", ""));

        expect((await ajaxPost(page, "/status/bulk/category", { ids: [id], category: "social" })).ok()).toBe(true);

        await page.goto("/mail/inbox");
        await openMessage(page);

        await swapList(page, "nav a[data-category-tab-link][href*='tab=social']");
        await expect(page).toHaveURL(/tab=social/);

        // The list is the Social tab's: the thread moved there is on it and
        // the one that is open (Primary) is not. Without this the assertions
        // below also hold for a click that did nothing.
        await expect(mailRow(page, INBOX_SUBJECTS.star)).toBeVisible();
        await expect(mailRow(page, INBOX_SUBJECTS.read)).toHaveCount(0);

        await expect(reading(page)).toBeVisible();
        await expect(reading(page)).toContainText(INBOX_SUBJECTS.read);
        await expect(placeholder(page)).toBeHidden();
    });

    test("the back arrow, after switching folders, returns to the folder that was switched to", async ({ page }) => {
        await openMessage(page);

        await swapList(page, "#sidebar a[href='/mail/sent']");
        await expect(page).toHaveURL(/\/mail\/sent/);
        await expect(mailRow(page, INBOX_SUBJECTS.read)).toHaveCount(0);

        await reading(page).getByRole("button", { name: /back/i }).first().click();

        await expect(reading(page)).toBeHidden();
        await expect(placeholder(page)).toBeVisible();
        await expect(page).toHaveURL(/\/mail\/sent/);
        await expect(list(page)).toBeVisible();
    });

    test("the two toolbars are the same height, so one rule runs under both", async ({ page }) => {
        // Four pixels apart while they took turns on the same space, which
        // nobody could see; side by side it was a step in the line.
        await openMessage(page);

        const listBar = (await page.locator("[data-toolbar-row]").locator("..").boundingBox())!;
        const back = page.locator('[data-surface="reading"] [data-action="click->mail--mail-pane#close"]');
        const readingBar = (await back.locator("xpath=../..").boundingBox())!;

        expect(Math.abs(readingBar.y - listBar.y)).toBeLessThan(1);
        expect(Math.abs(readingBar.height - listBar.height)).toBeLessThan(1);
    });

    test("a key acts on the ticked rows rather than on the conversation open beside them", async ({ page }) => {
        // Beside the list a selection and an open conversation are on screen
        // together, which one pane at a time never allowed. The keys asked only
        // whether a conversation was open, so `e` with other rows ticked
        // archived the one being read and left the ticked ones where they were.
        await openMessage(page);
        await mailRow(page, INBOX_SUBJECTS.archive).locator("[data-thread-select]").check({ force: true });

        const posted = page.waitForRequest((request) => request.url().includes("/status/bulk/archive"));
        await page.keyboard.press("e");
        expect((await posted).postDataJSON().ids).toHaveLength(1);

        await expect(mailRow(page, INBOX_SUBJECTS.archive)).toHaveCount(0);

        // The open conversation was not what the key was for.
        await expect(mailRow(page, INBOX_SUBJECTS.read)).toHaveCount(1);
        await expect(reading(page)).toBeVisible();
    });

    test("a message opened by its own address still has a list beside it", async ({ page }) => {
        const href = await mailRow(page, INBOX_SUBJECTS.read)
            .locator('a[data-action~="click->mail--mail-pane#open"]')
            .getAttribute("href");

        expect(href).not.toBeNull();

        // The thread route has no list behind it; with the pane beside the list
        // that would be an empty column. The Inbox is drawn instead, with the
        // open conversation marked if it is on the page.
        await page.goto(href!);

        await expect(reading(page)).toBeVisible();
        await expect(mailRow(page, INBOX_SUBJECTS.read)).toBeVisible();
        await expect(page.locator('li[data-selected="true"]')).toContainText(INBOX_SUBJECTS.read);
    });
});

test.describe("with a Message pane, on a window below lg", () => {
    test.use({ viewport: NARROW });

    test.beforeEach(async ({ page }) => {
        await useWorkspace(page, row("list", "message"));
    });

    test("the panes take turns and the message takes the list's place", async ({ page }) => {
        await expect(placeholder(page)).toBeHidden();

        await openMessage(page);

        await expect(list(page)).toBeHidden();
    });

    test("a folder switch still closes the message, because it had covered the list", async ({ page }) => {
        await openMessage(page);
        await expect(list(page)).toBeHidden();

        await swapList(page, "#sidebar a[href='/mail/sent']");
        await expect(page).toHaveURL(/\/mail\/sent/);

        await expect(list(page)).toBeVisible();
        await expect(reading(page)).toBeHidden();
    });
});
