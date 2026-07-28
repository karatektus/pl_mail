import { test, expect, type Page } from "@playwright/test";

/**
 * The sidebar has two ways of getting out of the way, and one button drives
 * both: below md it opens an overlay drawer, at md and up it collapses the
 * inline sidebar to an icon rail. The rail state lives in localStorage, so
 * these specs also cover it surviving a reload without a flash of the wide
 * sidebar.
 *
 * Runs authenticated via the shared storage state from auth.setup.ts.
 */

const DESKTOP = { width: 1280, height: 900 };
const MOBILE = { width: 420, height: 900 };

const toggle = (page: Page) =>
    page.getByRole("button", { name: /show or hide the sidebar/i });

const railed = (page: Page) =>
    page.evaluate(() =>
        document.documentElement.classList.contains("sidebar-rail"),
    );

test.beforeEach(async ({ page }) => {
    // The rail is remembered per browser; start every test expanded.
    await page.goto("/mail/inbox");
    await page.evaluate(() => localStorage.removeItem("plmail:sidebarRail"));
});

test.describe("sidebar rail (desktop)", () => {
    test.use({ viewport: DESKTOP });

    test("collapses the sidebar to an icon rail and back", async ({ page }) => {
        await page.goto("/mail/inbox");

        const sidebar = page.locator("#sidebar");
        const inboxLabel = sidebar.getByText("Inbox", { exact: true });

        await expect(sidebar).toBeVisible();
        await expect(inboxLabel).toBeVisible();

        await toggle(page).click();

        // The icon survives, the label does not.
        await expect(inboxLabel).toBeHidden();
        await expect(sidebar.locator("i.fa-inbox")).toBeVisible();
        await expect(sidebar).toHaveCSS("width", "56px");

        await toggle(page).click();
        await expect(inboxLabel).toBeVisible();
    });

    test("remembers the collapsed state across a reload", async ({ page }) => {
        await page.goto("/mail/inbox");
        await toggle(page).click();
        expect(await railed(page)).toBe(true);

        await page.reload();

        // Applied by the head script, so it is already on the element the
        // first time anything renders — no flash of the wide sidebar.
        expect(await railed(page)).toBe(true);
        await expect(page.locator("#sidebar")).toHaveCSS("width", "56px");
    });

    test("keeps the collapsed state across the settings and inbox shells", async ({
        page,
    }) => {
        await page.goto("/mail/inbox");
        await toggle(page).click();

        await page.goto("/settings");
        await expect(page.locator("#sidebar")).toHaveCSS("width", "56px");

        await page.goto("/mail/inbox");
        await expect(page.locator("#sidebar")).toHaveCSS("width", "56px");
    });

    test("keeps the compose button wider than it is tall when collapsed", async ({
        page,
    }) => {
        await page.goto("/mail/inbox");
        await toggle(page).click();

        const compose = page.locator("#sidebar .compose-link");
        const box = await compose.boundingBox();

        expect(box).not.toBeNull();
        expect(box!.width).toBeGreaterThanOrEqual(box!.height);
    });

    test("runs the active row's highlight off the left edge of the sidebar", async ({
        page,
    }) => {
        await page.goto("/mail/inbox");

        const sidebar = page.locator("#sidebar");
        const active = sidebar.locator(".nav-item.is-active");
        await expect(active).toHaveCount(1);

        const sidebarBox = await sidebar.boundingBox();
        const activeBox = await active.boundingBox();

        // Flush to the sidebar's own edge, with only its border in between.
        expect(activeBox!.x - sidebarBox!.x).toBeLessThanOrEqual(1);

        // …and capped on the right rather than squared off.
        await expect(active).toHaveCSS("border-top-right-radius", "9999px");
    });
});

test.describe("sidebar drawer (mobile)", () => {
    test.use({ viewport: MOBILE });

    test("opens the overlay drawer instead of the rail", async ({ page }) => {
        await page.goto("/mail/inbox");

        // The drawer is parked off-screen by a transform rather than hidden,
        // so its position is what says whether it is open.
        const drawer = page.locator('[data-sidebar-drawer-target="drawer"]');
        const backdrop = page.locator('[data-sidebar-drawer-target="backdrop"]');

        await expect(drawer).toHaveClass(/-translate-x-full/);

        await toggle(page).click();

        await expect(drawer).toHaveClass(/translate-x-0/);
        await expect(backdrop).toHaveClass(/opacity-100/);
        await expect(page.locator("#sidebar-drawer-inner")).toBeInViewport();

        // The rail is a desktop affordance and must stay out of it.
        expect(await railed(page)).toBe(false);

        await page.keyboard.press("Escape");
        await expect(drawer).toHaveClass(/-translate-x-full/);
    });
});
