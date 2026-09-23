import { Controller } from "@hotwired/stimulus";
import { jsonCsrfHeaders } from "../../csrf.js";
import { requestFailed } from "../../request_errors.js";

/**
 * "Label as" dropdown. Toggles a label on the target thread/message via the
 * status endpoint and re-renders through the returned Turbo Stream.
 *
 * Values:
 *   targetType — "thread" | "message"
 *   targetId   — entity id; omitted in bulk mode, in which case the ids are
 *                read from checked rows in the surrounding list
 *                ([data-thread-select]:checked → value = thread id).
 */
export default class extends Controller {
    // `check` is read per button (querySelector inside each option) rather
    // than through this.checkTargets, because each tick belongs to its own
    // button; declared so the attribute the template writes is a real target.
    static targets = ["panel", "check"];

    static values = {
        targetType: { type: String, default: "thread" },
        targetId: Number,
    };

    connect() {
        this._boundClose = this._closeOnOutsideClick.bind(this);
    }

    disconnect() {
        // The whole close, not just the click listener: a menu re-rendered
        // while open (a Turbo Stream replacing the thread toolbar) otherwise
        // left its scroll and resize listeners on window, and a popover open
        // in the top layer outlives the element that owned it.
        this._close();
    }

    toggle(event) {
        event.stopPropagation();

        const isOpen = !this.panelTarget.classList.contains("hidden");

        if (isOpen) {
            this._close();
        } else {
            this.panelTarget.classList.remove("hidden");
            this._escape();
            this._place();

            // Bulk mode: the menu is shared by whatever is selected, so what it
            // shows has to be read from the selection each time it opens.
            this._syncFromSelection();

            document.addEventListener("click", this._boundClose, { capture: true });

            // A fixed panel does not travel with the thing it belongs to, so it
            // is dismissed rather than followed. Capture, because the pane it
            // sits in scrolls and a scroll there does not reach the window.
            window.addEventListener("scroll", this._boundClose, { capture: true });
            window.addEventListener("resize", this._boundClose);
        }
    }

    /**
     * "Create label" opens the modal — which must not open underneath this
     * panel, and would: the panel is in the top layer while it is open.
     */
    close() {
        this._close();
    }

    async toggleLabel(event) {
        event.stopPropagation();

        const button = event.currentTarget;
        const labelId = Number(button.dataset.labelId);
        const attach = button.dataset.attached !== "true";

        const targets = this._resolveTargets();

        // Stops at the first failure: one toast rather than one per row, and
        // the tick below stays as it was instead of promising a change that
        // did not happen.
        for (const target of targets) {
            const ok = await this._post(
                `/status/${target.type}/${target.id}/label`,
                { labelId: labelId, attach: attach },
            );

            if (false === ok) {
                return;
            }
        }

        button.dataset.attached = attach ? "true" : "false";

        const check = button.querySelector("[data-mail--label-menu-target='check']");

        if (check) {
            check.classList.toggle("invisible", !attach);
        }
    }

    // ── Private ───────────────────────────────────────────────────────────

    /**
     * Tick the labels the current selection already carries.
     *
     * Single-target mode gets this from the server — _thread_content renders
     * `activeLabels` — but the toolbar's instance is shared by every row and
     * therefore renders with nothing ticked. That was not only cosmetic: this
     * menu decides attach-or-detach from the tick
     * (`attach = dataset.attached !== "true"`), so an untitcked label that was
     * in fact attached got attached AGAIN on the next click. A label could be
     * put on a conversation and never taken off through the interface.
     *
     * Ticked only when EVERY selected row carries it, which is the reading that
     * makes the next click do what the tick promises: a tick means "all of
     * these have it", so clicking removes it from all of them. Gmail draws a
     * third, indeterminate state for a partial selection; that is a nicer
     * answer and a different change.
     */
    _syncFromSelection() {
        if (this.hasTargetIdValue) {
            return;
        }

        const rows = [...document.querySelectorAll("[data-thread-select]:checked")]
            .map((box) => box.closest("[data-label-ids]"))
            .filter((row) => null !== row);

        for (const button of this.panelTarget.querySelectorAll("[data-label-id]")) {
            const id = button.dataset.labelId;

            const attached = rows.length > 0 && rows.every(
                (row) => (row.dataset.labelIds ?? "").split(",").includes(id),
            );

            button.dataset.attached = attached ? "true" : "false";

            button.querySelector("[data-mail--label-menu-target='check']")
                ?.classList.toggle("invisible", false === attached);
        }
    }

    _resolveTargets() {
        if (this.hasTargetIdValue) {
            return [{ type: this.targetTypeValue, id: this.targetIdValue }];
        }

        const checked = document.querySelectorAll("[data-thread-select]:checked");
        const targets = [];

        for (const box of checked) {
            targets.push({ type: "thread", id: Number(box.value) });
        }

        return targets;
    }

    /** Answers false when the request failed, after saying so. */
    async _post(url, body) {
        let response;

        try {
            response = await fetch(url, {
                method: "POST",
                headers: jsonCsrfHeaders(),
                body: JSON.stringify(body),
            });
        } catch {
            requestFailed(null);
            return false;
        }

        if (requestFailed(response)) {
            return false;
        }

        const html = await response.text();

        if (html.trim() !== "") {
            Turbo.renderStreamMessage(html);
        }

        return true;
    }

    _closeOnOutsideClick(event) {
        if (!this.element.contains(event.target)) {
            this._close();
        }
    }

