import { Controller } from "@hotwired/stimulus";

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
