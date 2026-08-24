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

// `Turbo.config.forms.confirm`, not the top-level setConfirmMethod: that one is
// deprecated in Turbo 8 and warns on every page load, twice, which is two lines
// of console noise standing between a reader and a real error.
Turbo.config.forms.confirm = askConfirm;

/**
 * The same guarantee for forms that opt OUT of Turbo.
 *
 * `data-turbo="false"` takes a submission away from Turbo entirely, so
 * setConfirmMethod above is never consulted and a `data-turbo-confirm` beside
 * it is inert — an attribute that looks like a guard and is not, which is worse
 * than no attribute at all. templates/admin/_live_frame.html.twig says exactly
 * that in a comment; the security page had the combination anyway, and revoking
 * every trusted device and switching off two-factor authentication both stopped
 * asking anything.
 *
 * Caught by the specs rather than by review, which is the argument for handling
 * it here instead of writing the rule down again: a listener cannot forget, and
 * the next form to combine the two is covered by having done nothing.
 *
 * Capture phase, so it runs before any other submit handler; and the submission
 * is re-issued through requestSubmit() once confirmed rather than submit(),
 * because submit() skips validation and does not fire this event again — the
 * flag is what stops the second pass asking twice.
 */
document.addEventListener(
    "submit",
    (event) => {
        const form = event.target;

        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        const question = form.getAttribute("data-turbo-confirm");

        // Turbo's own path handles everything else, and asking twice is worse
        // than the bug this fixes.
        if (null === question || "false" !== form.getAttribute("data-turbo")) {
            return;
        }

        // A string compare, because dataset values are strings — `true ===`
        // would never match and the confirmed submission would be intercepted
        // again, asking the same question forever.
        if ("true" === form.dataset.plConfirmed) {
            delete form.dataset.plConfirmed;

            return;
        }

        event.preventDefault();
        event.stopImmediatePropagation();

        void askConfirm(question).then((confirmed) => {
            if (true === confirmed) {
                form.dataset.plConfirmed = "true";
                form.requestSubmit();
            }
        });
    },
    { capture: true },
);
