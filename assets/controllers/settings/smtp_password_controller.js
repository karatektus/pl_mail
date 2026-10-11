import { Controller } from "@hotwired/stimulus";

/**
 * One password field for an account, and a second for sending only when asked.
 *
 * Nearly every mail server takes the same password to read and to send, so the
 * account form shows one. A few do not (Zoho issues an app password per
 * protocol), and for those a button reveals a second field.
 *
 * What it switches is a checkbox the form renders and hides. The server reads
 * the box, not the field: see AccountType. So this never has to decide what a
 * blank field means — it says whether a second password is wanted, and the
 * form's own rules do the rest.
 *
 * The state is read from the box on connect rather than assumed closed: an
 * account that already has a sending password opens with the field showing,
 * and a form that came back from a failed validation keeps it open.
 *
 * Targets:
 *   separate — the checkbox: ticked while a second password is wanted
 *   offer    — the button that asks for one; hidden while the field shows
 *   fields   — the second password and the way back; hidden otherwise
 *   password — the second password's input, emptied on the way back
 */
export default class extends Controller {
    static targets = ["separate", "offer", "fields", "password"];

    connect() {
        this.#show(true === this.separateTarget.checked);
    }

    separate() {
        this.separateTarget.checked = true;
        this.#show(true);

        if (true === this.hasPasswordTarget) {
            this.passwordTarget.focus();
        }
    }

    same() {
        this.separateTarget.checked = false;

        // Emptied, although the server would ignore it with the box clear: a
        // password left sitting in a field nobody can see is the kind of thing
        // that gets submitted somewhere it was not meant for.
        if (true === this.hasPasswordTarget) {
            this.passwordTarget.value = "";
        }

        this.#show(false);
    }

    // ── Private ───────────────────────────────────────────────────────────

    #show(separate) {
        this.fieldsTarget.hidden = false === separate;
        this.offerTarget.hidden = separate;
    }
}
