import { test, expect, type Page } from "./support/test";
import { seed } from "./support/config";

/**
 * The sidebar's width and its peek (#699).
 *
 * The width is a stored per-user preference, and each worker's user is shared
 * across spec files: a test that left the sidebar at 480px would hand the next
 * file a narrow mail column and a failure somewhere unrelated. So every test
 * starts AND ends on the default, through the endpoint rather than the UI.
 *
 * The long label is taken away again after the file, for the same reason:
 * other specs count sidebar rows.
 */

const DESKTOP = { width: 1280, height: 900 };
// Too long for the default 224px, short enough to fit inside the 480px the
// peek may lean out to.
const LONG_LABEL = "Northwind Traders International Accounts";

const handle = (page: Page) => page.locator("[data-sidebar-handle]");
const pane = (page: Page) => page.locator(".sidebar-pane");
const aside = (page: Page) => page.locator("#sidebar");

async function width(page: Page, selector: string): Promise<number> {
    const box = await page.locator(selector).boundingBox();
    expect(box).not.toBeNull();

    return Math.round(box!.width);
}

/** Posts straight to the endpoint with the token the page renders. */
async function setState(page: Page, fields: Record<string, string>): Promise<void> {
    const token = await page
        .locator("[data-ui--sidebar-split-token-value]")
        .first()
        .getAttribute("data-ui--sidebar-split-token-value");

    const response = await page.request.post("/mail/sidebar/state", {
        form: { ...fields, _token: token ?? "" },
        headers: { "X-Requested-With": "fetch" },
    });
    expect(response.ok()).toBe(true);
}

async function reset(page: Page): Promise<void> {
    if (false === page.url().includes("/mail")) {
        await page.goto("/mail/inbox");
    }

    await setState(page, { width: "224" });
    await setState(page, { key: "peek", enabled: "1" });
}

test.use({ viewport: DESKTOP });

test.afterAll(() => {
    seed(`seed-label --name='${LONG_LABEL}' --remove`);
});

test.beforeEach(async ({ page }) => {
    // Here rather than in beforeAll: the worker's mailbox is reset when its
    // sign-in fixture first runs, which is after a beforeAll that asks for no
    // fixtures. Idempotent, so once per test costs a lookup.
    seed(`seed-label --name='${LONG_LABEL}'`);

    await page.goto("/mail/inbox");
    await page.evaluate(() => localStorage.removeItem("plmail:sidebarRail"));
    await reset(page);
    await page.goto("/mail/inbox");
});

test.afterEach(async ({ page }) => {
    await page.evaluate(() => localStorage.removeItem("plmail:sidebarRail"));
    await reset(page);
});

test.describe("the boundary", () => {
    test("starts at the old fixed width and costs the row nothing", async ({ page }) => {
        expect(await width(page, ".sidebar-pane")).toBe(224);
        expect(await width(page, "#sidebar")).toBe(224);

        // The handle lies over the gutter, and no further: the mail column
        // starts where the handle ends.
        const grip = await handle(page).boundingBox();
        const column = await page.locator("[data-mail-column]").boundingBox();

        expect(Math.round(grip!.x)).toBe(224 + Math.round((await pane(page).boundingBox())!.x));
        expect(Math.round(grip!.x + grip!.width)).toBeLessThanOrEqual(Math.round(column!.x));
    });

    test("is dragged wider, remembers it, and the other pages follow", async ({ page }) => {
        const grip = (await handle(page).boundingBox())!;
        const before = (await page.locator("[data-mail-column]").boundingBox())!.x;

        await page.mouse.move(grip.x + grip.width / 2, grip.y + grip.height / 2);
        await page.mouse.down();
        await page.mouse.move(grip.x + grip.width / 2 + 80, grip.y + grip.height / 2, { steps: 8 });

        const saved = page.waitForResponse((r) => r.url().includes("/mail/sidebar/state"));
        await page.mouse.up();
        await saved;

        expect(await width(page, ".sidebar-pane")).toBe(304);

        // The list gave up what the sidebar took.
        const after = (await page.locator("[data-mail-column]").boundingBox())!.x;
        expect(Math.round(after - before)).toBe(80);

        // Drawn from the server on the next paint, on every page that has a sidebar.
        for (const path of ["/mail/inbox", "/settings/layout", "/calendar"]) {
            await page.goto(path);
            await expect(page.locator(".sidebar-boundary")).toHaveAttribute("style", /--sidebar-width: 304px/);
            expect(await width(page, ".sidebar-pane")).toBe(304);
        }
    });

    test("stops at the maximum", async ({ page }) => {
        const grip = (await handle(page).boundingBox())!;

        await page.mouse.move(grip.x + 4, grip.y + 100);
        await page.mouse.down();
        await page.mouse.move(grip.x + 600, grip.y + 100, { steps: 10 });
        await page.mouse.up();

        expect(await width(page, ".sidebar-pane")).toBe(480);
    });

    test("answers the arrow keys the way the drag goes, and a double-click resets it", async ({ page }) => {
        await handle(page).focus();

        // Right is wider for a pane on the left — the opposite of the
        // calendar's handle, and the same as dragging this one.
        await page.keyboard.press("ArrowRight");
        await page.keyboard.press("ArrowRight");
        await page.keyboard.press("ArrowRight");

        // Three steps, not one: each press steps from the width last written,
        // not from a box still part-way through its transition.
        await expect.poll(() => width(page, ".sidebar-pane")).toBe(224 + 3 * 16);

        await page.keyboard.press("ArrowLeft");
        await expect.poll(() => width(page, ".sidebar-pane")).toBe(224 + 2 * 16);

        await handle(page).dblclick();
        await expect.poll(() => width(page, ".sidebar-pane")).toBe(224);
    });

    test("steps aside for the rail and gives the dragged width back", async ({ page }) => {
        await setState(page, { width: "300" });
        await page.goto("/mail/inbox");

        await page.getByRole("button", { name: /show or hide the sidebar/i }).click();

        await expect(pane(page)).toHaveCSS("width", "56px");
        await expect(handle(page)).toBeHidden();

        await page.getByRole("button", { name: /show or hide the sidebar/i }).click();

        await expect(pane(page)).toHaveCSS("width", "300px");
        await expect(handle(page)).toBeVisible();
    });
});

