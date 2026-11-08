import { test, expect, type Page } from "./support/test";
import { deleteDraft } from "./support/compose";

/**
 * The compose window's chrome, as fixed after v0.4.2's first round on prod:
 * menus open over the button that opened them, the action bar is one row
 * that folds into ⋮, deleting a draft asks first, a minimized window is its
 * title bar, and the typeface menu does not change width with the choice.
 */

const DOCK = "#compose_dock";

async function openCompose(page: Page) {
    await page.goto("/mail/inbox");
    await page.getByRole("link", { name: "Compose" }).first().click();

    const editor = page.locator(DOCK).locator('[data-compose--compose-toolbar-target="editor"]');

    await expect(editor).toBeVisible();

    return { dock: page.locator(DOCK), editor };
}

test("the templates panel opens over its own button, not at the far right", async ({ page }) => {
    const { dock } = await openCompose(page);
    const button = dock.getByRole("button", { name: "Insert template" });

    await button.click();

    const panel = dock.locator('[data-compose--template-picker-target="panel"]');

    await expect(panel).toBeVisible();

    const b = (await button.boundingBox())!;
    const p = (await panel.boundingBox())!;

    // Above the button, and starting at it (or as near as the screen allows).
    expect(p.y + p.height).toBeLessThanOrEqual(b.y + 0.5);
    expect(p.x).toBeLessThanOrEqual(b.x + 0.5);
    expect(p.x + p.width).toBeGreaterThanOrEqual(b.x + b.width - 0.5);

    await page.keyboard.press("Escape");
    await expect(panel).toBeHidden();
});

test("the action bar is one row, with More options at its end holding what does not fit", async ({ page }) => {
    const { dock } = await openCompose(page);
    const bar = dock.locator('[data-controller~="compose--action-overflow"]');
    const more = dock.getByRole("button", { name: "More options", exact: true });

    const row = (await bar.boundingBox())!;
    const m = (await more.boundingBox())!;

    // Nothing after ⋮, and nothing below the row.
    expect(m.x + m.width).toBeLessThanOrEqual(row.x + row.width + 0.5);
    const lowest = await bar.evaluate((el) => Math.max(...[...el.querySelectorAll(":scope > *")]
        .filter((child) => child.getClientRects().length > 0)
        .map((child) => child.getBoundingClientRect().bottom)));
    expect(lowest).toBeLessThanOrEqual(row.y + row.height + 0.5);

    // Narrower: more of the row goes into the menu, and it still works.
    await page.setViewportSize({ width: 900, height: 720 });
    await page.evaluate(() => document.documentElement.style.setProperty("--compose-w", "380px"));
    await expect(dock.getByRole("button", { name: "Insert signature", exact: true })).toBeHidden();

    await more.click();
    await expect(dock.getByRole("menuitem", { name: "Insert signature" })).toBeVisible();
    await expect(dock.getByRole("menuitem", { name: "Delete draft" })).toBeVisible();
    await page.keyboard.press("Escape");
});

test("deleting a draft asks first, and Cancel keeps it", async ({ page }) => {
    const { dock, editor } = await openCompose(page);

    await editor.click();
    await editor.fill("keep me");
    await expect(dock.locator('[data-compose--compose-target="saveStatus"]')).toContainText("Draft saved");

    await dock.getByRole("button", { name: "More options", exact: true }).click();
    await dock.getByRole("menuitem", { name: "Delete draft" }).click();

    const dialog = page.locator("#confirm-dialog");

    await expect(dialog).toBeVisible();
    await expect(dialog).toContainText("Delete this draft?");
    await dialog.getByRole("button", { name: "Cancel" }).click();
    await expect(dialog).toBeHidden();
    await expect(editor).toHaveText("keep me");

    await deleteDraft(dock);
    await expect(editor).toBeHidden();
});

test("a minimized window is its title bar, and comes back at its size", async ({ page }) => {
    const { dock } = await openCompose(page);
    const window = dock.locator(".compose-window");
    const before = (await window.boundingBox())!.height;

    await dock.getByRole("button", { name: "Minimize" }).click();
    await expect.poll(async () => (await window.boundingBox())!.height).toBeLessThan(80);

    await dock.getByRole("button", { name: "Minimize" }).click();
    await expect.poll(async () => (await window.boundingBox())!.height).toBeCloseTo(before, 0);
});

/**
 * With "Serif" picked the control is narrow, and the panel used to be given
 * the control's width: "Sans serif" broke over two lines and "Monospace" was
 * cut off. The panel is as wide as its longest choice now — wider only when
 * the control itself is, as with the long label of "Monospace" picked.
 */
test("the typeface menu is as wide as its longest choice, whatever is picked", async ({ page }) => {
    const { dock } = await openCompose(page);
    // Tom Select's visible control, not the native <select> it hides.
    const typeface = dock.locator('[data-compose--compose-toolbar-target="fontFamily"] + .ts-wrapper .ts-control');
    const widths: number[] = [];
    const controls: number[] = [];

    for (const choice of ["Serif", "Monospace", "Sans serif"]) {
        await typeface.click();

        const panel = page.locator(".ts-dropdown.ts-dropdown-floating:visible");

        await expect(panel).toBeVisible();
        widths.push(Math.round((await panel.boundingBox())!.width));
        controls.push(Math.round((await typeface.boundingBox())!.width));

        // Every choice on one line.
        for (const option of await panel.locator(".option").all()) {
            expect((await option.boundingBox())!.height).toBeLessThan(40);
        }

        await panel.locator(".option", { hasText: new RegExp(`^${choice}$`) }).click();
    }

    // Sans serif picked, then Serif: the narrow control no longer narrows it.
    expect(widths[1]).toBe(widths[0]);
    widths.forEach((width, i) => expect(width).toBeGreaterThanOrEqual(controls[i] - 1));
});
