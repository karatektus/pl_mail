import { Controller } from "@hotwired/stimulus";
import { renderSVG } from "uqr";
import { takeTransferKey } from "../../crypto/pairing.js";

/**
 * Draws the pairing QR code in the browser, for a pairing that carries the
 * keyring: the transfer key goes into the URI's fragment, and this browser is
 * the only place it exists (crypto/pairing.js). Without it — a reload, a
 * second tab — there is no code to draw, and the person is asked to start
 * again.
 */
export default class extends Controller {
    static targets = ["image", "link"];

    static values = {
        uri: String,
    };

    connect() {
        const key = takeTransferKey();

        if (null === key) {
            this.element.hidden = true;

            return;
        }

        const uri = `${this.uriValue}#k=${key}`;
        const svg = renderSVG(uri, { border: 1 });

        this.imageTarget.src = `data:image/svg+xml;base64,${btoa(svg)}`;
        this.imageTarget.dataset.pairingUri = uri;

        if (true === this.hasLinkTarget) {
            this.linkTarget.href = uri;
        }
    }
}
