import { test, expect, type Locator, type Page } from "./support/test";
import { deleteDraft } from "./support/compose";

/**
 * The compose window's buttons in an order of one's own: "Arrange buttons"
 * under ⋮, a drag or the arrow keys, Done — and the next window drawn that way.
 *
 * ComposeActionOrderTest pins what the server stores and renders; this pins
 * that the bar can actually be arranged by hand, that a press while arranging
 * moves rather than acts, and that what Done stored comes back.
 */

async function open(page: Page) {
    await page.goto("/mail/inbox");
    await page.getByRole("link", { name: "Compose" }).first().click();

    const dock = page.locator("#compose_dock");

    await expect(dock.locator('[data-compose--compose-toolbar-target="editor"]')).toBeVisible();

    return dock;
}

/** The arrangeable buttons' names, in the order they stand. */
async function order(dock: Locator): Promise<string[]> {
    return dock.locator('[data-controller~="compose--action-overflow"]').evaluate((row) =>
        Array.from(row.querySelectorAll<HTMLElement>(":scope > [data-compose-action]"))
            .sort((a, b) => Number(a.style.order) - Number(b.style.order))
            .map((item) => item.dataset.composeAction!),
    );
}

async function arrange(dock: Locator) {
    await dock.getByRole("button", { name: "More options", exact: true }).click();
    await dock.getByRole("button", { name: "Arrange buttons" }).click();
    await expect(dock.getByRole("dialog", { name: "Arrange buttons" })).toBeVisible();
}

/** Back to the default, so the other specs' windows look as they expect. */
async function reset(page: Page) {
    const dock = await open(page);

    await arrange(dock);
    await dock.getByRole("button", { name: "Reset", exact: true }).click();
    await dock.getByRole("button", { name: "Done", exact: true }).click();
    await expect(dock.getByRole("dialog", { name: "Arrange buttons" })).toBeHidden();
    await deleteDraft(dock);
}

test.afterEach(async ({ page }) => {
    await reset(page);
});

test("a button dragged and one moved with the arrow keys stay where they were put", async ({ page }) => {
    let dock = await open(page);

    expect((await order(dock)).slice(0, 3)).toEqual(["format", "attach", "link"]);

    await arrange(dock);

    // Every button on show while arranging, ⋮ out of the way.
    await expect(dock.getByRole("button", { name: "More options", exact: true })).toBeHidden();

    // A press moves, it does not act: Aa would open the formatting bar.
    const format = dock.locator('[data-compose-action="format"]');
    const formatBar = dock.locator('[data-compose--compose-target="formatBar"]');
    const barWasOpen = await formatBar.isVisible().catch(() => false);

    await format.click();
    expect(await formatBar.isVisible().catch(() => false)).toBe(barWasOpen);

    // Drag the paperclip onto Aa's left half: it goes first.
    const attach = (await dock.locator('[data-compose-action="attach"]').boundingBox())!;
    const target = (await format.boundingBox())!;

    await page.mouse.move(attach.x + attach.width / 2, attach.y + attach.height / 2);
    await page.mouse.down();
    await page.mouse.move(target.x + 2, target.y + target.height / 2, { steps: 8 });
    await page.mouse.up();

    expect((await order(dock)).slice(0, 3)).toEqual(["attach", "format", "link"]);

    // The link button, one place left with the keyboard.
    await dock.locator('[data-compose-action="link"]').focus();
    await page.keyboard.press("ArrowLeft");

    expect((await order(dock)).slice(0, 3)).toEqual(["attach", "link", "format"]);

    const saved = page.waitForResponse((response) => response.url().endsWith("/settings/compose/action-order"));

    await dock.getByRole("button", { name: "Done", exact: true }).click();
    expect((await saved).status()).toBe(204);
    await expect(dock.getByRole("button", { name: "More options", exact: true })).toBeVisible();
    await deleteDraft(dock);

    // The next window is drawn in that order by the server.
    dock = await open(page);
    expect((await order(dock)).slice(0, 3)).toEqual(["attach", "link", "format"]);
    await deleteDraft(dock);
});

test("Escape leaves the order as it was", async ({ page }) => {
    const dock = await open(page);
    const before = await order(dock);

    await arrange(dock);
    await dock.locator('[data-compose-action="discard"]').focus();
    await page.keyboard.press("ArrowLeft");
    expect(await order(dock)).not.toEqual(before);

    await page.keyboard.press("Escape");

    await expect(dock.getByRole("dialog", { name: "Arrange buttons" })).toBeHidden();
    expect(await order(dock)).toEqual(before);
    await deleteDraft(dock);
});
