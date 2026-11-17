import { expect, type Locator, type Page } from "@playwright/test";
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

/**
 * Give the first account a signature through the settings panel.
 *
 * ALWAYS pair with clearAccountSignature() in a `finally`. The signature is
 * stored on the account, so it outlives the test, the file and the browser
 * context — and the next spec on this worker opens compose to find a body that
 * is not empty. That is not hypothetical: it is why the two emoji tests in
 * compose-formatting.spec.ts failed on full runs and passed on their own. One
 * placed a caret at offset 6 of `editor.firstChild` and got
 * `IndexSizeError: there is no child at offset 6`, because a signature makes
 * the first child an element rather than the text node it had just filled in;
 * the other compared the whole editor's innerText to the string it typed and
 * found four extra lines of sign-off.
 */
export async function setAccountSignature(page: Page, text: string): Promise<void> {
    await page.goto("/settings/signature");

    // The account editor, which is the only one on the page until an address
    // is given a signature of its own.
    const form = page
        .locator('form[action$="/signature"]')
        .first();

    const editor = form.locator('[contenteditable="true"]');

    await editor.click();
    await editor.fill(text);
    await form.getByRole("button", { name: /Save signature/i }).click();

    await expect(page.getByText("Saved.")).toBeVisible();
}

/**
 * Put the account back to signing with nothing.
 *
 * An empty signature is genuinely "no signature": SignatureProvider::block()
 * returns the empty string for anything that trims to nothing, so no
 * `[data-pl-signature]` block is written into a body — which is the state a
 * freshly seeded account is in.
 */
export async function clearAccountSignature(page: Page): Promise<void> {
    await page.goto("/settings/signature");

    const form = page.locator('form[action$="/signature"]').first();
    const editor = form.locator('[contenteditable="true"]');

    await editor.click();

    // Emptied through the DOM, not with `fill("")`. A contenteditable that is
    // cleared by typing keeps the `<br>` the browser puts in an empty block, so
    // `fill("")` posts "<br>" — and "<br>" does not trim to nothing, so
    // SignatureProvider::block() wraps it and every later compose body opens
    // with an empty-looking but very real signature block. The account is then
    // not back where it started, which is the whole point of this helper.
    //
    // The `input` event is what the toolbar controller listens for to mirror
    // the editor into the hidden field the form actually submits, so this still
    // goes through the real save path rather than around it.
    await editor.evaluate((node) => {
        node.innerHTML = "";
        node.dispatchEvent(new Event("input", { bubbles: true }));
    });

    await form.getByRole("button", { name: /Save signature/i }).click();

    await expect(page.getByText("Saved.")).toBeVisible();
}
