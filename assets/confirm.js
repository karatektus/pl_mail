/**
 * Point Turbo's confirmation at the application's own dialog.
 *
 * `data-turbo-confirm` calls `window.confirm()` unless Turbo is told otherwise,
 * and the browser dialog is the one piece of interface plMail cannot style,
 * theme or translate — it also announces the origin at the top, which makes
 * "delete this label?" read like a phishing warning. Twenty-two places used it.
 *
 * One registration rather than twenty-two rewrites, and that is the point: the
 * next `data-turbo-confirm` anybody writes is covered without them having to
 * know this file exists.
 *
 * Falls back to `window.confirm` when the dialog is not on the page. That is
 * not defensive noise — the sharing and booking pages have their own layout
 * which does not include it, and a confirmation that silently returned false
 * there would turn a destructive button into one that does nothing, which is
 * the worse failure of the two.
 */
import * as Turbo from "@hotwired/turbo";

/**
 * Ask the question, and answer true or false.
 *
 * Exported as well as registered with Turbo, because not every destructive
 * action in the app is a link or a form submission — `data-turbo-confirm` is
 * only consulted for those two, and a Stimulus controller that posts with
 * fetch() would carry the attribute and never be asked anything. Delete
 * forever, on a row, is exactly that shape. One function so those callers do
 * not each grow their own dialog.
 *
 * @param {string} message
 * @returns {Promise<boolean>}
 */
export function askConfirm(message) {
    const dialog = document.getElementById("confirm-dialog");

    if (dialog?.plConfirm) {
        return dialog.plConfirm(message);
    }

    // Not defensive noise: the sharing and booking pages use their own layout,
    // which does not include the dialog. A confirmation that silently answered
    // false there would turn a destructive button into one that does nothing,
    // which is the worse of the two failures.
    return Promise.resolve(window.confirm(message));
}

Turbo.setConfirmMethod(askConfirm);
