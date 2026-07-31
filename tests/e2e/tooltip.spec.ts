import { test, expect } from "./support/test";

/**
 * The application-wide tooltip.
 *
 * Replaces the browser's native `title` bubble, which waits about a second and
 * renders in an unthemed black box that cannot be styled. One controller on
 * <body> handles every `title` in the app, so these assertions are about the
 * mechanism rather than any one button.
 */
test.describe("tooltips", () => {
    /** A topbar control that carries a title on every mail page. */
    const trigger = (page: import("@playwright/test").Page) =>
        page.locator('[title="Sync now"], [data-tooltip="Sync now"]').first();

    test("shows a themed bubble on hover instead of the native one", async ({ page }) => {
        await page.goto("/mail/inbox");

        const control = trigger(page);
        await control.hover();

        const tip = page.locator(".app-tooltip");
        await expect(tip).toBeVisible();
        await expect(tip).toHaveText("Sync now");

        // The native tooltip cannot be turned off, only starved: if `title` is
        // still on the element, the black box appears over ours a second later.
        await expect(control).not.toHaveAttribute("title", /.+/);
    });

    /**
     * `position: fixed` does not resolve against the viewport inside an
     * ancestor with `backdrop-filter`, which the panes use — so a bubble
     * rendered next to its trigger would be positioned against the wrong box
     * and clipped. It has to be a child of <body>.
     */
    test("renders at body level so the panes cannot trap or clip it", async ({ page }) => {
        await page.goto("/mail/inbox");
        await trigger(page).hover();
        await expect(page.locator(".app-tooltip")).toBeVisible();

        const parent = await page.evaluate(
            () => document.querySelector(".app-tooltip")?.parentElement?.tagName,
        );

        expect(parent).toBe("BODY");
    });

    test("hides again when the pointer leaves", async ({ page }) => {
        await page.goto("/mail/inbox");
        await trigger(page).hover();
        await expect(page.locator(".app-tooltip")).toBeVisible();

        // Somewhere with no tooltip of its own.
        await page.mouse.move(0, 400);

        await expect(page.locator(".app-tooltip")).toBeHidden();
    });

    /** A tooltip only reachable by pointer leaves keyboard users without it. */
    test("appears on keyboard focus and closes on Escape", async ({ page }) => {
        await page.goto("/mail/inbox");

        await trigger(page).focus();

        const tip = page.locator(".app-tooltip");
        await expect(tip).toBeVisible();
        await expect(tip).toHaveText("Sync now");

        await page.keyboard.press("Escape");

        await expect(tip).toBeHidden();
    });

    /**
     * Moving `title` out of the attribute would drop the description from the
     * accessibility tree, which the native tooltip does provide — so the bubble
     * is wired back up with aria-describedby.
     */
    test("keeps the description available to assistive technology", async ({ page }) => {
        await page.goto("/mail/inbox");

        const control = trigger(page);
        await control.hover();
        await expect(page.locator(".app-tooltip")).toBeVisible();

        const describedBy = await control.getAttribute("aria-describedby");
        expect(describedBy, "trigger must point at the bubble").toBeTruthy();

        await expect(page.locator(`#${describedBy}`)).toHaveText("Sync now");
    });
});
