import { expect, type Locator } from "@playwright/test";
import { acceptConfirm } from "./confirm";

/**
 * Delete the draft in a compose window, and answer the question it asks.
 *
 * Two things stand between a spec and the trash can now. The action bar is
 * one row and folds the icons that do not fit into ⋮ from the right
 * (compose--action-overflow), so at the dock's default width the trash can is
 * a row in the More options menu rather than a button on the bar. And deleting
 * a draft asks first. Both in one place, so a spec that only wants its draft
 * gone does not have to know about either.
 *
 * @param window the compose window (the dock, or the inline reply's frame)
 */
export async function deleteDraft(window: Locator): Promise<void> {
    const trash = window.getByRole("button", { name: "Delete draft", exact: true });

    if (await trash.isVisible()) {
        await trash.click();
    } else {
        await window.getByRole("button", { name: "More options", exact: true }).click();

        const entry = window.getByRole("menuitem", { name: "Delete draft", exact: true });

        await expect(entry).toBeVisible();
        await entry.click();
    }

    // A window with nothing in it closes without asking.
    const page = window.page();
    const dialog = page.locator("#confirm-dialog");
    const editor = window.locator('[data-compose--compose-toolbar-target="editor"]');

    await expect.poll(async () => (await dialog.isVisible()) || false === (await editor.isVisible())).toBe(true);

    if (await dialog.isVisible()) {
        await acceptConfirm(page);
    }
}
