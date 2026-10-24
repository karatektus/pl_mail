import { Controller } from "@hotwired/stimulus"
import { uploadAppIcons } from "../../app_icons.js"

/**
 * Draws the installed app's icons once, when the server says the user's PNGs
 * for their current logo are missing (#141).
 *
 * That happens for a logo picked before the PNGs existed, and after a deploy
 * that changed the drawing (its version is part of where they are kept). The
 * layout renders this element only then, so most pages never load it. Until
 * the upload lands, the icon route answers with the wax seal.
 *
 * Once per choice per document: Turbo renders the layout again on every
 * visit, and the element keeps coming back until a page is rendered after the
 * upload. The set below stops those visits from drawing it again.
 */
const done = new Set();

export default class extends Controller {
    static values = {
        tileUrl: String,
        bleedUrl: String,
        uploadUrl: String,
    };

    async connect() {
        if (done.has(this.tileUrlValue)) {
            return;
        }

        done.add(this.tileUrlValue);

        try {
            await uploadAppIcons({
                uploadUrl: this.uploadUrlValue,
                tileUrl: this.tileUrlValue,
                bleedUrl: this.bleedUrlValue,
            });
        } catch (error) {
            // Nothing the user can act on, and the wax seal stands in: let a
            // later page load try again rather than shouting about it.
            done.delete(this.tileUrlValue);
            console.warn("app icons: could not draw or upload", error);
        }
    }
}
