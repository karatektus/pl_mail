/* stimulusFetch: 'lazy' */
import { Controller } from "@hotwired/stimulus";

/**
 * One half of the account form — receiving or sending — folded away while its
 * switch is off (#628).
 *
 * The switch sits on the section's heading, so turning a half off leaves the
 * heading and the switch where they were and takes only the fields below them.
 * Hidden rather than removed, like the POP3 and JMAP options in the same form:
 * the values still go back with it, so switching a half off and on again in
 * one sitting loses nothing typed, and AccountType asks for them only while
 * the half is on.
 *
 * Targets:
 *   toggle  — the switch's checkbox, in the heading
 *   body    — the section's fields
 */
export default class extends Controller {
    static targets = ["toggle", "body"];

    connect() {
        this.sync();
    }

    sync() {
        this.bodyTarget.classList.toggle("hidden", this.toggleTarget.checked === false);
    }
}
