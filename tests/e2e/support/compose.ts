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

/**
 * Press one of the compose action bar's controls, by its accessible name,
 * wherever it is right now: on the bar, or folded into More options because
 * the window is too narrow for it (compose--action-overflow). Which ones fold
 * depends on the window's width and on what is switched on — the writing
 * assistant and a cloud connection each add an icon — so a spec that wants
 * the control, not the layout, asks for it through here.
 *
 * @param window the compose window (the dock, or the inline reply's frame)
 */
export async function pressAction(window: Locator, name: string): Promise<void> {
    const button = window.getByRole("button", { name, exact: true });

    if (await button.isVisible()) {
        await button.click();

        return;
    }

    await window.getByRole("button", { name: "More options", exact: true }).click();

    const entry = window.getByRole("menuitem", { name, exact: true });

    await expect(entry).toBeVisible();
    await entry.click();
}
