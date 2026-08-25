import { test, expect, type Page } from "../support/test";
import { readFileSync } from "node:fs";

/**
 * Switching a theme lands where reloading into it would.
 *
 * The appearance panel is a live preview: picking a theme rewrites `data-theme`
 * on <html>, toggles `.dark`, and writes some values inline from JavaScript.
 * Three mechanisms, and only one of them — the attribute — actually *unsets*
 * the previous theme. Anything written inline stays written, and anything a
 * theme block declined to declare falls through to whatever :root or .dark
 * happens to say. So the failure this catches is a remnant: a value the old
 * theme put there that the new theme never asked for and never overwrote.
 *
 * It is checked as computed values rather than as pixels on purpose. A
 * screenshot comparison answers "do these look the same", which is the
 * question, but it answers it with a number nobody can act on when it fails.
 * The whole palette, variable by variable, says WHICH channel drifted — and
 * the palette is the entire difference between two themes, so if every channel
 * agrees the pixels do too.
 *
 * The inventory is read out of app.css rather than listed here, so a channel
 * added to the stylesheet is covered by this test the day it is added.
 * ThemeVariableCompletenessTest is what guarantees the :root block it is read
 * from is the complete set.
 */

const PANEL = '[data-controller="settings--appearance"]';

/**
 * The user's knobs, which are not the theme's and must NOT be compared: they
 * are sliders the user owns, AppearanceRenderer writes them inline from stored
 * settings, and picking a theme is not supposed to move them. Kept in step with
 * the KNOBS list in ThemeVariableCompletenessTest.
 */
const KNOBS = new Set([
    "--rgb-main",
    "--main-alpha",
    "--pane-alpha",
    "--pane-blur",
    "--app-radius",
    "--density-row-y",
    "--density-gap",
    "--scrim-alpha",
    "--pane-header-h",
]);

