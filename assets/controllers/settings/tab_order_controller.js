import { Controller } from "@hotwired/stimulus"
import Sortable from "sortablejs"
import { requestFailed } from "../../request_errors.js"

/**
 * The order of the inbox tabs: drag on a pointer, buttons everywhere else.
 *
 * The account list's controller (settings/account_order_controller) with tab
 * keys where it has ids — the same reasons hold: sortablejs directly so the
 * request can carry its CSRF token, the whole order in every write so a
 * duplicated request lands on the same arrangement, one write in flight and
 * only the latest order sent, and the rows put back if the server refuses.
 *
 * Primary is not in this list. It always comes first, so the page draws it
 * above the list rather than as a row that refuses to move.
 *
 * Values:
 *   url   — InboxCategoryController::order
 *   csrf  — csrf_token('settings-categories')
 */
export default class extends Controller {
    static targets = ["list"]
    static values = { url: String, csrf: String }

    connect() {
        this.sortable = Sortable.create(this.listTarget, {
            handle: ".tab-drag-handle",
            animation: 150,
            onEnd: () => {
                this.syncEdges()
                this.persist()
            },
        })

        this.syncEdges()

        this.saved = this.currentKeys()
        this.inFlight = false
        this.pending = false
    }

    disconnect() {
        this.sortable?.destroy()
    }

    /** Keyboard- and touch-reachable equivalent of a drag. */
    move(event) {
        const row = event.currentTarget.closest("[data-tab-key]")
        const direction = event.currentTarget.dataset.direction === "up" ? -1 : 1
        const rows = this.rows()
        const index = rows.indexOf(row)
        const target = index + direction

        if (target < 0 || target >= rows.length) {
            return
        }

        direction === -1
            ? rows[target].before(row)
            : rows[target].after(row)

        this.syncEdges()

        // Focus stays on the control that was pressed, or on its twin when
        // this move just made it a dead end — see the account list.
        const pressed = event.currentTarget.dataset.direction
        const same = row.querySelector(`[data-direction="${pressed}"]`)
        const other = row.querySelector(`[data-direction="${pressed === "up" ? "down" : "up"}"]`)

        ;(same?.disabled ? other : same)?.focus()

        this.persist()
    }

    syncEdges() {
        const rows = this.rows()

        rows.forEach((row, index) => {
            const up = row.querySelector('[data-direction="up"]')
            const down = row.querySelector('[data-direction="down"]')

            if (up) up.disabled = index === 0
            if (down) down.disabled = index === rows.length - 1
        })
    }

    rows() {
        return [...this.listTarget.querySelectorAll("[data-tab-key]")]
    }

    currentKeys() {
        return this.rows().map((row) => row.dataset.tabKey)
    }

    persist() {
        if (this.inFlight) {
            this.pending = true

            return
        }

        const keys = this.currentKeys()

        this.inFlight = true
        this.pending = false

        fetch(this.urlValue, {
            method: "POST",
            headers: { "Content-Type": "application/json", "X-CSRF-Token": this.csrfValue },
            body: JSON.stringify({ keys }),
            keepalive: true,
        }).then((response) => {
            if (requestFailed(response)) {
                this.revert()
            } else {
                this.saved = keys
            }
        }).catch(() => {
            requestFailed(null)
            this.revert()
        }).finally(() => {
            this.inFlight = false

            if (this.pending) {
                this.persist()
            }
        })
    }

    revert() {
        this.pending = false

        const rows = new Map(this.rows().map((row) => [row.dataset.tabKey, row]))

        this.saved.forEach((key) => {
            const row = rows.get(key)

            if (row) this.listTarget.append(row)
        })

        this.syncEdges()
    }
}
