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
        const body = JSON.stringify({
            key: this.keyValue,
            collapsed: !this.element.open,
        })

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
        })
    }
}