test.describe("the peek", () => {
    const longRow = (page: Page) => aside(page).getByText(LONG_LABEL, { exact: true });

    test("is on by default", async ({ page }) => {
        await expect(aside(page)).toHaveAttribute("data-sidebar-peek", "");
    });

    test("is switched off in Settings, at once on the sidebar beside it, and a clipped name stays clipped", async ({ page }) => {
        await page.goto("/settings/layout");

        const saved = page.waitForResponse((r) => r.url().includes("/mail/sidebar/state"));

        // The radios are sr-only; the label is what takes the click.
        await page.locator('#card-sidebar label:has(input[name="sidebar-peek"][value="0"])').click();
        await saved;

        await expect(aside(page)).not.toHaveAttribute("data-sidebar-peek", "");

        await longRow(page).hover();
        expect(await width(page, "#sidebar")).toBe(224);
    });

    test("leans out over the mail, which does not move, and hides the handle", async ({ page }) => {
        await setState(page, { key: "peek", enabled: "1" });
        await page.goto("/mail/inbox");

        await expect(aside(page)).toHaveAttribute("data-sidebar-truncated", "");

        const column = page.locator("[data-mail-column]");
        const before = (await column.boundingBox())!;

        await longRow(page).hover();

        await expect.poll(() => width(page, "#sidebar")).toBeGreaterThan(224);

        // Over, not beside: the box carrying the width and the mail column
        // are exactly where they were.
        expect(await width(page, ".sidebar-pane")).toBe(224);
        expect((await column.boundingBox())!.x).toBe(before.x);

        // The longest name now fits, once the lean-out has finished moving.
        await expect
            .poll(() => longRow(page).evaluate((el) => el.scrollWidth - el.clientWidth))
            .toBeLessThanOrEqual(0);

        // …and the handle, which would float over the labels, is gone.
        await expect(handle(page)).toHaveCSS("opacity", "0");
        await expect(handle(page)).toHaveCSS("pointer-events", "none");

        // Letting go puts both back.
        await page.mouse.move(before.x + before.width / 2, before.y + 200);
        await expect.poll(() => width(page, "#sidebar")).toBe(224);
        await expect(handle(page)).toHaveCSS("opacity", "1");
    });

    /**
     * A click leaves focus in the sidebar, and the peek used to hold on to it
     * (:focus-within): out over the list after the pointer had left, so the
     * next click on a row's checkbox opened the sidebar's Inbox instead.
     */
    test("lets go when the pointer leaves after a click, and keyboard focus still opens it", async ({ page }) => {
        await setState(page, { key: "peek", enabled: "1" });
        await page.goto("/mail/inbox");
        await expect(aside(page)).toHaveAttribute("data-sidebar-truncated", "");

        const column = (await page.locator("[data-mail-column]").boundingBox())!;

        await aside(page).getByRole("button", { name: "Create label" }).click();
        await page.locator("#modal-backdrop").getByRole("button", { name: "Cancel" }).click();
        await expect(page.locator("#modal-backdrop")).toBeHidden();
        expect(await aside(page).evaluate((el) => el.contains(document.activeElement))).toBe(true);

        await page.mouse.move(column.x + column.width / 2, column.y + 200);
        await expect.poll(() => width(page, "#sidebar")).toBe(224);

        // From the keyboard it is the way to read a clipped name.
        await page.keyboard.press("Shift+Tab");
        await page.keyboard.press("Tab");
        await expect.poll(() => width(page, "#sidebar")).toBeGreaterThan(224);
    });

    test("is not opened by reaching for the handle", async ({ page }) => {
        await setState(page, { key: "peek", enabled: "1" });
        await page.goto("/mail/inbox");
        await expect(aside(page)).toHaveAttribute("data-sidebar-truncated", "");

        const grip = (await handle(page).boundingBox())!;

        // From the mail, onto the handle — the way anyone aims for it.
        await page.mouse.move(grip.x + 200, grip.y + grip.height / 2);
        await page.mouse.move(grip.x + grip.width / 2, grip.y + grip.height / 2, { steps: 6 });

        expect(await width(page, "#sidebar")).toBe(224);
        await expect(handle(page)).toHaveCSS("opacity", "1");
    });

    test("does nothing when nothing is clipped", async ({ page }) => {
        await setState(page, { key: "peek", enabled: "1" });
        await setState(page, { width: "480" });
        await page.goto("/mail/inbox");

        // Measured on connect; give the frame it is coalesced into a chance.
        await expect.poll(async () => aside(page).getAttribute("data-sidebar-truncated")).toBeNull();

        await longRow(page).hover();
        expect(await width(page, "#sidebar")).toBe(480);
    });
});
