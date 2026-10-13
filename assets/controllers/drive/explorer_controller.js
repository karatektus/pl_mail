import { Controller } from "@hotwired/stimulus";
import * as Turbo from "@hotwired/turbo";
import { csrfToken } from "../../csrf.js";

/**
 * Drive: filter-as-you-type, list/grid view, and uploading with progress —
 * from the Upload button or by dropping files anywhere on the pane.
 *
 * Each file is its own request. One request for a batch would run into
 * post_max_size, and PHP answers that by discarding the whole body, so a
 * batch of small files that happened to add up was lost without a word.
 *
 * Without JavaScript the Upload button is still a working multipart form;
 * this controller only takes it over.
 */
export default class extends Controller {
    static targets = ["item", "noMatch", "files", "viewButton", "input", "form", "progress", "overlay"];
    static values = {
        uploadUrl: String,
        folderId: String,
        maxUpload: Number,
        i18n: Object,
    };

    connect() {
        this._dragDepth = 0;

        let view = "list";
        try {
            view = localStorage.getItem("plmail:driveView") || "list";
        } catch (e) {}
        this._applyView(view);
    }

    // ── Filter ───────────────────────────────────────────────────────────

    filter(event) {
        const query = event.target.value.trim().toLowerCase();
        let shown = 0;

        this.itemTargets.forEach((item) => {
            const match = query === "" || (item.dataset.name || "").includes(query);
            item.hidden = !match;
            if (match) shown++;
        });

        if (this.hasNoMatchTarget) {
            this.noMatchTarget.hidden = shown > 0 || this.itemTargets.length === 0;
        }
    }

    // ── View ─────────────────────────────────────────────────────────────

    setView(event) {
        const view = event.currentTarget.dataset.view;
        this._applyView(view);
        try {
            localStorage.setItem("plmail:driveView", view);
        } catch (e) {}
    }

    _applyView(view) {
        if (this.hasFilesTarget) this.filesTarget.dataset.view = view;
        this.viewButtonTargets.forEach((button) => {
            button.setAttribute("aria-pressed", String(button.dataset.view === view));
        });
    }

    // ── Upload ───────────────────────────────────────────────────────────

    browse(event) {
        event?.preventDefault();
        this.inputTarget.click();
    }

    pick() {
        const files = Array.from(this.inputTarget.files || []);
        this.inputTarget.value = "";
        this._uploadAll(files);
    }

    dragEnter(event) {
        if (!this._hasFiles(event)) return;
        event.preventDefault();
        this._dragDepth++;
        this.overlayTarget.hidden = false;
    }

    dragOver(event) {
        if (!this._hasFiles(event)) return;
        event.preventDefault();
        event.dataTransfer.dropEffect = "copy";
    }

    dragLeave(event) {
        if (!this._hasFiles(event)) return;
        this._dragDepth = Math.max(0, this._dragDepth - 1);
        if (this._dragDepth === 0) this.overlayTarget.hidden = true;
    }

    drop(event) {
        if (!this._hasFiles(event)) return;
        event.preventDefault();
        this._dragDepth = 0;
        this.overlayTarget.hidden = true;
        this._uploadAll(Array.from(event.dataTransfer.files || []));
    }

    _hasFiles(event) {
        return Array.from(event.dataTransfer?.types || []).includes("Files");
    }

    async _uploadAll(files) {
        if (files.length === 0) return;

        let stored = 0;
        for (const file of files) {
            if (await this._upload(file)) stored++;
        }

        if (stored > 0) {
            Turbo.visit(window.location.href, { action: "replace" });
        }
    }

    _upload(file) {
        const row = this._progressRow(file.name);

        if (this.maxUploadValue > 0 && file.size > this.maxUploadValue) {
            this._finish(row, false, this.i18nValue.tooLarge);
            return Promise.resolve(false);
        }

        const body = new FormData();
        body.append("file", file, file.name);
        if (this.folderIdValue) body.append("folder_id", this.folderIdValue);

        return new Promise((resolve) => {
            const xhr = new XMLHttpRequest();
            xhr.open("POST", this.uploadUrlValue);
            xhr.setRequestHeader("Accept", "application/json");
            xhr.setRequestHeader("X-CSRF-Token", csrfToken());

            xhr.upload.addEventListener("progress", (event) => {
                if (event.lengthComputable) {
                    row.bar.style.width = `${Math.round((event.loaded / event.total) * 100)}%`;
                }
            });

            xhr.addEventListener("load", () => {
                let data = null;
                try {
                    data = JSON.parse(xhr.responseText);
                } catch (e) {}

                const ok = xhr.status >= 200 && xhr.status < 300 && data && data.stored > 0;
                const message = data?.error
                    || (xhr.status === 413 ? this.i18nValue.tooLarge : null)
                    || (ok ? this.i18nValue.done : this.i18nValue.failed);
                this._finish(row, ok, message);
                resolve(ok);
            });

            xhr.addEventListener("error", () => {
                this._finish(row, false, this.i18nValue.failed);
                resolve(false);
            });

            xhr.send(body);
        });
    }

    _progressRow(name) {
        const li = document.createElement("li");
        li.className = "flex items-center gap-3 rounded-lg border border-line bg-raised/60 px-3 py-2";

        const icon = document.createElement("i");
        icon.className = "fa-solid fa-circle-notch fa-spin text-xs text-ink-faint w-4 text-center";
        icon.setAttribute("aria-hidden", "true");

        const body = document.createElement("div");
        body.className = "flex-1 min-w-0";

        const label = document.createElement("p");
        label.className = "text-sm text-ink truncate";
        label.textContent = name;

        const track = document.createElement("div");
        track.className = "mt-1 h-1 rounded-full bg-sunken overflow-hidden";
        const bar = document.createElement("div");
        bar.className = "h-full rounded-full bg-accent transition-[width] duration-150";
        bar.style.width = "0%";
        track.appendChild(bar);

        const status = document.createElement("span");
        status.className = "shrink-0 text-xs text-ink-faint";
        status.textContent = this.i18nValue.uploading || "";

        body.append(label, track);
        li.append(icon, body, status);
        this.progressTarget.appendChild(li);

        return { li, icon, bar, status };
    }

    _finish(row, ok, message) {
        row.icon.className = `fa-solid ${ok ? "fa-circle-check text-success" : "fa-circle-exclamation text-danger"} text-xs w-4 text-center`;
        row.bar.style.width = "100%";
        row.bar.classList.toggle("bg-danger", !ok);
        row.status.textContent = message || "";
        row.status.classList.toggle("text-danger", !ok);
    }
}
