import { Controller } from "@hotwired/stimulus";
import { jsonCsrfHeaders } from "../../csrf.js";

/**
 * TEMPORARY: topbar button that fires the same account syncs as
 * `app:mail:sync`, then polls the queue until the workers are idle so the
 * icon can stop spinning.
 */
export default class extends Controller {
    static targets = ["icon"];

    static values = {
        url: String,
        statusUrl: String,
        pollInterval: { type: Number, default: 1500 },
        timeout: { type: Number, default: 300000 },
        // The queue reads as empty in the gap between dispatch and the worker
        // picking the job up, so only call it done after N zeroes in a row.
        confirmations: { type: Number, default: 3 },
    };

    disconnect() {
        clearTimeout(this._poll);
    }

    async run() {
        if (true === this._running) {
            return;
        }

        this._running = true;
        this._spin(true);

        try {
            const response = await fetch(this.urlValue, {
                method: "POST",
                headers: jsonCsrfHeaders(),
            });

            if (false === response.ok) {
                throw new Error(`Request failed (${response.status}).`);
            }

            const result = await this._json(response);

            this._toast(`Syncing ${result.dispatched} account${1 === result.dispatched ? "" : "s"}…`, "info");

            this._deadline = Date.now() + this.timeoutValue;
            this._zeroes = 0;
            // The dispatch has only just landed; give the workers a beat to
            // pick it up before the first drained check.
            this._schedule();
        } catch (error) {
            this._toast(error.message, "error");
            this._stop();
        }
    }

    // ── Private ───────────────────────────────────────────────────────────

    /**
     * A 200 that is not JSON means the request was answered by something other
     * than the endpoint — in practice the login page, after fetch quietly
     * followed the redirect an expired session produces. Say that, rather than
     * handing the user a JSON parser error about a stray "<".
     */
    async _json(response) {
        if (false === (response.headers.get("Content-Type") ?? "").includes("json")) {
            throw new Error("Your session has expired — reload the page to sign in again.");
        }

        return response.json();
    }

    _schedule() {
        this._poll = setTimeout(() => this._check(), this.pollIntervalValue);
    }

    async _check() {
        if (Date.now() > this._deadline) {
            this._toast("Sync is still running in the background.", "info");
            this._stop();
            return;
        }

        try {
            const response = await fetch(this.statusUrlValue);
            const { pending } = await response.json();

            if (0 === pending) {
                this._zeroes++;

                if (this._zeroes >= this.confirmationsValue) {
                    this._toast("Sync complete.", "success");
                    this._stop();
                    return;
                }
            } else {
                this._zeroes = 0;
            }
        } catch {
            // Transient failure — keep polling until the deadline.
        }

        this._schedule();
    }

    _stop() {
        clearTimeout(this._poll);
        this._running = false;
        this._spin(false);
    }

    _spin(on) {
        this.iconTarget.classList.toggle("fa-spin", on);
        this.element.disabled = on;
    }

    _toast(message, type) {
        const region = document.getElementById("toast-region");

        if (null === region) {
            return;
        }

        const colors = {
            success: "bg-inverse text-inverse-ink",
            error: "bg-danger text-white",
            info: "bg-info text-white",
        };

        const toast = document.createElement("div");
        toast.setAttribute("data-controller", "ui--toast");
        toast.setAttribute("role", "status");
        toast.className = `relative rounded-pane shadow-xl overflow-hidden min-w-[280px] max-w-sm ${colors[type] ?? colors.info}`;
        toast.innerHTML = `
            <div class="flex items-center gap-3 px-5 py-3.5 text-base font-medium">
                <span></span>
                <button
                    data-action="click->ui--toast#dismiss"
                    class="ml-auto opacity-50 hover:opacity-100 transition-opacity cursor-pointer shrink-0"
                >
                    <i class="fa-solid fa-xmark text-base" aria-hidden="true"></i>
                </button>
            </div>
        `;
        toast.querySelector("span").textContent = message;

        region.appendChild(toast);
    }
}
