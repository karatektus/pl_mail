import { Controller } from "@hotwired/stimulus";

/**
 * Submit the form when a control in it changes.
 *
 * WHY THIS EXISTS RATHER THAN `onchange="this.form.requestSubmit()"`
 * ─────────────────────────────────────────────────────────────────
 * That is what ten of these were, and under the enforced Content Security
 * Policy none of them worked. Inline event handlers cannot be authorised by a
 * nonce or a hash — the spec is explicit that neither applies to them without
 * `unsafe-hashes` — so every one was refused with:
 *
 *     Executing inline event handler violates the following Content Security
 *     Policy directive 'script-src 'self' 'nonce-…''
 *
 * It failed quietly. The control moved, the page did not, and nothing said why
 * unless a console was open — which is why it survived in the log browser's
 * filters, the clock and timezone pickers, the compose defaults and the push
 * delivery filter for as long as it did.
 *
 * A Stimulus action is the same behaviour from a file the policy already
 * allows.
 *
 * PUT THE CONTROLLER ON THE FORM
 * ──────────────────────────────
 * Stimulus resolves an action against the element or an ancestor, and the
 * control that changes is inside the form — so `data-controller` belongs on the
 * `<form>` and `data-action` on the input. On the input alone, the action
 * silently never fires, which is the same failure this replaces.
 */
export default class extends Controller {
    submit(event) {
        const form = event.target?.form ?? this.element.closest("form") ?? this.element;

        if (false === form instanceof HTMLFormElement) {
            return;
        }

        // requestSubmit(), not submit(): it fires the submit event, so Turbo
        // sees it and constraint validation still runs. On the two forms here
        // that opt out of Turbo with data-turbo="false" it behaves exactly as
        // submit() did.
        if ("function" === typeof form.requestSubmit) {
            form.requestSubmit();

            return;
        }

        form.submit();
    }

    /**
     * Select the whole value, for a read-only field somebody is about to copy.
     *
     * Here rather than in a controller of its own because it is the same
     * problem — `onclick="this.select()"` is an inline handler the policy
     * refuses — and one more file for one more line would be worse than a
     * second verb on a controller that is already about "a control was touched,
     * do the obvious thing".
     */
    selectAll(event) {
        event.target?.select?.();
    }
}
