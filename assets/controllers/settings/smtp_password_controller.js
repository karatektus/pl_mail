/* stimulusFetch: 'lazy' */
import { Controller } from "@hotwired/stimulus";

/**
 * One sign-in for an account, and a second for sending only when asked.
 *
 * Nearly every mail server takes the same username and password to read and to
 * send, so the account form shows one. A few do not (Zoho issues an app
 * password per protocol, and a relay in front of SMTP has accounts of its own,
 * #660), and for those a button reveals a sending username and password.
 *
 * What it switches is a checkbox the form renders and hides. The server reads
 * the box, not the field: see AccountType. So this never has to decide what a
 * blank field means — it says whether a second sign-in is wanted, and the
 * form's own rules do the rest.
 *
 * The state is read from the box on connect rather than assumed closed: an
 * account that already has a sending sign-in opens with the fields showing,
 * and a form that came back from a failed validation keeps it open.
 *
 * Targets:
 *   separate — the checkbox: ticked while a second sign-in is wanted
 *   offer    — the button that asks for one; hidden while the fields show
 *   fields   — the second sign-in and the way back; hidden otherwise
 *   username — the sending username's input, emptied on the way back
 *   password — the second password's input, emptied on the way back
 */
export default class extends Controller {
    static targets = ["separate", "offer", "fields", "username", "password"];

    connect() {
        this.#show(true === this.separateTarget.checked);
    }

    separate() {
        this.separateTarget.checked = true;
        this.#show(true);

        // The username first: it is the field above, and a relay's sign-in
        // is typed top to bottom.
        if (true === this.hasUsernameTarget) {
            this.usernameTarget.focus();
        } else if (true === this.hasPasswordTarget) {
            this.passwordTarget.focus();
        }
    }

    same() {
        this.separateTarget.checked = false;

        // Emptied, although the server would ignore it with the box clear: a
        // password left sitting in a field nobody can see is the kind of thing
        // that gets submitted somewhere it was not meant for.
        if (true === this.hasUsernameTarget) {
            this.usernameTarget.value = "";
        }

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
