import { Controller } from "@hotwired/stimulus";

/**
 * The picture-and-video viewer's one piece of behaviour: saying so when the
 * browser cannot show the file (#295).
 *
 * The chip offers a preview from the declared type, so the bytes get no say
 * until they arrive. An octet-stream part named .jpg may turn out not to be a
 * picture (serve() then sends a download, which an <img> cannot draw), and a
 * .mov may be in a codec this browser does not play. Either way the element
 * would sit there as a broken-image glyph or a dead player; this puts a
 * sentence in its place, and the toolbar's download link is still there.
 *
 * `error` does not bubble, which is why the action is on the media element
 * itself rather than on the frame.
 */
export default class extends Controller {
    static targets = ["media", "status"];

    failed() {
        if (this.hasMediaTarget) {
            this.mediaTarget.remove();
        }

        this.statusTarget.hidden = false;
    }
}
