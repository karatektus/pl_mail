import {Controller} from "@hotwired/stimulus";
import {currentStreamState} from "./mercure_controller.js";

/**
 * Puts the Mercure connection state on the indicator dot beside the wordmark.
 *
 * `data-state` is the whole output: `.stream-dot` colours itself from it, the
 * tooltip explains it, and the e2e specs assert on it. Nothing here touches
 * classes or styles, so restyling the dot never means editing this file.
 *
 * Separate from core--mercure rather than folded into it: that controller lives
 * on <body> and owns the connection, while this one is furniture inside the
 * topbar. Keeping them apart means the topbar can be rendered, replaced or
 * dropped by Turbo without touching the stream, and the indicator listens on
 * document so it picks up the state wherever it is mounted.
 */
export default class extends Controller {

    connect() {
        this._onState = (event) => this._render(event.detail.state);
        document.addEventListener("core--mercure:state", this._onState);

        // Asked for, not waited for. Turbo replaces <body> on every visit, so
        // this element is rebuilt on each navigation while the connection it
        // reports on carries straight through — and the state event fired when
        // that connection was established is long gone. Waiting for the next
        // one left the dot grey and untitled after clicking any label, on a
        // stream that was working perfectly.
        this._render(currentStreamState() ?? this.element.dataset.state ?? null);
    }

    disconnect() {
        document.removeEventListener("core--mercure:state", this._onState);
    }

    _render(state) {
        if (!state) {
            return;
        }

        this.element.dataset.state = state;
        this.element.setAttribute("title", this._label(state));
    }

    _label(state) {
        // Keys live under mercure.status.* in the translation catalogues.
        return this.element.dataset[`label${state.charAt(0).toUpperCase()}${state.slice(1)}`] || "";
    }
}
