import { test, expect, type Page } from "../support/test";

/**
 * Layout is the second appearance axis: the theme picks the palette, the
 * layout picks how it is painted. Flat — the default — drops the card from
 * the topbar and sidebar so they sit straight on the background, leaving the
 * main pane as the only box. Boxed floats every panel.
 *
 * Picking a layout also seeds its glass knobs, so these specs check that the
 * sliders move with it and that a manual change afterwards still wins.
 *
 * Runs authenticated as this worker's own user, signed in by the worker
 * fixture in support/test.ts.
 */

const APPEARANCE = "/settings?section=appearance";
const TRANSPARENT = "rgba(0, 0, 0, 0)";

/**
 * The layout control, which is a segmented radio group rather than a <select>.
 *
 * It was the last dropdown on a page where every other short choice — motion,
 * density, preview lines, unread emphasis — is a segmented group, so it was
 * converted to match. The radios are `sr-only` with a styled <span> beside
 * them, so these act on the input directly rather than clicking the label.
 */
const layoutRadio = (page: Page, value: "flat" | "boxed") =>
    page.locator(`input[data-settings--appearance-field="layout"][value="${value}"]`);

/**
 * The clickable half of the segment.
 *
 * The input is `sr-only`, so acting on it directly does not work — Playwright
 * clicks it and the browser reports the state unchanged. The <label> wrapping
 * it is what a person clicks and what actually toggles the radio, which is the
 * same pattern density.spec.ts uses for the identical control shape.
 */
const layoutTile = (page: Page, value: "flat" | "boxed") =>
    page.locator(`label:has(input[data-settings--appearance-field="layout"][value="${value}"])`);

const flat = (page: Page) =>
    page.evaluate(() =>
        document.documentElement.classList.contains("layout-flat"),
    );

/** Waits out the appearance controller's debounced save. */
async function pickLayout(page: Page, value: "flat" | "boxed"): Promise<void> {
    const save = page.waitForResponse(
        (response) =>
            response.url().includes("/settings/appearance") &&
            response.request().method() === "POST",
    );

    await layoutTile(page, value).click();
    await save;
}

test.beforeEach(async ({ page }) => {
    // Layout is persisted per user, so restore the defaults before each test.
    // Straight to the endpoint the Reset control posts to: driving the button
    // would race the Stimulus controller's connect on a freshly loaded page.
    const reset = await page.request.post("/settings/appearance/reset");
    expect(reset.ok()).toBe(true);

    await page.goto(APPEARANCE);
    await expect(layoutRadio(page, "flat")).toBeChecked();
});

test.describe("layout", () => {
    test("defaults to flat", async ({ page }) => {
        await page.goto("/mail/inbox");

        expect(await flat(page)).toBe(true);
        await expect(page.locator("header.shell-chrome")).toHaveCSS(
            "background-color",
            TRANSPARENT,
        );
    });

    test("boxed gives the topbar and sidebar their card back", async ({
        page,
    }) => {
        await pickLayout(page, "boxed");
        await page.goto("/mail/inbox");

        expect(await flat(page)).toBe(false);

        const header = page.locator("header.shell-chrome");
        await expect(header).not.toHaveCSS("background-color", TRANSPARENT);
        await expect(header).not.toHaveCSS("box-shadow", "none");
    });

    test("survives a reload, rendered server-side", async ({ page }) => {
        await pickLayout(page, "boxed");

        await page.goto("/mail/inbox");
        expect(await flat(page)).toBe(false);

        await page.goto(APPEARANCE);
        await pickLayout(page, "flat");

        await page.goto("/mail/inbox");
        expect(await flat(page)).toBe(true);
    });

    test("seeds its glass knobs, and a manual change still wins", async ({
        page,
    }) => {
        const opacity = page.locator('input[data-settings--appearance-field="paneAlpha"]');
        const blur = page.locator('input[data-settings--appearance-field="paneBlur"]');

        await pickLayout(page, "boxed");
        await expect(opacity).toHaveValue("0.7");
        await expect(blur).toHaveValue("24");

        await pickLayout(page, "flat");
        await expect(opacity).toHaveValue("1");
        await expect(blur).toHaveValue("0");

        // An explicit tweak after the preset must not be clobbered by it.
        const save = page.waitForResponse(
            (response) =>
                response.url().includes("/settings/appearance") &&
                response.request().method() === "POST",
        );
        await blur.evaluate((slider: HTMLInputElement) => {
            slider.value = "12";
            slider.dispatchEvent(new Event("input", { bubbles: true }));
        });
        await save;

        await page.reload();
        await expect(blur).toHaveValue("12");
        expect(await flat(page)).toBe(true);
    });

    test("keeps the mobile drawer opaque, whatever the layout", async ({
        page,
    }) => {
        await page.setViewportSize({ width: 420, height: 900 });
        await page.goto("/mail/inbox");

        // Flat strips the inline sidebar's card but must not touch the drawer
        // copy — it floats over a dim backdrop and would be unreadable.
        expect(await flat(page)).toBe(true);
        await expect(page.locator("#sidebar-drawer-inner")).not.toHaveClass(
            /shell-chrome/,
        );
        await expect(page.locator("#sidebar-drawer-inner")).not.toHaveCSS(
            "background-color",
            TRANSPARENT,
        );
    });
});
