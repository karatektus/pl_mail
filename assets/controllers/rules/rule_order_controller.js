import { Controller } from "@hotwired/stimulus"
import Sortable from "sortablejs"

/**
 * Execution order for mail rules: drag on a pointer, buttons everywhere else.
 *
 * Drag-and-drop alone would put ordering out of reach for keyboard users and
 * make it fiddly on a phone, and order is not decoration here — combined with
 * "stop after this filter" it decides which rule wins. So the move buttons are
 * the real control and the drag handle is the shortcut, rather than the other
 * way round.
 *
 * Uses sortablejs directly rather than @stimulus-components/sortable (which the
 * accounts list uses): that wrapper builds its own request body and offers no
 * way to attach a CSRF token, which is why the account reorder endpoint has
 * none. Sending the whole order as JSON also makes the write idempotent — a
 * duplicated request lands on the same arrangement instead of shifting twice.
 */
export default class extends Controller {
    static targets = ["list"]
    static values = { url: String, csrf: String }

    connect() {
        this.sortable = Sortable.create(this.listTarget, {
            handle: ".rule-drag-handle",
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
    }

    disconnect() {
        this.sortable?.destroy()
    }

    /** Keyboard- and touch-reachable equivalent of a drag. */
    move(event) {
        const row = event.currentTarget.closest("[data-rule-id]")
        const direction = event.currentTarget.dataset.direction === "up" ? -1 : 1
        const rows = [...this.listTarget.querySelectorAll("[data-rule-id]")]
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
        const direction_ = event.currentTarget.dataset.direction
        const same = row.querySelector(`[data-direction="${direction_}"]`)
        const other = row.querySelector(`[data-direction="${direction_ === "up" ? "down" : "up"}"]`)

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
        const rows = [...this.listTarget.querySelectorAll("[data-rule-id]")]

        rows.forEach((row, index) => {
            const up = row.querySelector('[data-direction="up"]')
            const down = row.querySelector('[data-direction="down"]')

            if (up) up.disabled = index === 0
            if (down) down.disabled = index === rows.length - 1

            // The position number is the visible statement of execution order,
            // so it has to follow the move too.
            const position = row.querySelector("[data-rule-position]")

            if (position) position.textContent = String(index + 1)
        })
    }

    persist() {
        const ids = [...this.listTarget.querySelectorAll("[data-rule-id]")].map(
            (row) => Number(row.dataset.ruleId),
        )

        fetch(this.urlValue, {
            method: "POST",
            headers: { "Content-Type": "application/json", "X-CSRF-Token": this.csrfValue },
            body: JSON.stringify({ ids }),
            keepalive: true,
        }).catch(() => {
            // The order on screen is what the user chose; a failed write means
            // the next load shows the stored order again, which is honest.
        })
    }
}
