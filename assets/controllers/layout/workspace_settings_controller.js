import { Controller } from "@hotwired/stimulus";
import { showToast } from "../../toast.js";

/**
 * The workspace settings dialog (#783, layout/_workspace_settings.html.twig):
 * the workspace's accounts, its sidebar, where the sidebar's links open, and
 * the editor for the user's own sidebars.
 *
 * Sidebars are edited here as a list held in memory and sent whole with the
 * workspace's settings on Save, then the page reloads — what the accounts and
 * the sidebar change is everything the server drew. Before the reload the
 * mailbox's layout--workspace is handed the saved document, so a save of its
 * own on the way out cannot put the old one back.
 *
 * Values:
 *   saveUrl, token — POST app_layout_workspace_settings_save
 *   sidebars       — the user's own sidebars, as stored (Sidebar::toArray())
 *   catalog        — what can be added, by group: [{entry, name}]
 *   sidebar        — the workspace's sidebar: an id, "mail", or "" for none
 *   usedBy         — sidebar id => the names of the workspaces that show it
 *   strings        — translated labels
 */
export default class extends Controller {
    /**
     * The mailbox's layout--workspace, which holds the edit draft. Found by
     * selector anywhere in the document: the dialog is drawn in the modal at
     * the body's end, outside the workspace's element.
     */
    static outlets = ["layout--workspace"];

    static targets = ["accounts", "sidebarSelect", "editor", "target", "targetSection", "missing", "mailClock"];

    static values = {
        saveUrl: String,
        token: String,
        sidebars: Array,
        catalog: Object,
        sidebar: String,
        usedBy: Object,
        strings: Object,
    };

    connect() {
        this.sidebars = structuredClone(this.sidebarsValue);

        // Opened from edit mode (#783): the workspace is a draft in the page,
        // which may differ from what the server drew this dialog from — so
        // the dialog starts from the draft, and hands its choices back to it.
        this.draft = this.hasLayoutWorkspaceOutlet ? this.layoutWorkspaceOutlet.draft() : null;

        if (null !== this.draft) {
            this.#fromDraft(this.draft);
        }

        this.current = null !== this.draft ? (this.draft.sidebar ?? "") : this.sidebarValue;
        this.#drawSelect();
        this.#drawEditor();
    }

    #fromDraft(draft) {
        const groups = draft.groups ?? null;

        this.accountsTarget.querySelectorAll("input").forEach((box) => {
            box.checked = "all" === box.value ? null === groups : null !== groups && groups.includes(Number(box.value));
        });
        this.missingTargets.forEach((radio) => { radio.checked = radio.value === (draft.missing ?? "switch"); });

        // The draft's calendars, in the order they are drawn.
        const keys = [];
        const walk = (node) => {
            if ("pane" === node.t) {
                if ("calendar" === node.kind) {
                    keys.push(node.id ? `calendar~${node.id}` : "calendar");
                }
            } else {
                (node.children ?? []).forEach(walk);
            }
        };
        walk(draft.layout);

        const select = this.targetTargets.find((each) => "calendar" === each.dataset.kind);

        if (select) {
            const wanted = keys.includes(draft.targets?.calendar) ? draft.targets.calendar : keys[0];

            select.replaceChildren(...keys.map((key, index) => {
                const label = this.stringsValue.targetNth.replace("%n%", String(index + 1)) + (0 === index ? ` — ${this.stringsValue.targetDefault}` : "");
                const option = new Option(label, key);
                option.selected = key === wanted;

                return option;
            }));
        }

