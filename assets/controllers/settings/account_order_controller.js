import { Controller } from "@hotwired/stimulus"
import Sortable from "sortablejs"
import { requestFailed } from "../../request_errors.js"

/**
 * Display order for the account list: drag on a pointer, buttons everywhere else.
 *
 * Uses sortablejs directly rather than @stimulus-components/sortable, for the
 * same reason rules/rule_order_controller does: that wrapper builds its own
 * multipart body and offers no way to attach a CSRF token, which is why the
 * account reorder endpoint had none for as long as it did. Sending the whole
 * order as JSON also makes the write idempotent — a duplicated request lands on
 * the same arrangement instead of shifting a row twice.
 *
 * This order is COSMETIC. It decides where rows sit and nothing else; which
 * account new mail is sent from is the "make primary" button on the row, which
 * posts its own form. Dragging used to do both silently.
 */
export default class extends Controller {
    static targets = ["list"]
    static values = { url: String, csrf: String }

    connect() {
        this.sortable = Sortable.create(this.listTarget, {
            handle: ".account-drag-handle",
            animation: 150,
            // Without this a touch-drag scrolls the page instead of moving the
            // row; the handle also carries touch-none for the same reason.
            forceFallback: false,
            onEnd: () => {
                this.syncEdges()
                this.persist()
            },
        })

        this.syncEdges()

        // The last order the server confirmed — what a failed write reverts to.
        this.saved = this.currentIds()
        this.inFlight = false
        this.pending = false
    }

    disconnect() {
        this.sortable?.destroy()
    }

    /** Keyboard- and touch-reachable equivalent of a drag. */
    move(event) {
        const row = event.currentTarget.closest("[data-account-id]")
        const direction = event.currentTarget.dataset.direction === "up" ? -1 : 1
        const rows = [...this.listTarget.querySelectorAll("[data-account-id]")]
        const index = rows.indexOf(row)
        const target = index + direction

        if (target < 0 || target >= rows.length) {
            return
        }

        // Move in the DOM first so the list is already correct if the request
        // is slow; the server is told what the user can already see.
        direction === -1
            ? rows[target].before(row)
            : rows[target].after(row)

        this.syncEdges()

        // Keep focus on the control that was pressed, or a keyboard user loses
        // their place after every step. Re-queried because syncEdges() may have
        // just disabled this very button — if it did, move focus to the other
        // direction rather than leaving it on a dead control.
        const pressed = event.currentTarget.dataset.direction
        const same = row.querySelector(`[data-direction="${pressed}"]`)
        const other = row.querySelector(`[data-direction="${pressed === "up" ? "down" : "up"}"]`)

        ;(same?.disabled ? other : same)?.focus()

        this.persist()
    }

    /**
     * Re-evaluate which move buttons are dead ends.
     *
     * The server renders these disabled states from loop.first/loop.last, but a
     * move rearranges the DOM without re-rendering — so without this the top
     * row keeps an enabled "up" and the row that used to be first has a
     * permanently disabled one.
     */
    syncEdges() {
        const rows = [...this.listTarget.querySelectorAll("[data-account-id]")]

        rows.forEach((row, index) => {
            const up = row.querySelector('[data-direction="up"]')
            const down = row.querySelector('[data-direction="down"]')

            if (up) up.disabled = index === 0
            if (down) down.disabled = index === rows.length - 1
        })
    }

    currentIds() {
        return [...this.listTarget.querySelectorAll("[data-account-id]")].map(
            (row) => Number(row.dataset.accountId),
        )
    }

    /**
     * One write at a time, and only the latest order.
     *
     * Three quick presses of "down" used to be three concurrent POSTs, and
     * nothing guaranteed they landed in the order they were sent — the server
     * could end on the middle arrangement while the screen showed the last.
     * Now a move during a write only marks the order dirty, and the write that
     * follows sends whatever is on screen by then. The body is the whole order,
     * so skipping the intermediate ones loses nothing.
     */
    persist() {
        if (this.inFlight) {
            this.pending = true

            return
        }

        const ids = this.currentIds()

        this.inFlight = true
        this.pending = false

        fetch(this.urlValue, {
            method: "POST",
            headers: { "Content-Type": "application/json", "X-CSRF-Token": this.csrfValue },
            body: JSON.stringify({ ids }),
            keepalive: true,
        }).then((response) => {
            if (requestFailed(response)) {
                this.revert()
            } else {
                this.saved = ids
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

    /**
     * Put the rows back in the last order the server holds.
     *
     * The toast says the write failed; leaving the rows where they were
     * dropped would then have the page contradict it, and the next load put
     * the old order back unannounced. Moves made while the failed write was
     * in flight go with it — they were built on an order that never saved.
     */
    revert() {
        this.pending = false

        const rows = new Map(
            [...this.listTarget.querySelectorAll("[data-account-id]")].map((row) => [Number(row.dataset.accountId), row]),
        )

        this.saved.forEach((id) => {
            const row = rows.get(id)

            if (row) this.listTarget.append(row)
        })

        this.syncEdges()
    }
}
