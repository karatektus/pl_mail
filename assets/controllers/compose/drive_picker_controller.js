import { Controller } from "@hotwired/stimulus";
import { csrfToken } from "../../csrf.js";
import { showToast } from "../../toast.js";

/**
 * Insert a Drive file into the message as a share link.
 *
 * Sits on its own toolbar button (data-controller on the button), so it no
 * longer depends on an ancestor that never declared it — which is why the
 * button used to do nothing at all.
 *
 * The link inserted is a public /s/<token>/download share made on the spot.
 * The first version pasted the sender's own /drive/<id>/download URL, which
 * only works for the sender: every recipient got a login page.
 *
 * Built with DOM calls and textContent, never innerHTML with file names in it.
 */
export default class extends Controller {
    static values = {
        listUrl: String,
        linkUrl: String, // contains the placeholder 0 for the file id
        // Optional: the id of a <textarea>/<input> to insert "name: link" into
        // instead of the compose editor — the calendar event description uses it.
        textTarget: String,
        i18n: Object,
    };

    async open(event) {
        event.preventDefault();
        this._range = this._editorRange();

        let data;
        try {
            const response = await fetch(this.listUrlValue, { headers: { Accept: "application/json" } });
            if (!response.ok) throw new Error(String(response.status));
            data = await response.json();
        } catch (e) {
            this._toast(this.i18nValue.failed);
            return;
        }

        this._show(data);
    }

    _show(data) {
        const t = this.i18nValue;
        const backdrop = document.createElement("div");
        backdrop.className = "fixed inset-0 z-[60] flex items-center justify-center bg-black/40 backdrop-blur-sm p-4";

        const panel = document.createElement("div");
        panel.className = "popover w-full max-w-md max-h-[70vh] flex flex-col";
        panel.setAttribute("role", "dialog");
        panel.setAttribute("aria-modal", "true");
        panel.setAttribute("aria-label", t.title);

        const head = document.createElement("div");
        head.className = "flex items-center gap-2 px-4 h-12 border-b border-line shrink-0";
        head.innerHTML = '<i class="fa-solid fa-hard-drive text-ink-faint text-sm" aria-hidden="true"></i>';
        const title = document.createElement("h2");
        title.className = "text-sm font-semibold text-ink flex-1";
        title.textContent = t.title;
        head.appendChild(title);

        const search = document.createElement("input");
        search.type = "search";
        search.placeholder = t.search;
        search.className = "mx-4 mt-3 h-8 rounded-lg border border-field bg-field px-3 text-sm text-ink placeholder:text-ink-faint focus:outline-none focus-visible:ring-2 focus-visible:ring-accent";

        const list = document.createElement("ul");
        list.className = "flex-1 overflow-y-auto p-2 mt-1";

        const files = data.files || [];
        if (files.length === 0) {
            const empty = document.createElement("li");
            empty.className = "px-3 py-8 text-center text-sm text-ink-faint";
            empty.textContent = t.empty;
            list.appendChild(empty);
        }

        files.forEach((file) => {
            const li = document.createElement("li");
            li.dataset.name = (file.filename || "").toLowerCase();
            const button = document.createElement("button");
            button.type = "button";
            button.className = "w-full flex items-center gap-3 px-3 py-2 rounded-lg text-left hover:bg-hover transition-colors cursor-pointer";
            button.innerHTML = '<i class="fa-solid fa-file text-ink-faint w-4 text-center" aria-hidden="true"></i>';
            const text = document.createElement("span");
            text.className = "min-w-0 flex-1";
            const name = document.createElement("span");
            name.className = "block text-sm text-ink truncate";
            name.textContent = file.filename;
            const meta = document.createElement("span");
            meta.className = "block text-[11px] text-ink-faint truncate";
            meta.textContent = [this._size(file.size), file.folder].filter(Boolean).join(" · ");
            text.append(name, meta);
            button.appendChild(text);
            button.addEventListener("click", () => this._insert(file, button, close));
            li.appendChild(button);
            list.appendChild(li);
        });

        search.addEventListener("input", () => {
            const q = search.value.trim().toLowerCase();
            list.querySelectorAll("li[data-name]").forEach((li) => {
                li.hidden = q !== "" && !li.dataset.name.includes(q);
            });
        });

        const foot = document.createElement("div");
        foot.className = "flex items-center justify-between gap-2 px-4 py-3 border-t border-line shrink-0";
        const manage = document.createElement("a");
        manage.href = data.driveUrl || "/drive";
        manage.target = "_blank";
        manage.rel = "noopener";
        manage.className = "text-xs text-accent hover:underline";
        manage.textContent = t.open;
        const cancel = document.createElement("button");
        cancel.type = "button";
        cancel.className = "h-8 px-3.5 rounded-lg text-sm font-medium text-ink-soft bg-field border border-field hover:bg-hover cursor-pointer";
        cancel.textContent = t.cancel;
        foot.append(manage, cancel);

        panel.append(head, search, list, foot);
        backdrop.appendChild(panel);
        document.body.appendChild(backdrop);
        search.focus();

        const onKey = (e) => { if (e.key === "Escape") close(); };
        function close() {
            document.removeEventListener("keydown", onKey);
            backdrop.remove();
        }
        document.addEventListener("keydown", onKey);
        cancel.addEventListener("click", close);
        backdrop.addEventListener("click", (e) => { if (e.target === backdrop) close(); });
    }

