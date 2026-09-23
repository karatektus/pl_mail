import { Controller } from "@hotwired/stimulus";
import { jsonCsrfHeaders } from "../../csrf.js";
import { requestFailed } from "../../request_errors.js";
import { showToast } from "../../toast.js";

/**
 * The topbar's sync button: fires the same account syncs as
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
        // The words, from the template — a catalogue lives in the
        // translations, not here. See _topbar.html.twig.
        i18n: Object,
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

        let response = null;

        try {
            response = await fetch(this.urlValue, {
                method: "POST",
                headers: jsonCsrfHeaders(),
            });
        } catch {
            requestFailed(null);
            this._stop();
            return;
        }

        if (false === response.ok) {
            requestFailed(response);
            this._stop();
            return;
        }

        try {
            const result = await this._json(response);
            const t = this.i18nValue;

            this._toast(
                (1 === result.dispatched ? t.syncingOne : t.syncingOther).replace("%count%", String(result.dispatched)),
                "info",
            );

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
            throw new Error(this.i18nValue.sessionExpired);
        }

        return response.json();
    }

    _schedule() {
        this._poll = setTimeout(() => this._check(), this.pollIntervalValue);
    }

    async _check() {
        if (Date.now() > this._deadline) {
            this._toast(this.i18nValue.stillRunning, "info");
            this._stop();
            return;
        }

        try {
            const response = await fetch(this.statusUrlValue);
            const { pending } = await response.json();

            if (0 === pending) {
                this._zeroes++;

                if (this._zeroes >= this.confirmationsValue) {
                    this._toast(this.i18nValue.complete, "success");
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
        showToast(message, { type });
    }
}
