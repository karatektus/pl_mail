import { test, expect, type Page } from "../support/test";

/**
 * The appearance settings a list wears: per-surface density and the
 * interface text scale.
 *
 * The list's own settings — the account corner, sender discs, preview, unread
 * emphasis and the rest — were in this file while they were on the Appearance
 * page. They are Settings → Inbox → Message list since #608, and their spec is
 * settings-message-list.spec.ts.
 */

const PANEL = '[data-controller="settings--appearance"]';

/** The value the page is painted with, not the one in the form. */
const cssVar = (page: Page, name: string) =>
    page.evaluate(
        (n) => getComputedStyle(document.documentElement).getPropertyValue(n).trim(),
        name,
    );

const open = async (page: Page) => {
    await page.goto("/settings/appearance");
    await expect(page.locator(PANEL)).toBeVisible();
};

/**
 * A segmented control's option, reached by its LABEL.
 *
 * The radios are `peer sr-only` — 1px boxes behind the visible pill — so a
 * click lands on the label, which is what a person clicks too. The density
 * spec next door does the same for the same reason.
 */
const option = (page: Page, name: string, value: string) =>
    page.locator(`${PANEL} label:has(input[name="${name}"][value="${value}"])`);

const pick = async (page: Page, name: string, value: string) => {
    await option(page, name, value).click();
    await expect(
        page.locator(`${PANEL} input[name="${name}"][value="${value}"]`),
    ).toBeChecked();
};

/**
 * Per-surface density: a dense list beside a comfortable reading pane.
 *
 * The list and the reading pane are one painted surface, so the only knob that
 * can honestly differ between them is the padding inside their rows — which is
 * what this measures, on the resolved tokens rather than on the radios.
 */
test.describe("appearance — per-surface density", () => {
    test.afterEach(async ({ page }) => {
        await open(page);
        await pick(page, "listDensity", "");
        await pick(page, "readingDensity", "");
        await pick(page, "density", "comfortable");
        await page.waitForTimeout(700);
    });

    test("a surface can differ from the global density, and reverts to following", async ({ page }) => {
        await open(page);

        await pick(page, "density", "comfortable");
        await expect.poll(() => cssVar(page, "--surface-list-row-y")).toBe("0.625rem");
        expect(await cssVar(page, "--surface-reading-row-y")).toBe("1rem");

        await pick(page, "listDensity", "compact");

        // The list tightens; the reading pane does not move.
        await expect.poll(() => cssVar(page, "--surface-list-row-y")).toBe("0.25rem");
        expect(await cssVar(page, "--surface-reading-row-y")).toBe("1rem");

        // Persisted on the user, not painted in this tab.
        await page.waitForTimeout(700);
        await page.reload();
        expect(await cssVar(page, "--surface-list-row-y")).toBe("0.25rem");

        // Back to following, and the global value takes over again.
        await pick(page, "listDensity", "");
        await expect.poll(() => cssVar(page, "--surface-list-row-y")).toBe("0.625rem");
    });

    /** A following surface moves when the global control does. */
    test("a following surface tracks the global density", async ({ page }) => {
        await open(page);

        await pick(page, "readingDensity", "");
        await pick(page, "density", "compact");

        await expect.poll(() => cssVar(page, "--surface-reading-row-y")).toBe("0.5rem");
    });
});

/**
 * The interface text scale, and the thing it must not touch.
 *
 * The compose editor has its own font size, which goes out with the message.
 * If the UI scale moved it, writing at 1.25 would send mail 25% smaller than it
 * looked while being written and nothing would ever have said so.
 */
test.describe("appearance — typography", () => {
    test.afterEach(async ({ page }) => {
        await open(page);
        await page.locator(`${PANEL} input[data-css-variable="--app-font-scale"]`)
            .fill("1");
        await page.locator(`${PANEL} input[data-css-variable="--app-font-scale"]`)
            .dispatchEvent("input");
        await page.waitForTimeout(700);
    });

    test("the scale moves the root and leaves the compose editor alone", async ({ page }) => {
        await open(page);

        const rootSize = () =>
            page.evaluate(() => getComputedStyle(document.documentElement).fontSize);

        expect(await rootSize()).toBe("16px");

        const slider = page.locator(`${PANEL} input[data-css-variable="--app-font-scale"]`);
        await slider.fill("1.25");
        await slider.dispatchEvent("input");

        await expect.poll(rootSize).toBe("20px");

        await page.waitForTimeout(700);
        await page.goto("/mail/inbox");

        // The root carries the scale everywhere…
        expect(await rootSize()).toBe("20px");

        // …and the compose editor is pinned in px, outside it.
        await page.getByRole("link", { name: /compose|schreiben/i }).first().click();
        const editor = page.locator('[data-compose--compose-toolbar-target="editor"]');
        await expect(editor).toBeVisible();

        expect(await editor.evaluate((el) => getComputedStyle(el).fontSize)).toBe("14px");
    });
});
