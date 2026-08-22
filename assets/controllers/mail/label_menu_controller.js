import { Controller } from "@hotwired/stimulus";
import { jsonCsrfHeaders } from "../../csrf.js";
import { pinToTopLayer, releaseFromTopLayer } from "../../popout.js";

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
    static targets = ["panel"];

    static values = {
        targetType: { type: String, default: "thread" },
        targetId: Number,
    };

    connect() {
        this._boundClose = this._closeOnOutsideClick.bind(this);
    }

    disconnect() {
        document.removeEventListener("click", this._boundClose, { capture: true });
    }

    toggle(event) {
        event.stopPropagation();

        const isOpen = !this.panelTarget.classList.contains("hidden");

        if (isOpen) {
            this._close();
        } else {
            this.panelTarget.classList.remove("hidden");

            // Into the top layer, or it never gets out of the mail pane: the
            // panes carry backdrop-filter, which makes them a stacking context
            // AND the containing block for position:fixed, and every ancestor
            // from there down is overflow:hidden. This menu used to vanish
            // behind the navbar and no z-index could have fixed it. See
            // assets/popout.js.
            pinToTopLayer(event.currentTarget, this.panelTarget);

            document.addEventListener("click", this._boundClose, { capture: true });
        }
    }

    async toggleLabel(event) {
        event.stopPropagation();

        const button = event.currentTarget;
        const labelId = Number(button.dataset.labelId);
        const attach = button.dataset.attached !== "true";

        const targets = this._resolveTargets();

        for (const target of targets) {
            await this._post(
                `/status/${target.type}/${target.id}/label`,
                { labelId: labelId, attach: attach },
            );
        }

        button.dataset.attached = attach ? "true" : "false";

        const check = button.querySelector("[data-mail--label-menu-target='check']");

        if (check) {
            check.classList.toggle("invisible", !attach);
        }
    }

    // ── Private ───────────────────────────────────────────────────────────

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

    async _post(url, body) {
        const response = await fetch(url, {
            method: "POST",
            headers: jsonCsrfHeaders(),
            body: JSON.stringify(body),
        });

        if (!response.ok) {
            console.error(`[label-menu] label update failed: ${url}`, response.status);
            return;
        }

        const html = await response.text();

        if (html.trim() !== "") {
            Turbo.renderStreamMessage(html);
        }
    }

    _closeOnOutsideClick(event) {
        if (!this.element.contains(event.target)) {
            this._close();
        }
    }

    _close() {
        // Out of the top layer first: a hidden popover that was never dismissed
        // keeps its scroll and resize listeners, and the next open would add a
        // second pair.
        releaseFromTopLayer(this.panelTarget);

        this.panelTarget.classList.add("hidden");
        document.removeEventListener("click", this._boundClose, { capture: true });
    }
}
