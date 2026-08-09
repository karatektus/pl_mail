import { Controller } from "@hotwired/stimulus"

/**
 * A form that goes out once, however many times the button is pressed.
 *
 * Written for the two-factor code form, where a second submit is not merely
 * wasteful: the first one completes the login and replaces the half
 * authenticated session with a full one, so the second arrives at /2fa_check
 * with no two-factor process left to finish and is refused. The server now
 * answers that refusal with a redirect rather than a 403 — see
 * App\Security\TwoFactor\CompletedTwoFactorRedirect — but the honest fix for
 * "the stack was slow so I clicked again" is to not send the second request.
 *
 * Nothing here is specific to that form. Any form whose submission must not be
 * repeated can use it.
 *
 *     <form data-controller="core--submit-once"
 *           data-action="submit->core--submit-once#guard"
 *           data-core--submit-once-busy-label-value="Checking…">
 *         <button data-core--submit-once-target="button">Continue</button>
 *     </form>
 *
 * The busy label is optional and comes in already translated, because Twig is
 * where this application's strings live. Without it the button is only
 * disabled.
 */
export default class extends Controller {
    static targets = ["button"]
    static values = { busyLabel: String }

    connect() {
        this._sent = false

        // Coming Back to a page the browser kept whole restores the DOM exactly
        // as it was left — button disabled, flag set — and fires no lifecycle
        // event Stimulus listens to. Without this the form is dead on arrival.
        this._onPageShow = (event) => {
            if (true === event.persisted) {
                this._release()
            }
        }

        window.addEventListener("pageshow", this._onPageShow)
    }

    disconnect() {
        window.removeEventListener("pageshow", this._onPageShow)
    }

    guard(event) {
        if (true === this._sent) {
            event.preventDefault()
            return
        }

        this._sent = true

        // After the current task, not during it: the submit event is still
        // being dispatched, and a button disabled inside it is a button the
        // form may decide not to submit from.
        setTimeout(() => this._hold(), 0)
    }

    _hold() {
        this._buttons().forEach((button) => {
            button.disabled = true
            button.setAttribute("aria-busy", "true")

            if ("" !== this.busyLabelValue && "" !== button.textContent.trim()) {
                button.dataset.idleLabel ??= button.textContent
                button.textContent = this.busyLabelValue
            }
        })
    }

    _release() {
        this._sent = false

        this._buttons().forEach((button) => {
            button.disabled = false
            button.removeAttribute("aria-busy")

            if (undefined !== button.dataset.idleLabel) {
                button.textContent = button.dataset.idleLabel
            }
        })
    }

    _buttons() {
        return this.hasButtonTarget
            ? this.buttonTargets
            : Array.from(this.element.querySelectorAll('[type="submit"]'))
    }
}
