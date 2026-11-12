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

    // …and their card: a squared-off, stretched sidebar was the first try.
    expect(await page.locator("#sidebar").evaluate((el) => getComputedStyle(el).borderTopLeftRadius)).not.toBe("0px");

    // Chrome paints the strips beside the window buttons in the theme-color,
    // so it has to be the bar's own colour, not the brand purple.
    const bar = await page.locator(".shell-topbar").evaluate((el) => getComputedStyle(el).backgroundColor);
    await expect(page.locator('meta[name="theme-color"]')).toHaveAttribute("content", bar);
});

test("in a tab the shell keeps its gutter", async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 800 });
    await page.goto("/mail/inbox");
    await settled(page);

    await expect(page.locator("html")).not.toHaveClass(/\bapp-titlebar\b/);
    expect((await box(page, ".shell-topbar")).top).toBeGreaterThan(0);
    await expect(page.locator('meta[name="theme-color"]')).toHaveAttribute("content", "#a21caf");
});
