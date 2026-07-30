import { Controller } from "@hotwired/stimulus"

/**
 * Re-reads the rule list while an "apply to existing mail" run is in progress.
 *
 * The list is server-rendered from MailRule.runState, so it is already correct
 * on any load — this only shortens the wait for a page that happens to be open.
 * That split is deliberate: the push is a hint, the row is the record, and a
 * missed message costs a stale panel rather than a wrong answer.
 *
 * Listens for the mercure controller's `rule-run` event on <body> rather than
 * opening its own EventSource, so there is still one connection per topic.
 *
 * A slow poll runs only while something is busy, so the status still settles if
 * the hub is unreachable — and stops as soon as nothing is running, which is
 * the usual state of this page.
 */
export default class extends Controller {
    static values = { busy: Boolean }

    connect() {
        this._onRuleRun = () => this.refresh()
        document.addEventListener("core--mercure:rule-run", this._onRuleRun)

        if (this.busyValue) {
            this._poll = setInterval(() => this.refresh(), 5000)
        }
    }

    disconnect() {
        document.removeEventListener("core--mercure:rule-run", this._onRuleRun)
        clearInterval(this._poll)
    }

    refresh() {
        // reload() re-fetches the frame; the list re-renders from the rule
        // rows, which is where the progress actually lives.
        document.getElementById("settings-filter-frame")?.reload()
    }
}
