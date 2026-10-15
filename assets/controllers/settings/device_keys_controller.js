import { Controller } from "@hotwired/stimulus";
import { keyring } from "../../crypto/keyring.js";
import { sealForPairing } from "../../crypto/pairing.js";

/**
 * "Pair a device", with the keyring: before the form asks for a code, seal
 * the person's user keys for the new device (crypto/pairing.js) and send the
 * sealed copy along. A browser whose keyring is not open pairs the device as
 * before, without keys — the app then offers to set encryption up with the
 * password.
 */
export default class extends Controller {
    static targets = ["keys"];

    static values = {
        keyring: Object,
    };

    #sealed = false;

    async submit(event) {
        if (true === this.#sealed) {
            this.#sealed = false;

            return;
        }

        event.preventDefault();

        try {
            const ring = keyring(this.keyringValue);

            if ("open" === (await ring.ensure()).status) {
                this.keysTarget.value = await sealForPairing(ring);
            }
        } catch {
            this.keysTarget.value = "";
        }

        this.#sealed = true;
        this.element.requestSubmit(event.submitter ?? undefined);
    }
}
