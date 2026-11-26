import { test, expect } from "./support/test";
import type { Locator, Page } from "@playwright/test";
import { INBOX_SUBJECTS, mailRow, seed } from "./support/config";

/**
 * Typing into "Label as" and "Move to": the menus open with a filter field, the
 * arrows move through what it leaves, Enter picks and Escape closes. "Create
 * new" takes the typed text along as the new label's name.
 *
 * Driven from the keyboard on purpose, because that is the way in this exists
 * for: `l` and `v` on an open conversation, a few letters, Enter.
 */
const reading = (page: Page) => page.locator('[data-mail--mail-pane-target="reading"]');
const filter = (page: Page) => reading(page).getByRole("textbox", { name: "Type to filter" }).locator("visible=true");

/** The keyboard's highlight is the bare `bg-hover` class; `hover:bg-hover` is the mouse's and always there. */
const highlighted = (entry: Locator) => entry.evaluate((element) => element.classList.contains("bg-hover"));

async function openConversation(page: Page): Promise<void> {
    await page.goto("/mail/inbox");
    await mailRow(page, INBOX_SUBJECTS.read).click();
    await expect(reading(page)).toBeVisible();
    await expect(page.locator('body[data-controller~="ui--shortcuts"]')).toHaveCount(1);
}

test.beforeEach(() => {
    seed("seed-mail", "seed-label", "seed-label --folder");
});

test.describe("label menu filter", () => {
    test("l, a few letters, the arrows and Enter put the label on; Escape closes", async ({ page }) => {
        await openConversation(page);

        const panel = reading(page).locator('[data-mail--label-menu-target="panel"]');
        const entry = panel.locator("[data-label-id]", { hasText: "E2E Label" });
        const create = panel.locator('[data-mail--label-menu-target="create"]');

        await page.keyboard.press("l");
        await expect(panel).toBeVisible();
        await expect(filter(page)).toBeFocused();
        await expect(entry).toHaveAttribute("data-attached", "false");

        await page.keyboard.type("e2e lab");

        // Narrowed to what matches, with the keyboard on the first of it.
        await expect(entry).toBeVisible();
        await expect(panel.locator("[data-label-id]:not(.hidden)")).toHaveCount(1);
        await expect.poll(() => highlighted(entry)).toBe(true);

        // Down to Create new and back up: Enter goes where the highlight is.
        await page.keyboard.press("ArrowDown");
        await expect.poll(() => highlighted(create)).toBe(true);
        await expect.poll(() => highlighted(entry)).toBe(false);

        await page.keyboard.press("ArrowUp");
        await expect.poll(() => highlighted(entry)).toBe(true);

        await page.keyboard.press("Enter");
        await expect(entry).toHaveAttribute("data-attached", "true");

        await page.keyboard.press("Escape");
        await expect(panel).toBeHidden();
    });

    test("nothing matching says so, and Create new starts with the typed name", async ({ page }) => {
        await openConversation(page);

        const panel = reading(page).locator('[data-mail--label-menu-target="panel"]');

        await page.keyboard.press("l");
        await expect(filter(page)).toBeFocused();
        await page.keyboard.type("Zebra crossing");

        await expect(panel.getByText("Nothing matches.")).toBeVisible();
        await expect(panel.locator("[data-label-id]:not(.hidden)")).toHaveCount(0);

        // Create new is the only thing left to land on.
        await page.keyboard.press("ArrowDown");
        await page.keyboard.press("Enter");

        await expect(page.locator('input[name="label[name]"]')).toHaveValue("Zebra crossing");
    });
});

test.describe("move menu filter", () => {
    test("v, a few letters, the arrows and Enter move the conversation", async ({ page }) => {
        await openConversation(page);

        const menu = reading(page).getByRole("menu", { name: "Move to" });

        await page.keyboard.press("v");
        await expect(menu).toBeVisible();
        await expect(filter(page)).toBeFocused();

        await page.keyboard.type("folder");
        await expect(menu.getByRole("menuitem", { name: "E2E Folder" })).toBeVisible();
        await expect(menu.getByRole("menuitem").locator("visible=true")).toHaveCount(1);

        // Down from the only match wraps back onto it.
        await page.keyboard.press("ArrowDown");
        await expect.poll(() => highlighted(menu.getByRole("menuitem", { name: "E2E Folder" }))).toBe(true);

        await page.keyboard.press("Enter");

        await expect(page.locator("#toast-region [role='status']").filter({ hasText: "Moved to E2E Folder" })).toBeVisible();
        await expect(mailRow(page, INBOX_SUBJECTS.read)).toHaveCount(0);
    });
});
