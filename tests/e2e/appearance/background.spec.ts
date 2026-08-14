import { test, expect, type Page } from "../support/test";

/**
 * Choosing a background applies it, and applies the same one a reload would.
 *
 * Both halves are load-bearing and they are different claims. The reported bug
 * was the first: --app-bg was written in exactly one place in
 * settings--appearance, the upload path, so a preset, a colour or a return to
 * the theme changed nothing on screen until the page was reloaded. The setting
 * saved correctly throughout, which is why it read as "backgrounds need a
 * reload" rather than as a control that did not work.
 *
 * The second is what stops the fix being worse than the bug. A live preview
 * that paints its own idea of the value looks fixed and disagrees with the
 * server on the next load — the failure the controller's own docblock warns
 * about. So every case here is asserted twice: what the page shows immediately,
 * and what it shows after a reload, compared as COMPUTED values so a difference
 * in spelling cannot hide a difference in result.
 *
 * The pane opacity floor rides along with the background (AppearanceRenderer
 * raises it to 0.45 for any kind but Theme, because panel text over a
 * photograph is unreadable below that), so it is part of "the same background"
 * and is checked in the same breath.
 */

const computedBackground = (page: Page) =>
    page.evaluate(() =>
        getComputedStyle(document.querySelector(".app-bg") as HTMLElement).backgroundImage,
    );

const paneAlpha = (page: Page) =>
    page.evaluate(() =>
        getComputedStyle(document.documentElement).getPropertyValue("--pane-alpha").trim(),
    );

/** What the page paints now, and what it paints after a reload. */
const liveThenReload = async (page: Page) => {
    const live = { background: await computedBackground(page), alpha: await paneAlpha(page) };

    // The controller debounces its save; the reload has to come after it.
    await page.waitForTimeout(800);
    await page.reload();

    return {
        live,
        reloaded: { background: await computedBackground(page), alpha: await paneAlpha(page) },
    };
};

test.describe("background", () => {
    test.beforeEach(async ({ page }) => {
        const reset = await page.request.post("/settings/appearance/reset");
        expect(reset.ok()).toBe(true);
        await page.goto("/settings?section=appearance");
    });

    test.afterAll(async ({ browser }) => {
        const page = await browser.newPage();
        await page.request.post("/settings/appearance/reset");
        await page.close();
    });

    test("a preset applies live, and matches the reload", async ({ page }) => {
        const before = await computedBackground(page);

        await page.locator('label:has(input[name="backgroundPreset"][value="dunes"])').click();

        const { live, reloaded } = await liveThenReload(page);

        expect(live.background).not.toBe(before);
        expect(live.background).toContain("dunes");
        expect(live.background).toBe(reloaded.background);
    });

    test("a solid colour applies live, and matches the reload", async ({ page }) => {
        await page.evaluate(() => {
            const input = document.querySelector(
                '[data-settings--appearance-field="backgroundSolid"]',
            ) as HTMLInputElement;

            input.value = "#3366cc";
            input.dispatchEvent(new Event("input", { bubbles: true }));
        });

        const { live, reloaded } = await liveThenReload(page);

        // A flat colour is a gradient from itself to itself — `.app-bg` paints
        // through background-image, which cannot take a colour.
        expect(live.background).toBe("linear-gradient(rgb(51, 102, 204), rgb(51, 102, 204))");
        expect(live.background).toBe(reloaded.background);

        // Picking a colour picks the kind, or the next save would post the
        // colour under a kind that ignores it.
        await expect(page.locator('input[name="backgroundKind"][value="solid"]')).toBeChecked();
    });

    test("going back to the theme applies live, and matches the reload", async ({ page }) => {
        await page.locator('label:has(input[name="backgroundPreset"][value="fog"])').click();
        await page.waitForTimeout(800);

        await page.locator('label:has(input[name="backgroundKind"][value="theme"])').click();

        const { live, reloaded } = await liveThenReload(page);

        // The theme's own gradient, which means --app-bg REMOVED rather than
        // set: AppearanceRenderer omits it entirely for BackgroundKind::Theme
        // so the stylesheet block for whichever theme is on <html> answers.
        // Writing a theme's gradient inline instead would look identical until
        // the theme changed underneath it.
        expect(live.background).not.toContain("fog");
        expect(live.background).toBe(reloaded.background);

        const inline = await page.evaluate(() =>
            document.documentElement.style.getPropertyValue("--app-bg"),
        );
        expect(inline).toBe("");
    });

    test("the opacity floor arrives and leaves with the background", async ({ page }) => {
        await page.evaluate(() => {
            const slider = document.querySelector(
                '[data-settings--appearance-field="paneAlpha"]',
            ) as HTMLInputElement;

            slider.value = "0.2";
            slider.dispatchEvent(new Event("input", { bubbles: true }));
        });
        await page.waitForTimeout(800);

        expect(await paneAlpha(page)).toBe("0.2");

        await page.locator('label:has(input[name="backgroundPreset"][value="fog"])').click();
        const withPicture = await liveThenReload(page);

        expect(withPicture.live.alpha).toBe("0.45");
        expect(withPicture.live.alpha).toBe(withPicture.reloaded.alpha);

        await page.locator('label:has(input[name="backgroundKind"][value="theme"])').click();
        const withoutPicture = await liveThenReload(page);

        // And the user's own number comes back rather than being stuck at the
        // floor — the floor belongs to the background, not to the slider.
        expect(withoutPicture.live.alpha).toBe("0.2");
        expect(withoutPicture.live.alpha).toBe(withoutPicture.reloaded.alpha);
    });

    /**
     * The scrim was NOT part of the reported fault, and this pins that down
     * rather than assuming it either way: it rides `slide()` like every other
     * glass slider, so it always did apply on the spot.
     */
    test("the scrim applies live, as it already did", async ({ page }) => {
        await page.evaluate(() => {
            const slider = document.querySelector(
                '[data-settings--appearance-field="scrimAlpha"]',
            ) as HTMLInputElement;

            slider.value = "0.5";
            slider.dispatchEvent(new Event("input", { bubbles: true }));
        });

        // Painted, not merely stored: the scrim is a ::before on .app-bg.
        await expect
            .poll(() =>
                page.evaluate(
                    () =>
                        getComputedStyle(
                            document.querySelector(".app-bg") as HTMLElement,
                            "::before",
                        ).backgroundColor,
                ),
            )
            .toBe("rgba(0, 0, 0, 0.5)");

        await page.waitForTimeout(800);
        await page.reload();

        expect(
            await page.evaluate(() =>
                getComputedStyle(document.documentElement).getPropertyValue("--scrim-alpha").trim(),
            ),
        ).toBe("0.5");
    });
});
