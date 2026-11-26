import { test, expect, type Page } from "./support/test";
import { settled } from "./support/motion";

/**
 * The installed app with its title bar hidden (#589). Headless Chromium runs
 * in a tab, where `display-mode: window-controls-overlay` never matches, so the
 * media query is stood in for; what is pinned is that the shell then meets the
 * window's edges, and that it does not anywhere else.
 */
async function asTitlebarless(page: Page): Promise<void> {
    await page.addInitScript(() => {
        const real = window.matchMedia.bind(window);
        window.matchMedia = (query: string) =>
            "(display-mode: window-controls-overlay)" === query
                ? ({ ...real(query), matches: true, media: query, addEventListener() {} } as unknown as MediaQueryList)
                : real(query);
    });
}

/** `#rrggbb` as getComputedStyle spells it, to compare a tag with a box. */
function rgb(hex: string | null): string {
    const [r, g, b] = (hex ?? "").replace("#", "").match(/../g)!.map((pair) => parseInt(pair, 16));
    return `rgb(${r}, ${g}, ${b})`;
}

async function box(page: Page, selector: string) {
    const b = await page.locator(selector).first().boundingBox();
    if (null === b) throw new Error(`${selector} has no box`);
    return { left: Math.round(b.x), top: Math.round(b.y), right: Math.round(b.x + b.width), bottom: Math.round(b.y + b.height) };
}

test("without a title bar, only the margin around the panels goes", async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 800 });
    await asTitlebarless(page);
    await page.goto("/mail/inbox");
    await settled(page);

    await expect(page.locator("html")).toHaveClass(/\bapp-titlebar\b/);

    const top = await box(page, ".shell-topbar");
    expect(top).toMatchObject({ left: 0, top: 0, right: 1280 });

    // The panels meet the window's edges and keep the gap between them…
    const side = await box(page, "#sidebar");
    const main = await box(page, ".main-pane");
    expect(side.left).toBe(0);
    expect(side.top).toBeGreaterThan(top.bottom);
    expect(main.right).toBe(1280);
    expect(main.bottom).toBe(800);

    // …and their size. Only a corner halfway along a window edge is squared
    // (it read as a notch); one facing another panel stays round, and so does
    // one in the window's own corner.
    const radii = (selector: string) => page.locator(selector).evaluate((el) => {
        const cs = getComputedStyle(el);
        return { tl: cs.borderTopLeftRadius, tr: cs.borderTopRightRadius, bl: cs.borderBottomLeftRadius, br: cs.borderBottomRightRadius };
    });
    await expect.poll(() => radii("#sidebar")).toMatchObject({ tl: "0px", br: "0px" });
    const sidebar = await radii("#sidebar");
    expect(sidebar.tr).not.toBe("0px");
    expect(sidebar.bl).not.toBe("0px");

    const bar = await radii(".shell-topbar");
    expect(bar).toMatchObject({ bl: "0px", br: "0px" });
    expect(bar.tl).not.toBe("0px");

    // Chrome paints the strips beside the window buttons in the theme-color,
    // so the app's tag has to be the bar's own colour, not the brand purple.
    // The server renders it ahead of the tab's, under a display-mode
    // condition; headless Chromium is no installed app, so the tag is read
    // rather than what the browser would pick.
    const colour = await page.locator(".shell-topbar").evaluate((el) => getComputedStyle(el).backgroundColor);
    const appTag = page.locator('meta[name="theme-color"][media*="window-controls-overlay"]');
    await expect(appTag).toHaveCount(1);
    expect(rgb(await appTag.getAttribute("content"))).toBe(colour);

    // …ahead of the purple, because the first matching tag wins.
    expect(await page.evaluate(() => [...document.querySelectorAll('meta[name="theme-color"]')].pop()?.getAttribute("media"))).toBeNull();

    // A link is a Turbo visit that merges the next page's head: the app's
    // colour has to arrive with it, not only on a reload.
    await page.locator("#sidebar a[href*='/mail/starred']").first().click();
    await page.waitForURL(/\/mail\/starred/);
    expect(rgb(await appTag.getAttribute("content"))).toBe(colour);
});

test("in a tab the shell keeps its gutter", async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 800 });
    await page.goto("/mail/inbox");
    await settled(page);

    await expect(page.locator("html")).not.toHaveClass(/\bapp-titlebar\b/);
    expect((await box(page, ".shell-topbar")).top).toBeGreaterThan(0);
    await expect(page.locator('meta[name="theme-color"]:not([media])')).toHaveAttribute("content", "#a21caf");
});
