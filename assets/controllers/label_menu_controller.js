import { Controller } from "@hotwired/stimulus";

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
            document.addEventListener("click", this._boundClose, { capture: true });
        }
    }

    async toggleLabel(event) {
        event.stopPropagation();

        const button = event.currentTarget;
        const labelId = Number(button.dataset.labelId);
        const attach = button.dataset.attached !== "true";

        const targets = this._resolveTargets();
        console.log(targets, labelId, attach);
        for (const target of targets) {
            await this._post(
                `/status/${target.type}/${target.id}/label`,
                { labelId: labelId, attach: attach },
            );
        }

        button.dataset.attached = attach ? "true" : "false";

        const check = button.querySelector("[data-label-menu-target='check']");

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
            headers: {
                "Content-Type": "application/json",
                "X-CSRF-Token": document.querySelector('meta[name="csrf-token"]')?.content ?? "",
            },
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
        this.panelTarget.classList.add("hidden");
        document.removeEventListener("click", this._boundClose, { capture: true });
    }
}