    _close() {
        this.panelTarget.classList.add("hidden");

        // hidePopover() throws on a popover that is not open — the first
        // outside click of a page whose menu was never opened.
        if (this._canEscape() && this.panelTarget.matches(":popover-open")) {
            this.panelTarget.hidePopover();
        }

        document.removeEventListener("click", this._boundClose, { capture: true });
        window.removeEventListener("scroll", this._boundClose, { capture: true });
        window.removeEventListener("resize", this._boundClose);

        // Back to the stylesheet's own sizing, or the next open would start
        // from wherever this one finished.
        for (const property of ["position", "top", "bottom", "left", "right", "margin", "maxHeight"]) {
            this.panelTarget.style[property] = "";
        }
    }

    /**
     * Lift the panel into the top layer while it is open.
     *
     * The conversation's toolbar sits in a pane with `backdrop-filter`, which
     * makes the pane a stacking context and the containing block for
     * `position: fixed` — so the panel was painted under the navbar whatever
     * its z-index said, and could not be positioned out of it either. The top
     * layer is above every stacking context and clipped by nothing, while the
     * panel stays where it is in the DOM, so the actions scoped to this
     * controller keep reaching it. Same as ui--user-menu.
     *
     * `popover="manual"`, set here rather than in the template so a browser
     * without the API never sees the attribute. MANUAL, not auto: auto's light
     * dismiss closes the popover on the first pointerdown, so the click meant
     * for an option landed on nothing — which is how the first attempt at this
     * failed. Closing stays with _closeOnOutsideClick, as before.
     */
    _escape() {
        if (!this._canEscape()) {
            return;
        }

        if (!this.panelTarget.hasAttribute("popover")) {
            this.panelTarget.setAttribute("popover", "manual");
        }

        this.panelTarget.showPopover();
    }

    _canEscape() {
        return typeof this.panelTarget.showPopover === "function";
    }

    /**
     * Keep the panel inside whatever is clipping it.
     *
     * The reading pane is `overflow-hidden`, so a panel hanging below the
     * toolbar was sliced off at the pane's edge — and could not be scrolled to,
     * because the thing clipping it is not the thing that scrolls.
     *
     * The obvious fix is `position: fixed`, and it does not work here. A fixed
     * element is positioned against the nearest ancestor carrying a
     * `transform`, `filter` or `backdrop-filter` rather than against the
     * viewport, and the boxed layout gives the panes a backdrop blur — so the
     * panel stayed inside the same box, now with coordinates measured from it.
     * Measured, not assumed: it reported `top: 125px` inline and rendered at
     * 210, ending 80px below a 420px viewport.
     *
     * So it stays where it is and is made to fit. The height is capped to the
     * room between the button and the bottom of the clipping box, and the menu
     * flips above the button when there is more room there. It already scrolls
     * internally, so a short pane gets a short menu rather than a cut-off one.
     */
    _place() {
        const button = this.element.querySelector("button");

        if (button === null) {
            return;
        }

        const panel  = this.panelTarget;
        const rect   = button.getBoundingClientRect();
        const margin = 8;

        // In the top layer the containing block is the viewport, so the
        // classes that hung the panel under the button (`absolute right-0
        // top-full mt-1`) mean nothing and the position is written by hand.
        // Every inset and the margin are set, because the UA's popover style
        // (`inset: 0; margin: auto`) would otherwise centre it on screen.
        if (panel.matches(":popover-open")) {
            const below = window.innerHeight - rect.bottom - margin;
            const above = rect.top - margin;
            const flip  = below < 140 && above > below;
            const width = panel.getBoundingClientRect().width;
            const left  = Math.min(
                Math.max(margin, rect.right - width),
                Math.max(margin, window.innerWidth - width - margin),
            );

            panel.style.position  = "fixed";
            panel.style.margin    = "0";
            panel.style.left      = `${Math.round(left)}px`;
            panel.style.right     = "auto";
            panel.style.maxHeight = `${Math.max(96, Math.floor(flip ? above : below))}px`;

            if (flip) {
                panel.style.top    = "auto";
                panel.style.bottom = `${Math.round(window.innerHeight - rect.top + 4)}px`;
            } else {
                panel.style.top    = `${Math.round(rect.bottom + 4)}px`;
                panel.style.bottom = "auto";
            }

            return;
        }

        // No top layer: stay in place and fit inside whatever clips.
        const box = this._clipperFor(panel);

        const below = box.bottom - rect.bottom - margin;
        const above = rect.top - box.top - margin;
        const flip  = below < 140 && above > below;

        panel.style.maxHeight = `${Math.max(96, Math.floor(flip ? above : below))}px`;

        if (flip) {
            panel.style.top    = "auto";
            panel.style.bottom = "100%";
        } else {
            panel.style.top    = "";
            panel.style.bottom = "";
        }
    }

    /**
     * The box the panel must stay inside: the nearest ancestor that clips.
     *
     * Falls back to the viewport, which is the right answer for the list
     * toolbar — nothing between it and the document clips, and there the menu
     * was never the problem.
     */
    _clipperFor(panel) {
        for (let node = panel.parentElement; node !== null; node = node.parentElement) {
            const style = getComputedStyle(node);

            if (/hidden|clip|auto|scroll/.test(style.overflowY + style.overflow)) {
                return node.getBoundingClientRect();
            }
        }

        return { top: 0, bottom: window.innerHeight };
    }

}
