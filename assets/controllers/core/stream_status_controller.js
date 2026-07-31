import {Controller} from "@hotwired/stimulus";

/**
 * Reflects the Mercure connection state onto the element, for the glow behind
 * the logo (see `.stream-glow` in app.css).
 *
 * Separate from core--mercure rather than folded into it: that controller lives
 * on <body> and owns the connection, while this one is furniture inside the
 * topbar. Keeping them apart means the topbar can be rendered, replaced or
 * dropped by Turbo without touching the stream, and the indicator listens on
 * document so it picks up the state wherever it is mounted.
 *
 * The state is announced as well as shown. The whole point is a failure the UI
 * otherwise hides, so leaving it to colour alone would hide it again from
 * anyone who cannot see the colour — hence role="status" and the visually
 * hidden text, which is also what the e2e assertions read.
 */
export default class extends Controller {
    static targets = ["announcement"];

    connect() {
        this._onState = (event) => this._render(event.detail.state);
        document.addEventListener("core--mercure:state", this._onState);

        // Nothing is dispatched between transitions, so a topbar mounted after
        // the connection settled would otherwise sit blank until the next
        // change — which, on a healthy instance, may be never.
        this._render(this.element.dataset.state || null);
    }

    disconnect() {
        document.removeEventListener("core--mercure:state", this._onState);
    }

    _render(state) {
        if (!state) {
            return;
        }

        this.element.dataset.state = state;

        const label = this._label(state);
        this.element.setAttribute("title", label);

        if (this.hasAnnouncementTarget) {
            this.announcementTarget.textContent = label;
        }
    }

    _label(state) {
        // Keys live under mercure.status.* in the translation catalogues.
        return this.element.dataset[`label${state.charAt(0).toUpperCase()}${state.slice(1)}`] || "";
    }
}