/** Every palette channel, read off the :root block of the stylesheet. */
function inventory(): string[] {
    const css = readFileSync("assets/styles/app.css", "utf8").replace(/\/\*[\s\S]*?\*\//g, "");
    const root = css.slice(css.indexOf(":root {"));
    const body = root.slice(0, root.indexOf("\n}"));

    return [...body.matchAll(/(--[a-z0-9-]+)\s*:/g)]
        .map((match) => match[1])
        .filter((name) => !KNOBS.has(name));
}

/**
 * Everything about how the page is painted that a theme controls.
 *
 * The class list and color-scheme are in here beside the variables because
 * they are the two parts a switch gets wrong without touching a single
 * variable: `.dark` drives every Tailwind `dark:` utility, and color-scheme is
 * the only channel that reaches the browser's own popups and scrollbars.
 */
async function painted(page: Page, names: string[]): Promise<Record<string, string>> {
    return page.evaluate((variables) => {
        const root = document.documentElement;
        const style = getComputedStyle(root);
        const state: Record<string, string> = {
            "html.class": [...root.classList].sort().join(" "),
            "color-scheme": style.colorScheme,
        };

        for (const name of variables) {
            state[name] = style.getPropertyValue(name).trim().replace(/\s+/g, " ");
        }

        return state;
    }, names);
}

async function open(page: Page): Promise<void> {
    await page.goto("/settings?section=appearance");
    await expect(page.locator(PANEL)).toBeVisible();
}

/** Click a theme and wait for the debounced save to actually land. */
async function pick(page: Page, theme: string): Promise<void> {
    const tile = page.locator(`${PANEL} [data-theme-name="${theme}"]`);

    // The logo themes sit behind the "More" tile until it is pressed once per
    // page visit (the currently selected one is rendered visible). A hidden
    // tile cannot be clicked, so reveal the full set first.
    if (!(await tile.isVisible())) {
        await page
            .locator(`${PANEL} [data-settings--appearance-target="moreThemesButton"]`)
            .click();
    }

    const saved = page.waitForResponse(
        (response) => response.url().includes("/appearance") && response.request().method() === "POST",
    );

    await tile.click();
    await saved;
}

/**
 * Choose a layout.
 *
 * This used to drive a Tom Select widget, because the control was a <select>
 * that ui--select had replaced — so acting on the native element changed the
 * value without changing what the page showed. The control is now a segmented
 * radio group like every other short choice on the panel, and there is no
 * widget in between: the radio IS the control, and checking it is what a
 * person does.
 */
async function setLayout(page: Page, layout: string): Promise<void> {
    const radio = page.locator(
        `${PANEL} input[data-settings--appearance-field="layout"][value="${layout}"]`,
    );

    // Re-picking the selected option fires no change, so there is no save to
    // wait for and nothing to do.
    if (await radio.isChecked()) {
        return;
    }

    const saved = page.waitForResponse(
        (response) => response.url().includes("/appearance") && response.request().method() === "POST",
    );

    // The wrapping <label>, not the input: the radio is sr-only, and clicking
    // it directly leaves the state unchanged. Same as density.spec.ts.
    await page
        .locator(`${PANEL} label:has(input[data-settings--appearance-field="layout"][value="${layout}"])`)
        .click();
    await saved;
}

async function themeNames(page: Page): Promise<string[]> {
    return page.locator(`${PANEL} [data-theme-name]`).evaluateAll(
        (buttons) => buttons.map((button) => (button as HTMLElement).dataset.themeName ?? ""),
    );
}

test.describe("appearance — a switched theme equals a loaded one", () => {
    /**
     * These are matrix tests, and the matrix is the theme catalogue: every one
     * of them walks all 41 themes twice — once through the picker and once
     * through a cold reload — which is ~40 full page loads and ~80 saves each.
     * They measure a little over 45s apiece locally against Playwright's 30s
     * default, and the way that failed was misleading: the budget expired
     * mid-navigation and the error read `page.reload: net::ERR_ABORTED; maybe
     * frame was detached?`, which looks like a bug in the app rather than a
     * stopwatch running out.
     *
     * The breadth is the point — a theme is covered here the day it is added,
     * and that is what caught the remnants these tests exist for — so the
     * budget moves rather than the coverage. Generous enough for CI, which
     * runs on two vCPUs.
     */
    test.describe.configure({ timeout: 150_000 });

    test.afterEach(async ({ page }) => {
        await open(page);
        await page.locator(PANEL).getByRole("button", { name: /reset/i }).first().click();
        await page.waitForResponse((r) => r.url().includes("/appearance/reset")).catch(() => {});
    });

    /**
     * The sequence matters. Each theme is switched to from the one before it,
     * never from a fresh page, because that is the only way a remnant can
     * exist: it takes a previous theme to leave one. Picking each theme after
     * a reload would pass with the bug still in place.
     */
    test("every theme, switched to in sequence, paints what a reload paints", async ({ page }) => {
        const variables = inventory();

        expect(variables.length, "the stylesheet should declare a palette").toBeGreaterThan(20);

        await open(page);

        const themes = await themeNames(page);

        expect(themes.length).toBeGreaterThan(1);

        // Pass one: straight through the picker, no reload anywhere.
        const switched: Record<string, Record<string, string>> = {};

        for (const theme of themes) {
            await pick(page, theme);
            switched[theme] = await painted(page, variables);
        }

        // Pass two: each theme as the server renders it into a cold document.
        for (const theme of themes) {
            await pick(page, theme);
            await page.reload();
            await expect(page.locator(PANEL)).toBeVisible();

            expect(
                await painted(page, variables),
                `theme "${theme}" paints differently when switched to than when loaded`,
            ).toEqual(switched[theme]);
        }
    });

    /**
     * And again under the other layout.
     *
     * Boxed rather than flat, because flat is the default a reset leaves
     * behind (Appearance::$layout) — a test that picks it is asserting against
     * the state it started in, which is how the first version of this passed
     * without exercising anything. Boxed is also the one with pane fills to
     * lose, so it is the variant where a leaked --pane-alpha or --rgb-border
     * would actually show.
     */
    test("the same holds under the boxed layout", async ({ page }) => {
        const variables = inventory();

        await open(page);
        await setLayout(page, "boxed");

        expect(
            await page.evaluate(() => document.documentElement.classList.contains("layout-flat")),
            "boxed should have dropped the flat class",
        ).toBe(false);

        const themes = await themeNames(page);
        const switched: Record<string, Record<string, string>> = {};

        for (const theme of themes) {
            await pick(page, theme);
            switched[theme] = await painted(page, variables);
        }

        for (const theme of themes) {
            await pick(page, theme);
            await page.reload();
            await expect(page.locator(PANEL)).toBeVisible();

            expect(
                await painted(page, variables),
                `theme "${theme}" differs under the boxed layout`,
            ).toEqual(switched[theme]);

            expect(
                await page.evaluate(() => document.documentElement.classList.contains("layout-flat")),
                "the layout should survive a theme switch",
            ).toBe(false);
        }
    });

    /**
     * The reading pane is the one surface whose palette is NOT the theme's own
     * — it is light in every theme, because mail is authored for light. That
     * makes it the easiest place for a stale value to hide: nothing about it
     * looks wrong on a light theme, so a leak only shows on a dark one.
     */
    test("the mail sheet takes its colours from the theme, not from the last one", async ({ page }) => {
        await open(page);

        const sheet = [
            "--rgb-sheet",
            "--rgb-sheet-ink",
            "--rgb-sheet-ink-soft",
            "--rgb-sheet-ink-muted",
            "--rgb-sheet-ink-faint",
            "--rgb-sheet-link",
            "--rgb-sheet-danger",
        ];

        const themes = await themeNames(page);
        const seen: Record<string, string> = {};

        for (const theme of themes) {
            await pick(page, theme);
            seen[theme] = JSON.stringify(await painted(page, sheet));
        }

        for (const theme of themes) {
            await pick(page, theme);
            await page.reload();
            await expect(page.locator(PANEL)).toBeVisible();

            expect(
                JSON.stringify(await painted(page, sheet)),
                `the sheet under "${theme}" depends on which theme came before it`,
            ).toBe(seen[theme]);
        }

        // And it is genuinely per-theme rather than one hardcoded palette:
        // nord's sheet is its own snow storm, not the default warm cream.
        expect(new Set(Object.values(seen)).size, "every theme has the same sheet").toBeGreaterThan(1);
    });
});

/**
 * `system` on a machine set to dark.
 *
 * Its own describe block because the OS preference is browser-context state,
 * and it is the one case where "what the server renders" and "what the picker
 * does" ask different questions. The server cannot know the answer — it omits
 * the `dark` class and leaves a script in the page to add it — so the picker
 * has to ask the same question the script does. Reading data-dark instead gets
 * `0`, because Theme::isDark() says System is not dark, and picking System on
 * a dark desktop dropped the class and painted light until the next
 * navigation put it back.
 */
test.describe("appearance — system follows the desktop", () => {
    test.use({ colorScheme: "dark" });

    test.afterEach(async ({ page }) => {
        await open(page);
        await page.locator(PANEL).getByRole("button", { name: /reset/i }).first().click();
        await page.waitForResponse((r) => r.url().includes("/appearance/reset")).catch(() => {});
    });

    test("picking system on a dark desktop paints dark immediately", async ({ page }) => {
        const variables = inventory();

        await open(page);

        // From a light theme, so the class genuinely has to be added rather
        // than merely left alone.
        await pick(page, "solar");
        expect(await page.evaluate(() => document.documentElement.classList.contains("dark"))).toBe(false);

        await pick(page, "system");

        const switched = await painted(page, variables);

        expect(switched["html.class"].split(" "), "system should follow the dark desktop").toContain("dark");

        await page.reload();
        await expect(page.locator(PANEL)).toBeVisible();

        expect(await painted(page, variables)).toEqual(switched);
    });
});
