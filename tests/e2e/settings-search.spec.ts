import { test, expect, type Page } from "./support/test";

/**
 * Settings: the tree, its filter and the ⌘K palette (#569).
 *
 * The server half — which cards each page draws, the index, the addresses —
 * is pinned by tests/Controller/Settings/SettingsPageAnchorsTest. What only a
 * browser can show:
 *
 *   - Typing in the filter hides the areas with nothing in them and shows,
 *     under each page that is left, the cards that match. A card found by a
 *     keyword says which one, so "dark" finding Theme does not look like a
 *     wrong answer.
 *   - Clearing the filter puts the folding back as the reader left it.
 *   - ⌘K / Ctrl+K opens the palette from a page that is not Settings at all,
 *     and Enter on a result lands on its page with that card ringed.
 *   - A result scrolls the settings pane only, not the clipped page shell.
 *   - On a phone the tree is behind one button, which names where you are.
 *
 * Read-only: nothing here saves a setting.
 */

const DESKTOP = { width: 1280, height: 900 };

const tree = (page: Page) => page.locator("#settings-tree");
const filter = (page: Page) => page.locator("#settings-filter");

test.describe("the settings tree", () => {
    test.beforeEach(async ({ page }) => {
        await page.setViewportSize(DESKTOP);
        await page.goto("/settings/labels");
    });

    test("opens on the area that holds the page, with the page marked", async ({ page }) => {
        const open = tree(page).locator("details[open]");

        await expect(open).toHaveCount(1);
        await expect(open.locator("summary")).toContainText("Inbox");
        await expect(tree(page).locator('a[aria-current="page"]')).toHaveText("Labels");
    });

    test("filters to the cards that match, by heading and by keyword", async ({ page }) => {
        await filter(page).fill("time zone");

        const hit = tree(page).locator("li[data-settings--nav-target='hit']:not([hidden])");

        await expect(hit).toHaveCount(1);
        await expect(hit).toContainText("Time zone");
        await expect(hit.locator("mark")).toHaveText(/time zone/i);

        // Inbox has nothing about time zones, so it is gone; General holds the
        // match and is open.
        await expect(tree(page).locator("summary", { hasText: "Inbox" })).toBeHidden();
        await expect(tree(page).locator("details", { hasText: "General" })).toHaveAttribute("open", "");

        await filter(page).fill("dark");

        const theme = tree(page).locator("li[data-settings--nav-target='hit']:not([hidden])", { hasText: "Theme" });

        await expect(theme).toBeVisible();
        await expect(theme).toContainText("Keyword: dark");
    });

    test("says so when nothing matches, and Escape puts the tree back", async ({ page }) => {
        await filter(page).fill("qqqzzz");

        await expect(tree(page).getByText("Nothing found.")).toBeVisible();

        await filter(page).press("Escape");

        await expect(filter(page)).toHaveValue("");
        await expect(tree(page).locator("details[open]")).toHaveCount(1);
        await expect(tree(page).locator("li[data-settings--nav-target='hit']:not([hidden])")).toHaveCount(0);
    });
});

test.describe("the ⌘K palette", () => {
    test("opens anywhere, and a result lands on its card", async ({ page }) => {
        await page.setViewportSize(DESKTOP);
        await page.goto("/mail/inbox");

        await page.keyboard.press("ControlOrMeta+k");

        const palette = page.locator("#settings-palette");

        await expect(palette).toBeVisible();

        await palette.getByRole("combobox").fill("2fa");

        const first = palette.getByRole("option").first();

        await expect(first).toContainText("Two-factor");
        await expect(first).toContainText("Keyword: 2fa");

        await page.keyboard.press("Enter");

        await expect(page).toHaveURL(/\/settings\/security#card-two-factor$/);
        await expect(page.locator("#card-two-factor")).toHaveClass(/settings-card-flash/);
    });

    test("keeps out of the way while another dialog is open", async ({ page }) => {
        await page.setViewportSize(DESKTOP);
        await page.goto("/settings/profile");

        await page.keyboard.press("ControlOrMeta+k");
        await expect(page.locator("#settings-palette")).toBeVisible();

        // Pressed again while it is open: one palette, not a second modal.
        await page.keyboard.press("ControlOrMeta+k");
        await expect(page.locator("dialog[open]")).toHaveCount(1);

        await page.keyboard.press("Escape");
        await expect(page.locator("#settings-palette")).toBeHidden();
    });
});

test("a result scrolls the settings pane, never the page around it", async ({ page }) => {
    // The shell is overflow-hidden, but a script can still scroll it, and
    // scrollIntoView does. The sr-only label of each alias under Compose
    // defaults was placed against the shell rather than the settings pane; with
    // a handful of aliases they reached below the window, and a result for
    // "mailto" slid the whole page up — top bar and all — with no scrollbar to
    // put it back. One alias is enough to show the label escaping, not enough
    // to move anything, so the escape is asserted as well as the scroll.
    //
    // The alias is added and removed here: the list is not reseeded between
    // runs (see settings-forms.spec.ts).
    const address = `scroll-${Date.now()}@example.test`;
    const account = page.locator("#settings-alias-list > div > div").first();

    await page.setViewportSize(DESKTOP);
    await page.goto("/settings/aliases");
    await account.getByPlaceholder(/Add another address/i).fill(address);
    await account.getByRole("button", { name: "Add" }).click();
    await expect(page.getByText(address)).toBeVisible();

    try {
        await filter(page).fill("mailto");
        await tree(page).locator("li[data-settings--nav-target='hit']:not([hidden]) a").first().click();

        await expect(page).toHaveURL(/\/settings\/compose#card-compose-behaviour$/);
        await expect(page.locator("#card-compose-behaviour")).toHaveClass(/settings-card-flash/);
        await expect(page.locator("label.sr-only", { hasText: address })).toHaveCount(1);

        const escaped = await page.evaluate(() => {
            const port = document.getElementById("card-compose-behaviour")!.closest(".overflow-y-auto")!;

            return [...port.querySelectorAll("*")]
                .filter((e) => "absolute" === getComputedStyle(e).position && false === port.contains((e as HTMLElement).offsetParent))
                .map((e) => e.outerHTML.slice(0, 80));
        });

        expect(escaped).toEqual([]);
        expect(await page.locator(".shell-frame").locator("..").evaluate((e) => e.scrollTop)).toBe(0);
    } finally {
        await page.goto("/settings/aliases");
        await page.locator("li").filter({ hasText: address }).getByRole("button", { name: "Remove" }).click();
        await expect(page.getByText(address)).toHaveCount(0);
    }
});

test("on a phone the tree waits behind a button that says where you are", async ({ page }) => {
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto("/settings/reading");

    const toggle = page.locator("button[aria-controls='settings-tree']");

    await expect(toggle).toContainText("Reading & writing");
    await expect(toggle).toContainText("Reading");
    await expect(tree(page)).toBeHidden();

    await toggle.click();

    await expect(tree(page)).toBeVisible();
    await expect(toggle).toHaveAttribute("aria-expanded", "true");
    await expect(filter(page)).toBeFocused();
});
