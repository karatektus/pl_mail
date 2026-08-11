import { Controller } from "@hotwired/stimulus";

/**
 * The "Show images" bar.
 *
 * Only the once-off button lives here. "Always for this sender" is a real form
 * posting to a real route, because it changes stored state and should behave
 * like it — it survives a reload, and it works without this file.
 *
 * Showing images once needs no server: the blocked body already carries a
 * signed proxy URL for every image it hid, so unblocking is a message posted
 * into the reading frame. The reader's browser still never talks to the
 * sender — the URLs being swapped in point at our own proxy route.
 */
export default class extends Controller {
    show() {
        // The frame controller owns the channel into the sandbox; reaching for
        // the iframe directly from here would mean two places knowing the
        // protocol. Its element is the body partial's frame wrapper.
        const wrapper = this.element
            .closest(".mail-message-body")
            ?.querySelector('[data-controller~="mail--message-frame"]');

        if (!wrapper) return;

        const frame = this.application.getControllerForElementAndIdentifier(
            wrapper,
            "mail--message-frame",
        );

        if (frame) frame.showImages();

        // The offer is spent. Hiding rather than removing keeps the layout from
        // jumping under the cursor that just clicked it.
        this.element.classList.add("hidden");
    }
}
