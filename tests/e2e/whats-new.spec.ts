import { test, expect } from "./support/test";

/**
 * What's new in a browser: the ? menu, the dialog, and the parts of it that
 * only work with the script running (#165).
 *
 * The users here are created after the migration that gives everybody who
 * already existed 0.3.1, so they start on the running release and nothing
 * opens by itself — WhatsNewTest covers that on the server, pinned to a
 * release. What it cannot see is the dialog's wiring: links that stay in the
 * shared modal's frame, the footer's checkbox submitting without closing it,
 * and Done closing it.
 */
test.describe("What's new", () => {
    test("opens from the ? and moves between releases inside the dialog", async ({ page }) => {
        await page.goto("/settings?section=general");

        await page.locator("[data-whats-new-menu] > button").click();
        await page.getByRole("menuitem", { name: "What's new" }).click();

        const dialog = page.locator("#modal-backdrop");
        const notes = dialog.locator("[data-whats-new-notes]");

        await expect(notes).toBeVisible();
        const first = await notes.getAttribute("data-whats-new-notes");

        // The full changelog starts folded, and opening it shows older releases.
        const history = dialog.locator("[data-whats-new-history]");
        await expect(history).not.toHaveAttribute("open", /.*/);
        await history.locator("summary").click();

        const older = history.locator("[data-whats-new-release]").nth(2);
        const tag = await older.getAttribute("data-whats-new-release");
        await older.click();

        await expect(notes).toHaveAttribute("data-whats-new-notes", tag ?? "");
        expect(tag).not.toBe(first);
        await expect(dialog, "a release link stays in the dialog").toBeVisible();
        await expect(page).toHaveURL(/settings/);
    });

    test("the footer's checkbox keeps the dialog open, and Done closes it", async ({ page }) => {
        await page.goto("/settings?section=general");

        await page.locator("[data-whats-new-menu] > button").click();
        await page.getByRole("menuitem", { name: "What's new" }).click();

        const dialog = page.locator("#modal-backdrop");
        await expect(dialog.locator("[data-whats-new-notes]")).toBeVisible();

        const quiet = dialog.getByLabel("Don't show this after updates");
        await quiet.check();

        // Drawn again by the server with the box ticked, still open.
        await expect(dialog.getByLabel("Don't show this after updates")).toBeChecked();
        await expect(dialog).toBeVisible();

        // And put back, so the per-worker user is left as it was found.
        await dialog.getByLabel("Don't show this after updates").uncheck();
        await expect(dialog.getByLabel("Don't show this after updates")).not.toBeChecked();

        await dialog.getByRole("button", { name: "Done" }).click();
        await expect(dialog).toBeHidden();
    });

    /**
     * Over the calendar, whose day headings painted on top of the menu while
     * it was an ordinary dropdown inside the header's stacking context. Asked
     * the way menu-escapes-pane.spec.ts asks it: what is at the menu's centre
     * is the menu.
     */
    test("the ? menu is above the calendar", async ({ page }) => {
        await page.goto("/calendar/week");

        await page.locator("[data-whats-new-menu] > button").click();
        const item = page.getByRole("menuitem", { name: "What's new" });
        await expect(item).toBeVisible();

        const onTop = await item.evaluate((el) => {
            const box = el.getBoundingClientRect();
            const hit = document.elementFromPoint(box.left + box.width / 2, box.top + box.height / 2);

            return null !== hit && el.contains(hit);
        });

        expect(onTop, "something is painted over the What's new item").toBe(true);

        // And choosing it closes the menu, so it does not stay above the dialog.
        await item.click();
        await expect(page.locator("#modal [data-whats-new-notes]")).toBeVisible();
        await expect(item).toBeHidden();
    });
});