        if (this.hasTargetSectionTarget) {
            this.targetSectionTarget.hidden = keys.length < 2;
        }
    }

    // ── Accounts ─────────────────────────────────────────────────────

    pickAll(event) {
        if (event.target.checked) {
            this.#accountBoxes().forEach((box) => { box.checked = false; });
        } else if (0 === this.#chosenAccounts().length) {
            event.target.checked = true;
        }
    }

    pickAccount() {
        const all = this.accountsTarget.querySelector("input[value='all']");

        all.checked = 0 === this.#chosenAccounts().length;
    }

    #accountBoxes() {
        return [...this.accountsTarget.querySelectorAll("input:not([value='all'])")];
    }

    #chosenAccounts() {
        return this.#accountBoxes().filter((box) => box.checked).map((box) => Number(box.value));
    }

    // ── Sidebar choice ───────────────────────────────────────────────

    pickSidebar(event) {
        const value = event.target.value;

        if ("__new" === value) {
            this.current = this.#add([{ t: "sys", row: "inbox" }]);
        } else if ("__dup" === value) {
            this.current = this.#add([
                { t: "sys", row: "inbox" }, { t: "sys", row: "unread" }, { t: "sys", row: "starred" },
                { t: "sys", row: "snoozed" }, { t: "sys", row: "sent" }, { t: "sys", row: "drafts" },
                { t: "block", b: "accounts" }, { t: "block", b: "labels" },
            ]);
        } else {
            this.current = value;
        }

        this.#drawSelect();
        this.#drawEditor();
    }

    #add(entries) {
        const id = "s" + Math.random().toString(36).slice(2, 10);
        const taken = new Set(this.sidebars.map((sidebar) => sidebar.name));
        let n = this.sidebars.length + 1;

        while (taken.has(this.stringsValue.newName.replace("%n%", n))) {
            n++;
        }

        this.sidebars.push({ id, name: this.stringsValue.newName.replace("%n%", n), entries });

        return id;
    }

    #drawSelect() {
        const select = this.sidebarSelectTarget;
        const option = (value, label) => {
            const el = document.createElement("option");
            el.value = value;
            el.textContent = label;
            el.selected = value === this.current;

            return el;
        };

        select.replaceChildren(
            option("", this.stringsValue.none),
            option("mail", this.stringsValue.builtIn),
            ...this.sidebars.map((sidebar) => option(sidebar.id, sidebar.name)),
            option("__new", this.stringsValue.newSidebar),
            option("__dup", this.stringsValue.duplicate),
        );
    }

    // ── The editor ───────────────────────────────────────────────────

    #sidebar() {
        return this.sidebars.find((sidebar) => sidebar.id === this.current) ?? null;
    }

    #drawEditor() {
        const sidebar = this.#sidebar();
        const editor = this.editorTarget;

        // The built-in Mail sidebar is not edited, but it has a clock switch.
        this.mailClockTarget.hidden = "mail" !== this.current;

        editor.hidden = null === sidebar;

        if (null === sidebar) {
            editor.replaceChildren();

            return;
        }

        const name = document.createElement("input");
        name.type = "text";
        name.maxLength = 40;
        name.value = sidebar.name;
        name.className = "block w-full h-9 rounded-lg border border-field-border bg-field px-3 text-sm text-ink mb-2";
        name.addEventListener("input", () => {
            sidebar.name = name.value;
            this.sidebarSelectTarget.querySelector(`option[value="${CSS.escape(sidebar.id)}"]`).textContent = name.value;
        });

        const rows = document.createElement("div");
        rows.className = "flex flex-col gap-0.5";

        sidebar.entries.forEach((entry, index) => rows.append(this.#entryRow(sidebar, entry, index)));

        const add = document.createElement("select");
        add.className = "select-field block w-full h-9 text-sm mt-2";
        add.append(new Option(this.stringsValue.groups.other + " …", ""));

        // "Other" holds what the server offers there (the clock) and a
        // heading, whose text is asked for when it is picked.
        const { other = [], ...groups } = this.catalogValue;
        const catalog = { ...groups, other: [...other, { entry: { t: "head", text: "" }, name: this.stringsValue.heading }] };

        for (const [group, items] of Object.entries(catalog)) {
            if (0 === items.length) {
                continue;
            }

            const optgroup = document.createElement("optgroup");
            optgroup.label = this.stringsValue.groups[group] ?? group;
            items.forEach((item) => optgroup.append(new Option(item.name, JSON.stringify(item.entry))));
            add.append(optgroup);
        }
        add.options[0].textContent = "+";
        add.addEventListener("change", () => {
            if ("" === add.value) {
                return;
            }

            const entry = JSON.parse(add.value);

            if ("head" === entry.t) {
                const text = window.prompt(this.stringsValue.headingText, "");

                if (null === text || "" === text.trim()) {
                    add.value = "";

                    return;
                }

                entry.text = text.trim().slice(0, 40);
            }

            sidebar.entries.push(entry);
            this.#drawEditor();
        });

        const remove = document.createElement("button");
        remove.type = "button";
        remove.className = "mt-3 text-xs text-ink-muted hover:text-danger";
        remove.textContent = this.stringsValue.deleteSidebar;
        remove.addEventListener("click", () => {
            this.sidebars = this.sidebars.filter((item) => item.id !== sidebar.id);
            this.current = "mail";
            this.#drawSelect();
            this.#drawEditor();
        });

        // Shared: say who else sees a change, the workspace being edited aside.
        const others = (this.usedByValue[sidebar.id] ?? []).filter((each) => each !== this.draft?.name);
        const used = document.createElement("p");
        used.className = "text-xs text-ink-faint mb-2";
        used.textContent = others.length > 0 ? this.stringsValue.usedBy.replace("%names%", others.join(", ")) : "";
        used.hidden = 0 === others.length;

        editor.replaceChildren(used, name, rows, add, remove);
    }

    #entryRow(sidebar, entry, index) {
        const row = document.createElement("div");
        row.className = "sidebar-entry";

        const label = document.createElement("span");
        label.className = "flex-1 min-w-0 truncate " + ("head" === entry.t ? "text-xs font-semibold text-ink-faint" : "text-ink");
        label.textContent = this.#nameOf(entry);

        const kind = document.createElement("span");
        kind.className = "text-[11px] text-ink-faint";
        kind.textContent = "block" === entry.t ? this.stringsValue.live : "";

        const button = (icon, title, disabled, act) => {
            const el = document.createElement("button");
            el.type = "button";
            el.className = "icon-btn w-7 h-7 disabled:opacity-30";
            el.title = title;
            el.setAttribute("aria-label", title);
            el.disabled = disabled;
            el.innerHTML = `<i class="fa-solid ${icon} text-[11px]" aria-hidden="true"></i>`;
            el.addEventListener("click", () => { act(); this.#drawEditor(); });

            return el;
        };

        const swap = (a, b) => { [sidebar.entries[a], sidebar.entries[b]] = [sidebar.entries[b], sidebar.entries[a]]; };

        row.append(
            label,
            kind,
            button("fa-arrow-up", this.stringsValue.moveUp, 0 === index, () => swap(index, index - 1)),
            button("fa-arrow-down", this.stringsValue.moveDown, index === sidebar.entries.length - 1, () => swap(index, index + 1)),
            button("fa-xmark", this.stringsValue.remove, false, () => sidebar.entries.splice(index, 1)),
        );

        return row;
    }

    #nameOf(entry) {
        if ("head" === entry.t) {
            return entry.text;
        }

        const key = JSON.stringify(entry);

        for (const items of Object.values(this.catalogValue)) {
            const found = items.find((item) => JSON.stringify(item.entry) === key);

            if (found) {
                return found.name;
            }
        }

        return this.stringsValue.gone;
    }

    // ── Save ─────────────────────────────────────────────────────────

    async save() {
        const accounts = this.#chosenAccounts();
        const targets = {};

        this.targetTargets.forEach((select) => {
            if (null === select.closest("[hidden]") && "" !== select.value) {
                targets[select.dataset.kind] = select.value;
            }
        });

        const sidebar = "" === this.current ? null : this.current;
        const body = {
            groups: 0 === accounts.length ? null : accounts,
            sidebar,
            targets,
            missing: this.missingTargets.find((radio) => radio.checked)?.value ?? "switch",
            mailClock: "1" === this.mailClockTarget.querySelector("input:checked")?.value,
            sidebars: this.sidebars.map((item) => ({ ...item, name: item.name.trim() || this.stringsValue.newName.replace("%n%", 1) })),
        };

        try {
            const response = await fetch(this.saveUrlValue, {
                method: "POST",
                headers: { "Content-Type": "application/json", "X-CSRF-Token": this.tokenValue, "X-Requested-With": "fetch" },
                body: JSON.stringify(body),
            });

            if (false === response.ok) {
                throw new Error(String(response.status));
            }

            // Into the draft: Save workspace keeps it, Cancel drops it.
            this.layoutWorkspaceOutlet.applySettings({ groups: body.groups, sidebar: body.sidebar, targets: body.targets, missing: body.missing });
            this.element.querySelector("[data-action~='click->ui--modal#close']")?.click();
        } catch {
            showToast(this.stringsValue.saveFailed);
        }
    }
}