    async _insert(file, button, close) {
        button.disabled = true;
        try {
            const response = await fetch(this.linkUrlValue.replace(/\/0\//, `/${file.id}/`), {
                method: "POST",
                headers: { Accept: "application/json", "X-CSRF-Token": csrfToken() },
            });
            if (!response.ok) throw new Error(String(response.status));
            const data = await response.json();
            close();
            // The landing page, not /download, so the recipient
            // sees the file name and size and can report the link.
            const link = data.url;
            if (this.textTargetValue) {
                this._insertText(link, data.filename);
            } else {
                this._insertLink(link, data.filename);
            }
        } catch (e) {
            button.disabled = false;
            this._toast(this.i18nValue.failed);
        }
    }

    _editor() {
        const compose = this.element.closest('[data-controller~="compose--compose"]');
        return compose?.querySelector('[data-compose--compose-target="body"][contenteditable="true"]') || null;
    }

    /** Plain-text fields (the event description): "name: link" at the caret. */
    _insertText(url, filename) {
        const field = document.getElementById(this.textTargetValue);
        if (!field) {
            navigator.clipboard?.writeText(url);
            this._toast(this.i18nValue.copied);
            return;
        }

        const insert = `${filename}: ${url}`;
        const start = field.selectionStart ?? field.value.length;
        const end = field.selectionEnd ?? field.value.length;
        const before = field.value.slice(0, start);
        const after = field.value.slice(end);
        const lead = before && !before.endsWith("\n") ? "\n" : "";

        field.value = before + lead + insert + after;
        const caret = (before + lead + insert).length;
        field.focus();
        field.setSelectionRange(caret, caret);
        field.dispatchEvent(new Event("input", { bubbles: true }));
    }

    _editorRange() {
        const editor = this._editor();
        const selection = window.getSelection();
        if (editor && selection && selection.rangeCount > 0 && editor.contains(selection.anchorNode)) {
            return selection.getRangeAt(0).cloneRange();
        }
        return null;
    }

    _insertLink(url, filename) {
        const editor = this._editor();
        if (!editor) {
            navigator.clipboard?.writeText(url);
            this._toast(this.i18nValue.copied);
            return;
        }

        editor.focus();
        const selection = window.getSelection();
        if (this._range) {
            selection.removeAllRanges();
            selection.addRange(this._range);
        } else {
            const end = document.createRange();
            end.selectNodeContents(editor);
            end.collapse(false);
            selection.removeAllRanges();
            selection.addRange(end);
        }

        const link = document.createElement("a");
        link.href = url;
        link.textContent = filename;
        const wrapper = document.createElement("span");
        wrapper.appendChild(link);
        // insertHTML keeps it on the undo stack; the markup is built from DOM
        // nodes above, so the file name is already escaped.
        if (!document.execCommand("insertHTML", false, wrapper.innerHTML + "&nbsp;")) {
            editor.appendChild(link);
        }
        editor.dispatchEvent(new Event("input", { bubbles: true }));
    }

    _size(bytes) {
        if (!bytes) return "";
        const units = ["B", "KB", "MB", "GB"];
        let i = 0;
        let n = bytes;
        while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
        return `${n < 10 && i > 0 ? n.toFixed(1) : Math.round(n)} ${units[i]}`;
    }

    _toast(message) {
        if (message) showToast(message);
    }
}
