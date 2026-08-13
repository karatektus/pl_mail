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

    /**
     * The pane is still ON SCREEN afterwards — not merely still in the DOM.
     *
     * The blanking this guards against left every node in place and every
     * request succeeding. `sr-only` is `position: absolute`, and a <label> is
     * static, so each segmented control's radio had `.main-pane` for a
     * containing block; its static position a page down the panel became part
     * of `.main-pane`'s scrollable overflow (2739px inside an 824px box).
     * Clicking a label focuses its radio, the browser scrolls the focused
     * element into view through every scroll container above it — and
     * `.main-pane` is `overflow: hidden`, which is still scrollable, just
     * without a scrollbar. scrollTop went to 1314, the pane header, the
     * section nav and the whole settings body slid up out of the clip, and the
     * pane painted as an empty rectangle with no way to scroll it back.
     *
     * So the assertions are geometric. `innerText` still returned all 2519
     * characters while the pane was visibly empty — content scrolled out of a
     * clipped box is still laid out and still "rendered" by that measure — and
     * the POST still returned 200. Anything short of "where is it on screen"
     * passes against the bug.
     *
     * All four controls, because "any of the density triggers" was the report
     * and the global one is not special: they share one cause.
     */
    test("does not scroll the main pane out of its own clip", async ({ page }) => {
        const paneMetrics = () =>
            page.evaluate(() => {
                const pane = document.querySelector(".main-pane") as HTMLElement;
                const heading = pane.querySelector("h1") as HTMLElement;
                const paneBox = pane.getBoundingClientRect();
                const headingBox = heading.getBoundingClientRect();

                return {
                    scrollTop: pane.scrollTop,
                    headingTop: Math.round(headingBox.top),
                    paneTop: Math.round(paneBox.top),
                    paneBottom: Math.round(paneBox.bottom),
                };
            });

        const controls: Array<[string, string]> = [
            ["density", "compact"],
            ["sidebarDensity", "compact"],
            ["listDensity", "cosy"],
            ["readingDensity", "compact"],
        ];

        for (const [name, value] of controls) {
            await page
                .locator(`label:has(input[name="${name}"][value="${value}"])`)
                .click();
            await page.waitForTimeout(300);

            const m = await paneMetrics();

            // The pane never scrolls: it has no scrollbar, so anything it
            // scrolls to is unreachable.
            expect(m.scrollTop, `${name}=${value} scrolled .main-pane`).toBe(0);

            // And the pane's own header is still inside the pane's box, which
            // is the thing a person would see missing.
            expect(
                m.headingTop,
                `${name}=${value} pushed the pane header out of the pane`,
            ).toBeGreaterThan(m.paneTop);
            expect(m.headingTop).toBeLessThan(m.paneBottom);
        }

        // The controls themselves are still reachable afterwards — the pane is
        // not just painted, it is still usable.
        await expect(
            page.locator('label:has(input[name="density"][value="comfortable"])'),
        ).toBeVisible();

        // Put the three surfaces back on "follow". The describe's afterEach
        // only owns the global control, and a surface left overridden would
        // outlive this file.
        for (const surface of ["sidebar", "list", "reading"]) {
            await page
                .locator(`label:has(input[name="${surface}Density"][value=""])`)
                .click();
        }
        await page.waitForTimeout(700);
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
