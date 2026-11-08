import { test, expect, type Page } from "./support/test";

/**
 * The docked compose window takes the size it is dragged to, and keeps it on
 * this device (#507): in localStorage, put on <html> before the first paint.
 */

test.use({ viewport: { width: 1400, height: 1000 } });

async function openCompose(page: Page) {
    await page.goto("/mail/inbox");
    await page.getByRole("link", { name: "Compose" }).first().click();

    const window = page.locator("#compose_dock .compose-window");
    await expect(window).toBeVisible();

    return window;
}

async function size(page: Page) {
    const box = (await page.locator("#compose_dock .compose-window").boundingBox())!;

    return [Math.round(box.width), Math.round(box.height)];
}

async function kept(page: Page) {
    return page.evaluate(() => localStorage.getItem("plmail:composeSize"));
}

test.beforeEach(async ({ page }) => {
    await page.goto("/mail/inbox");
    await page.evaluate(() => localStorage.removeItem("plmail:composeSize"));
});

test("dragging the corner resizes the window, and the size is kept on this device", async ({ page }) => {
    const window = await openCompose(page);
    const handle = window.getByRole("button", { name: "Resize the compose window" });

    expect(await size(page)).toEqual([520, 600]);

    // Up and to the left is bigger: the dock is anchored bottom-right.
    const grip = (await handle.boundingBox())!;
    await page.mouse.move(grip.x + grip.width / 2, grip.y + grip.height / 2);
    await page.mouse.down();
    await page.mouse.move(grip.x + grip.width / 2 - 200, grip.y + grip.height / 2 - 100, { steps: 5 });
    await page.mouse.up();

    expect(await size(page)).toEqual([720, 700]);
    expect(JSON.parse((await kept(page))!)).toEqual({ w: 720, h: 700 });

    // A reload, and a new window, open at that size.
    await page.reload();
    expect(await page.evaluate(() => document.documentElement.style.getPropertyValue("--compose-w"))).toBe("720px");
    await openCompose(page);
    expect(await size(page)).toEqual([720, 700]);

    // Double-click: the standard size, and nothing kept.
    await page.locator("#compose_dock .compose-window").getByRole("button", { name: "Resize the compose window" }).dblclick();
    expect(await size(page)).toEqual([520, 600]);
    expect(await kept(page)).toBeNull();
});

test("the window cannot be made smaller than it can be used", async ({ page }) => {
    const window = await openCompose(page);
    const grip = (await window.getByRole("button", { name: "Resize the compose window" }).boundingBox())!;

    await page.mouse.move(grip.x + grip.width / 2, grip.y + grip.height / 2);
    await page.mouse.down();
    await page.mouse.move(grip.x + 600, grip.y + 600, { steps: 5 });
    await page.mouse.up();

    expect(await size(page)).toEqual([420, 360]);
});
