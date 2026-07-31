import { Controller } from "@hotwired/stimulus"

/**
 * Persists an admin panel's collapsed state.
 *
 * The panel is a native <details>, so opening and closing already works
 * without this controller — it only records the result server-side so the
 * state survives a reload, a new browser, or a different machine. A failed
 * request is therefore not worth interrupting the admin for: the panel is
 * already in the state they asked for, it just won't be remembered.
 *
 * Attached to the <details> element, driven by its own `toggle` event.
 */
export default class extends Controller {
    static values = {
        key:  String,   // panel slug, matches the server's whitelist
        url:  String,   // POST endpoint
        csrf: String,
    }

    persist() {
        // The frame this lives in auto-refreshes, which re-renders the
        // <details> from server state. Without keepalive an in-flight request
        // can be cancelled by that swap and the toggle silently lost.
        //
        // keepalive is not enough on its own, though: a refresh *issued*
        // before this write commits still answers with the pre-toggle markup
        // and swaps the panel back. So the frame is told to hold off until the
        // write lands — see mail--auto-refresh#hold.
        const body = JSON.stringify({
            key: this.keyValue,
            collapsed: !this.element.open,
        })

        this.dispatch("persisting", { prefix: "admin--admin-panel" })

        fetch(this.urlValue, {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-Token": this.csrfValue,
            },
            body,
            keepalive: true,
        }).catch(() => {
            // Deliberately silent — see the class comment.
        }).finally(() => {
            // finally, not then: a failed write must still release the frame,
            // or one dropped request stops the dashboard refreshing until the
            // page is reloaded.
            this.dispatch("persisted", { prefix: "admin--admin-panel" })
        })
    }
}
