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

test("without a title bar, the top bar and sidebar meet the window's edges", async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 800 });
    await asTitlebarless(page);
    await page.goto("/mail/inbox");
    await settled(page);

    await expect(page.locator("html")).toHaveClass(/\bapp-titlebar\b/);

    const top = await box(page, ".shell-topbar");
    expect(top).toMatchObject({ left: 0, top: 0, right: 1280 });

    const side = await box(page, "#sidebar");
    expect(side).toMatchObject({ left: 0, top: top.bottom, bottom: 800 });

    // The content is still a card, a gutter in from the right and the bottom.
    const main = await box(page, ".main-pane");
    expect(main.right).toBeLessThan(1280);
    expect(main.bottom).toBeLessThan(800);
});

test("in a tab the shell keeps its gutter", async ({ page }) => {
    await page.setViewportSize({ width: 1280, height: 800 });
    await page.goto("/mail/inbox");
    await settled(page);

    await expect(page.locator("html")).not.toHaveClass(/\bapp-titlebar\b/);
    expect((await box(page, ".shell-topbar")).top).toBeGreaterThan(0);
});
