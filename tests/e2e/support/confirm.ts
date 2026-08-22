import { expect, type Page } from "@playwright/test";

/**
 * Answer the application's confirmation dialog.
 *
 * Destructive actions used to ask through `window.confirm()` — Turbo's default
 * implementation of `data-turbo-confirm`, plus a handful of raw
 * `onsubmit="return confirm(…)"` attributes. A native confirm() blocks the
 * page, and Playwright surfaces it as a `dialog` event, so every spec that
 * deleted a label or revoked a device carried a line like
 * `page.once("dialog", (dialog) => dialog.accept())`.
 *
 * `Turbo.setConfirmMethod` now routes all of it through the in-app
 * `<dialog id="confirm-dialog">` (templates/_partials/_confirm_dialog.html.twig).
 * That is ordinary DOM: no `dialog` event is ever emitted, so those handlers
 * stopped firing, the action was never confirmed, and eighteen specs timed out
 * waiting for a change that could not happen. This is the replacement, in one
 * place, so the next such change is one edit rather than eighteen.
 *
 * It waits for the dialog to be hidden again before returning. That matters:
 * the dialog is in the browser's top layer, above everything, so a caller that
 * carried straight on to its next click could have that click swallowed by a
 * dialog still playing its close — a failure that reads as "element not
 * visible" pointing at an element that is perfectly visible.
 *
 * Cancelling is deliberately not offered here. Only confirm-dialog.spec.ts
 * exercises the Cancel and Escape paths, and it does so by naming the dialog's
 * parts directly, because there the dialog is the subject rather than a step.
 */
export async function acceptConfirm(page: Page): Promise<void> {
    const dialog = page.locator("#confirm-dialog");

    await dialog.getByRole("button", { name: "Continue" }).click();
    await expect(dialog).toBeHidden();
}
