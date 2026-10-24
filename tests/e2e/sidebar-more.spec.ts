import { test, expect, type Page } from "./support/test";

/**
 * Choosing which system folders wait behind "More" in the sidebar.
 *
 * The server-side half — what is stored, what is refused, which rows land in
 * which group and why — is pinned by tests/Controller/Settings/SidebarMoreTest.
 * What only a browser can show is the part a person actually does: tick a box
 * in Settings, and the row moves in the sidebar beside it; click through to a
 * folder tucked behind More and the disclosure is still open on the other side,
 * because the sidebar re-renders on every visit and a shut one would snap closed
 * on the row you had just chosen.
 *
 * Runs authenticated as this worker's own user, signed in by the worker fixture
 * in support/test.ts. This spec writes a per-user preference that outlives it,
 * so afterEach puts the default back — a worker's user left with Sent behind
 * More would be a booby trap for every spec that looks for it on top.
 */

const DESKTOP = { width: 1280, height: 900 };

/** What everybody who has never chosen has, and what the form opens ticked. */
const DEFAULT_BEHIND_MORE = ["Snoozed", "Archive", "Spam", "Trash"];

/** Every folder the form offers; the Inbox is pinned and not among them. */
const OFFERED = [
    "Unread",
    "Starred",
    "Sent",
    "Drafts",
    "Snoozed",
    "Archive",
    "Spam",
    "Trash",
];

const card = (page: Page) =>
    page.locator('form[action="/settings/sidebar/more"]');

const box = (page: Page, label: string) =>
    card(page).getByRole("checkbox", { name: label, exact: true });

/**
 * Tick or clear one box and wait for the save. Each change submits the form on
 * its own and the page comes back reloaded, so the wait is on the POST rather
 * than on a navigation that looks the same before and after.
 */
async function setBehindMore(page: Page, label: string, behind: boolean) {
    if ((await box(page, label).isChecked()) === behind) {
        return;
    }

    const saved = page.waitForResponse(
        (r) =>
            r.url().includes("/settings/sidebar/more") &&
            r.request().method() === "POST",
    );

    await box(page, label).click();
    await saved;

    // Re-queried by the assertion, which is what survives the reload.
    if (behind) {
        await expect(box(page, label)).toBeChecked();
    } else {
        await expect(box(page, label)).not.toBeChecked();
    }
}

/**
 * How far the settings content is scrolled. The page scrolls inside its own
 * pane, not the window, so this walks up from the card to the first ancestor
 * that scrolls.
 */
async function scrollOf(page: Page): Promise<number> {
    return card(page).evaluate((form) => {
        for (let el: HTMLElement | null = form as HTMLElement; el; el = el.parentElement) {
            if (el.scrollHeight > el.clientHeight && "auto" === getComputedStyle(el).overflowY) {
                return el.scrollTop;
            }
        }

        return window.scrollY;
    });
}

test.beforeEach(async ({ page }) => {
    await page.goto("/settings?section=general");
});

test.afterEach(async ({ page }) => {
    await page.goto("/settings?section=general");

    for (const label of OFFERED) {
        await setBehindMore(page, label, DEFAULT_BEHIND_MORE.includes(label));
    }
});

