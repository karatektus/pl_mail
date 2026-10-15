import { test, expect } from "./support/test";

/**
 * What's new in a browser: the ? menu, the dialog, and the parts of it that
 * only work with the script running (#165).
 *
 * The e2e stack is not a release build, so nothing here opens by itself and
 * there is no "New to you" — WhatsNewTest covers that on the server, pinned to
 * a release. What it cannot see is the dialog's wiring: links that stay in the
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
});
