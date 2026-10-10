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

        // A real in-app click rather than page.goto: the sidebar is
        // re-rendered per visit, which is exactly when a disclosure that only
        // the browser remembered would snap shut under the row just chosen.
        await more.locator('a[href="/mail/sent"]').first().click();
        await expect(page).toHaveURL(/\/mail\/sent/);

        await expect(page.locator("#sidebar [data-sidebar-more]")).toHaveAttribute("open", "");
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
