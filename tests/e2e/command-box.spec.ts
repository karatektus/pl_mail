import type { Locator, Page } from "@playwright/test";
import { test, expect } from "./support/test";
import { seed } from "./support/config";

/**
 * ⌘K / Ctrl+K as a command box (FLT-154): beside the settings it always found,
 * the keyboard shortcuts' actions, run on what their key would act on, and
 * the places the sidebar goes to. Each row shows its key.
 *
 * The actions are offered only where they would do something, so what is
 * asserted is both halves: a command that runs, and one that is not offered.
 */
const ROWS = "#message-list li[data-mail--message-row-id-value]";
const CURSOR = `${ROWS}[data-kbd-cursor]`;

const palette = (page: Page): Locator => page.locator("#settings-palette");

/** The results whose keys are exactly these, each a letter. */
const withKeys = (page: Page, ...keys: string[]): Locator =>
    palette(page).getByRole("option").filter({
        has: page.locator("[data-part='keys']", { hasText: new RegExp("^" + keys.join("\\s*") + "$") }),
    });

async function inbox(page: Page): Promise<void> {
    await page.goto("/mail/inbox");
    await expect(page.locator(ROWS).first()).toBeVisible();
    await expect(page.locator('body[data-controller~="ui--shortcuts"]')).toHaveCount(1);
}

async function command(page: Page, text: string): Promise<void> {
    await page.keyboard.press("ControlOrMeta+k");
    await expect(palette(page)).toBeVisible();
    await palette(page).getByRole("combobox").fill(text);
}

test.beforeEach(() => {
    seed("seed-mail");
});

test.describe("the command box", () => {
    test("archive runs on the highlighted row, as e would", async ({ page }) => {
        await inbox(page);

        const before = await page.locator(ROWS).count();

        await page.keyboard.press("j");
        await expect(page.locator(CURSOR)).toHaveCount(1);

        await command(page, "archive");

        const first = palette(page).getByRole("option").first();
        await expect(first).toContainText("Archive");
        await expect(first.locator("kbd")).toHaveText(["e"]);

        await page.keyboard.press("Enter");

        await expect(palette(page)).toBeHidden();
        await expect(page.locator(ROWS)).toHaveCount(before - 1);
    });

    test("a reply is offered only with a conversation open", async ({ page }) => {
        await inbox(page);

        await command(page, "reply");
        // Rendered: the status says how many matched, or that nothing did.
        await expect(palette(page).locator("[data-ui--settings-palette-target='status']")).not.toHaveText("");
        await expect(withKeys(page, "r")).toHaveCount(0);

        // A search field's first Escape clears it (Chromium); the next closes the dialog.
        await page.keyboard.press("Escape");
        await expect(palette(page).getByRole("combobox")).toHaveValue("");
        await page.keyboard.press("Escape");
        await expect(palette(page)).toBeHidden();

        await page.keyboard.press("j");
        await page.keyboard.press("o");
        await expect(page.locator('[data-surface="reading"]')).toBeVisible();

        await command(page, "reply");
        await expect(withKeys(page, "r")).toHaveCount(1);
    });

    test("a folder is found by name, shows its g key and goes there", async ({ page }) => {
        await inbox(page);

        await command(page, "starred");

        const starred = withKeys(page, "g", "s").first();
        await expect(starred).toContainText("Starred");

        await starred.click();
        await expect(page).toHaveURL(/\/mail\/starred/);
    });

    test("settings are still found", async ({ page }) => {
        await inbox(page);

        await command(page, "2fa");
        await expect(palette(page).getByRole("option").first()).toContainText("Two-factor");
    });
});
