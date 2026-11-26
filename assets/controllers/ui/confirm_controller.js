import { Controller } from "@hotwired/stimulus";

/**
 * The application's confirmation dialog, and the thing Turbo asks instead of
 * asking the browser.
 *
 * See templates/_partials/_confirm_dialog.html.twig for why the browser dialog
 * had to go and why this is a <dialog>.
 *
 * The controller's whole job is to turn a modal dialog into a promise: ask()
 * shows it, and resolves true or false when it closes. Everything else —
 * focus, Escape, inertness, restoring focus to whatever was focused before — is
 * the platform's.
 */
export default class extends Controller {
    static targets = ["message", "confirm", "cancel", "link"];

    connect() {
        // Registered on the element so assets/confirm.js can find whichever
        // instance is on the page, without either knowing about the other.
        this.element.plConfirm = (message, options) => this.ask(message, options);
    }

    disconnect() {
        delete this.element.plConfirm;

        // A dialog left open across a Turbo body swap would be showing over the
        // next page with nothing left to answer it.
        this._settle(false);
    }

    /**
     * `options` is for the one question that is not "are you sure": the pin
     * reminder (#654) names its own button ("Pin anyway") and links to the
     * setting it is about. Both go back to the dialog's own on every ask, so
     * the next caller never inherits them.
     *
     * @param {string} message
     * @param {{confirm?: string, link?: {href: string, text: string}}} [options]
     * @returns {Promise<boolean>}
     */
    ask(message, options = {}) {
        // A second question while one is already up: answer the first with "no"
        // rather than leaving its promise dangling forever. Two confirmations
        // at once is not a state the app produces today, and a promise that
        // never settles is the kind of thing that only shows up as a button
        // that has silently stopped working.
        this._settle(false);

        this.messageTarget.textContent = message ?? "";

        this.confirmTarget.dataset.label ??= this.confirmTarget.textContent.trim();
        this.confirmTarget.textContent = options.confirm ?? this.confirmTarget.dataset.label;

        if (this.hasLinkTarget) {
            const link = options.link ?? null;

            this.linkTarget.hidden = null === link;
            this.linkTarget.textContent = link?.text ?? "";
            this.linkTarget.href = link?.href ?? "#";
        }

        return new Promise((resolve) => {
            this._resolve = resolve;

            this.element.addEventListener(
                "close",
                () => {
                    const confirmed = "confirm" === this.element.returnValue;
                    this._resolve = null;
                    resolve(confirmed);
                },
                { once: true },
            );

            // Cleared first: returnValue persists between openings, so a dialog
            // dismissed with Escape would otherwise report whatever button was
            // pressed the previous time.
            this.element.returnValue = "";
            this.element.showModal();

            // Cancel focused rather than Continue. Every caller of this is
            // destructive — that is what a confirmation is for — so the safe
            // answer is the one a stray Enter or Space lands on.
            this.cancelTarget.focus();
        });
    }

    _settle(answer) {
        if (!this._resolve) {
            return;
        }

        const resolve = this._resolve;
        this._resolve = null;

        if (this.element.open) {
            this.element.close();
        }

        resolve(answer);
    }
}
