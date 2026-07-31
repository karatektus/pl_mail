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

    /**
     * The caret is aimed by the controller rather than parked at the bubble's
     * midpoint, because the two only agree until a viewport edge pushes the
     * bubble off centre — which for a control in the corner of the topbar is
     * most of the time.
     */
    test("points a caret at the element it describes", async ({ page }) => {
        await page.goto("/mail/inbox");

        const control = trigger(page);
        await control.hover();

        const tip = page.locator(".app-tooltip");
        await expect(tip).toBeVisible();
        await expect(tip).toHaveAttribute("data-placement", /above|below/);

        const aim = await page.evaluate(() => {
            const bubble = document.querySelector(".app-tooltip") as HTMLElement;
            const box = bubble.getBoundingClientRect();
            const caretX = parseFloat(getComputedStyle(bubble).getPropertyValue("--caret-x"));
            const anchor = document
                .querySelector('[data-tooltip="Sync now"], [title="Sync now"]')!
                .getBoundingClientRect();

            return {
                caretPageX: box.left + caretX,
                anchorCentre: anchor.left + anchor.width / 2,
            };
        });

        // Within a couple of pixels of the trigger's centre.
        expect(Math.abs(aim.caretPageX - aim.anchorCentre)).toBeLessThan(3);
    });

    /**
     * A hint that restates what is already on screen is noise, and the sidebar
     * had eight of them stacked down the left edge — hovering "Inbox" produced
     * a bubble saying "Inbox".
     */
    test("says nothing when the element already shows the same text", async ({ page }) => {
        await page.goto("/mail/inbox");

        // Scoped to the desktop sidebar: the mobile drawer holds a second copy
        // of every one of these links, and it is not the one on screen here.
        await page.locator('#sidebar a[href="/mail/inbox"]').first().hover();
        await page.waitForTimeout(400);

        await expect(page.locator(".app-tooltip")).toBeHidden();
    });

    /**
     * The other half of the same rule. Collapsed to the icon rail the label
     * text is `display: none`, so the hint is the only thing identifying the
     * icon and has to come back — which is why those titles were kept rather
     * than deleted from the template.
     */
    test("gives the hint back when the sidebar collapses to icons", async ({ page }) => {
        await page.goto("/mail/inbox");

        await page.evaluate(() => document.documentElement.classList.add("sidebar-rail"));

        const inbox = page.locator('#sidebar a[href="/mail/inbox"]').first();

        // useInnerText, because the point is what is *rendered*: the label is
        // still in textContent, just `display: none`. That distinction is the
        // whole mechanism.
        await expect(inbox).toHaveText("", { useInnerText: true });

        await inbox.hover();

        await expect(page.locator(".app-tooltip")).toBeVisible();
        await expect(page.locator(".app-tooltip")).toHaveText("Inbox");
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
