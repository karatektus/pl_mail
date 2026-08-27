import { Controller } from "@hotwired/stimulus";
import { takePendingCancel } from "../../compose/pending_cancel.js";

/**
 * The send response's only job: tell the window that sent it how to call the
 * send off, and how to tidy up when the offer expires.
 *
 * An empty, hidden element appended into the compose frame — see
 * compose/_sending.stream.html.twig. It exists because the two URLs it carries
 * cannot be known when the window is rendered: a brand-new message has no id
 * until the send mints one, and both `undo` and `settle` are addressed by it.
 * A turbo-stream is how the server speaks to a page it is not re-rendering,
 * and a Stimulus controller is how that stream reaches a controller that is
 * already alive.
 *
 * It hands the values over and removes itself. Everything after that —- the
 * countdown, the cancel, the settle — belongs to compose--compose, because the
 * surface all three act on is its pill.
 */
export default class extends Controller {
    static values = {
        undoUrl:   String,
        settleUrl: String,
        // The CANCEL window, not the send delay. The gap between the two is
        // deliberate: see ComposeController::CANCEL_WINDOW_MS.
        delay: { type: Number, default: 8000 },
    };

    connect() {
        // The frame is the window's own root element, so the controller is on
        // the element this was appended next to — not an ancestor of it.
        const frame  = this.element.closest("turbo-frame") ?? document;
        const root  = frame.querySelector('[data-controller~="compose--compose"]');

        if (null === root) {
            // No composer to hand this to — the reader closed the window in
            // the gap between pressing Send and this markup arriving. Returning
            // here is right for everything EXCEPT a cancel they pressed in that
            // same gap: the send is already queued on a worker behind a
            // ten-second delay, the flag recording the press died with the
            // controller, and nothing else is going to call it off. The
            // message would go out ten seconds after somebody cancelled it,
            // with no error anywhere, because nothing failed.
            //
            // This element is the only thing that ever learns the undo URL, so
            // if a cancel is owing for this frame it has to spend it itself.
            if (true === takePendingCancel(frame instanceof Element ? frame.id : "")) {
                // keepalive: the window is already gone and the reader may be
                // navigating away behind this. A cancel that is dropped because
                // the page unloaded is the same lost cancel by another route.
                fetch(this.undoUrlValue, {
                    method:    "POST",
                    headers:   { "X-Requested-With": "XMLHttpRequest" },
                    keepalive: true,
                }).catch((error) => console.error("[compose] standing cancel failed", error));
            }

            return;
        }

        const compose = this.application.getControllerForElementAndIdentifier(
            root,
            "compose--compose",
        );

        compose?.armSendHold({
            undoUrl:   this.undoUrlValue,
            settleUrl: this.settleUrlValue,
            delay:     this.delayValue,
        });

        // Out of connect(), not inside it: removing the element a controller
        // is connecting to, mid-connect, is a teardown racing its own setup.
        requestAnimationFrame(() => this.element.remove());
    }
}