test.describe("sidebar: what waits behind More (desktop)", () => {
    test.use({ viewport: DESKTOP });

    test("opens ticked with the folders that were always behind More", async ({
        page,
    }) => {
        for (const label of OFFERED) {
            if (DEFAULT_BEHIND_MORE.includes(label)) {
                await expect(box(page, label)).toBeChecked();
            } else {
                await expect(box(page, label)).not.toBeChecked();
            }
        }

        // The Inbox is said to stay put, not silently missing from the list.
        await expect(card(page).getByRole("checkbox", { name: "Inbox" })).toHaveCount(0);
        await expect(card(page)).toContainText("The Inbox always stays where it is");
    });

    test("moves a folder between the sidebar and More, and it stays there after a reload", async ({
        page,
    }) => {
        await setBehindMore(page, "Sent", true);
        await setBehindMore(page, "Archive", false);

        await page.goto("/mail/inbox");

        const sidebar = page.locator("#sidebar");
        const more = sidebar.locator("[data-sidebar-more]");

        // Sent went in, Archive came out. Each assertion has its opposite
        // beside it: a locator that matched nothing would pass "not in More"
        // for every row there is.
        await expect(more.locator('[data-sidebar-row="sent"]')).toHaveCount(1);
        await expect(sidebar.locator('nav > [data-sidebar-row="sent"]')).toHaveCount(0);
        await expect(sidebar.locator('nav > [data-sidebar-row="archive"]')).toHaveCount(1);
        await expect(more.locator('[data-sidebar-row="archive"]')).toHaveCount(0);

        // Rendered by the server, not rearranged after paint.
        await page.reload();
        await expect(more.locator('[data-sidebar-row="sent"]')).toHaveCount(1);
        await expect(sidebar.locator('nav > [data-sidebar-row="archive"]')).toHaveCount(1);
    });

    /**
     * The save redraws the page in place: the sidebar beside the form takes the
     * change without the document being loaded again. A full reload drops
     * everything a page holds in memory, so a value planted on `window` before
     * the click is the proof — it survives a morph and not a reload. The scroll
     * position is the part a person notices, and is kept the same way.
     */
    test("ticking a box moves the row in the sidebar beside it without reloading the page", async ({
        page,
    }) => {
        const more = page.locator("#sidebar [data-sidebar-more]");

        await expect(more.locator('[data-sidebar-row="sent"]')).toHaveCount(0);

        // Scrolled to the card, as somebody using it would be.
        await card(page).scrollIntoViewIfNeeded();
        const scrolledTo = await scrollOf(page);

        await page.evaluate(() => {
            (window as unknown as { __sameDocument: boolean }).__sameDocument = true;
        });

        await setBehindMore(page, "Sent", true);

        await expect(more.locator('[data-sidebar-row="sent"]')).toHaveCount(1);
        await expect(page.locator('#sidebar nav > [data-sidebar-row="sent"]')).toHaveCount(0);

        expect(
            await page.evaluate(() => (window as unknown as { __sameDocument?: boolean }).__sameDocument),
            "the page was reloaded",
        ).toBe(true);
        expect(
            await scrollOf(page),
        ).toBe(scrolledTo);
    });

    test("More stays open when you click through to a folder inside it", async ({
        page,
    }) => {
        await setBehindMore(page, "Sent", true);

        await page.goto("/mail/inbox");

        const more = page.locator("#sidebar [data-sidebar-more]");

        // Shut while you are somewhere else, and nothing in it is visible.
        await expect(more).not.toHaveAttribute("open", "");
        await expect(more.locator('a[href="/mail/sent"]')).toBeHidden();

        await more.locator("> summary").click();
        await expect(more).toHaveAttribute("open", "");

        // A real in-app click. A folder link swaps the list inside its frame
        // and leaves the sidebar alone, so what this shows is that choosing a
        // folder behind More does not shut it — NOT that the server renders it
        // open, which only a fresh load can show; see the next test.
        await more.locator('a[href="/mail/sent"]').first().click();
        await expect(page).toHaveURL(/\/mail\/sent/);

        await expect(page.locator("#sidebar [data-sidebar-more]")).toHaveAttribute("open", "");
    });

    test("More arrives open when the page you load is inside it, and shut otherwise", async ({
        page,
    }) => {
        await setBehindMore(page, "Sent", true);

        // Rendered by the server on a full load — a reload, a bookmark, a link
        // from elsewhere. The sidebar is not kept between visits, so the answer
        // to "should this be open?" is "are you in it?", and nothing but the
        // markup can carry it. Each half has its opposite beside it.
        await page.goto("/mail/sent");
        await expect(page.locator("#sidebar [data-sidebar-more]")).toHaveAttribute("open", "");

        await page.goto("/mail/inbox");
        await expect(page.locator("#sidebar [data-sidebar-more]")).not.toHaveAttribute("open", "");

        // Archive is not behind More any more, so being on it opens nothing.
        await page.goto("/settings?section=general");
        await setBehindMore(page, "Archive", false);
        await page.goto("/mail/archive");
        await expect(page.locator("#sidebar [data-sidebar-more]")).not.toHaveAttribute("open", "");
    });

    test("with nothing behind More there is no More", async ({ page }) => {
        for (const label of DEFAULT_BEHIND_MORE) {
            await setBehindMore(page, label, false);
        }

        await page.goto("/mail/inbox");

        // The settings page and the sidebar agree that the disclosure is gone,
        // not that it is empty.
        await expect(page.locator("#sidebar [data-sidebar-more]")).toHaveCount(0);
        await expect(page.locator("#sidebar nav > [data-sidebar-row]").first()).toBeVisible();
    });
});
