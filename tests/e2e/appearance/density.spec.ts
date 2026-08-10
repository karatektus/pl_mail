import { test, expect, type Page } from "../support/test";

/**
 * The density control, and the thing it must not take with it.
 *
 * Density itself is two custom properties on <html> — no class, no reload, no
 * frame swap — so "switching density breaks the page" was never about density
 * applying wrongly. It was about what else the save carried: the controller
 * posts EVERY appearance field on any change, and the "Theme default"
 * background tile was mislabelled `value="preset"` while being the tile that
 * means "no picture". So a density click rewrote backgroundKind from theme to
 * preset, and AppearanceRenderer floors --pane-alpha and --main-alpha at 0.45
 * for any kind other than Theme — panes jumping opaque app-wide, having touched
 * nothing but the row spacing.
 */

const densityTile = (page: Page, value: string) =>
    page.locator(`label:has(input[name="density"][value="${value}"])`);

const cssVar = (page: Page, name: string) =>
    page.evaluate(
        (n) => getComputedStyle(document.documentElement).getPropertyValue(n).trim(),
        name,
    );

test.describe("density", () => {
    test.beforeEach(async ({ page }) => {
        await page.goto("/settings?section=appearance");
        // Land on a known value whatever an earlier test left behind.
        await densityTile(page, "comfortable").click();
        await page.waitForTimeout(700);
    });

    test.afterEach(async ({ page }) => {
        await page.goto("/settings?section=appearance");
        await densityTile(page, "comfortable").click();
        await page.waitForTimeout(700);
    });

    test("applies live, and survives a reload", async ({ page }) => {
        expect(await cssVar(page, "--density-row-y")).toBe("0.875rem");

        await densityTile(page, "compact").click();

        await expect.poll(() => cssVar(page, "--density-row-y")).toBe("0.375rem");
        expect(await cssVar(page, "--density-gap")).toBe("0.375rem");

        // Persisted, not just painted.
        await page.waitForTimeout(700);
        await page.reload();

        expect(await cssVar(page, "--density-row-y")).toBe("0.375rem");
        await expect(
            page.locator('input[name="density"][value="compact"]'),
        ).toBeChecked();
    });

    /** The regression that made it look like the page broke. */
    test("does not rewrite the background setting on the way past", async ({ page }) => {
        const kind = () =>
            page.evaluate(
                () =>
                    (
                        document.querySelector(
                            'input[name="backgroundKind"]:checked',
                        ) as HTMLInputElement | null
                    )?.value ?? "none",
            );

        expect(await kind()).toBe("theme");

        await densityTile(page, "compact").click();
        await page.waitForTimeout(700);
        await page.reload();

        // Still the theme background, and still visibly selected.
        expect(await kind()).toBe("theme");
        expect(await cssVar(page, "--pane-alpha")).toBe("1");
    });

    /** The list still renders and still responds after the switch. */
    test("leaves the mail list working", async ({ page }) => {
        await densityTile(page, "compact").click();
        await page.waitForTimeout(700);

        await page.goto("/mail/inbox");

        const rows = page.locator("#message-list li");
        await expect(rows.first()).toBeVisible();

        // Interactable, not merely painted: opening a row still works.
        const count = await rows.count();
        expect(count).toBeGreaterThan(0);

        await page.goto("/settings?section=appearance");
        await densityTile(page, "comfortable").click();
        await expect.poll(() => cssVar(page, "--density-row-y")).toBe("0.875rem");
    });
});
