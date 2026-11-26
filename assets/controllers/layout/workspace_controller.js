import { Controller } from "@hotwired/stimulus";
import * as Turbo from "@hotwired/turbo";
import { registerPane } from "../../panes.js";
import { showToast } from "../../toast.js";
import { askConfirm } from "../../confirm.js";

/**
 * The user's workspaces (#755): which panes are on screen, how they are split,
 * and everything that changes that — the pane menu, the dividers, the keyboard,
 * the workspace tabs, the Layout menu and edit mode.
 *
 * Mounted on the mailbox shell (_layout/_mailbox.html.twig), above the topbar,
 * because the workspace tabs and the Layout button are in the topbar and a
 * Stimulus action only reaches a controller on an ancestor.
 *
 * ── The document and the DOM ─────────────────────────────────────────────
 *
 * `document` is the whole of what the server stores (Workspaces::toArray()):
 * a list of workspaces, each a tree of splits with one pane per leaf, and the
 * index of the active one. Every change edits that object, redraws the tree,
 * and posts the object whole (LayoutController). Posting it whole means a lost
 * write is mended by the next one.
 *
 * The server rendered every pane once — the ones in the workspace into the
 * tree, the rest into the park. #render() never re-renders a pane: it builds a
 * fresh set of splits and cells and MOVES the existing <section
 * data-layout-pane> elements into them. A pane's frame, its scroll position and
 * the contact open in it all survive a split or a move that way.
 * _layout/_workspace.html.twig is the server's copy of #build(); the two make
 * the same markup.
 *
 * ── Sizes belong to the device ───────────────────────────────────────────
 *
 * The user asked for exactly this: workspaces follow the account, divider
 * positions stay with the screen they were dragged on. A drag writes the
 * device's cookie (DeviceLayout reads it, so the first paint is already right)
 * and posts nothing. The workspace's own proportions — what a new device starts
 * from — change only when a workspace is made from a shape or saved in edit
 * mode, which is where somebody is deliberately arranging it.
 *
 * Full width is the device's too, for the same reason: what has the whole
 * screen on a laptop is not what has it on a monitor.
 *
 * ── Below lg ─────────────────────────────────────────────────────────────
 *
 * The tree is still the same tree; the stylesheet flattens it, and one pane at
 * a time is shown, chosen by the tab strip (`data-tab-current`). The message is
 * not a tab: it opens in place of the list there, as it always did on a phone,
 * so its element moves into the list's card (#placeReading).
 *
 * Values:
 *   document  — the stored workspaces (Workspaces::toArray())
 *   saveUrl   — POST app_layout_workspaces
 *   token     — CSRF token `layout_workspaces`, sent as X-CSRF-Token
 *   inboxUrl  — where leaving a pane page goes
 *   cookie    — the device cookie's name (DeviceLayout::COOKIE)
 *   page      — on /contacts and /tasks, the pane the page is; "" elsewhere
 *   strings   — translated toasts and pane names
 *
 * Targets:
 *   root, body, park         — the workspace, its tree, the parked panes
 *   paneTabs, workspaceTabs  — the tab strip below lg, the topbar's tabs
 *   editBar, tray, nameInput, deleteButton, drop — edit mode
 *   menuTemplate, layoutTemplate, slotTemplate, overlayTemplate,
 *   iconTemplate             — markup the script copies (_workspace_edit)
 *
 * Routes used:
 *   POST app_layout_workspaces
 */
export default class extends Controller {
    static targets = [
        "root", "body", "park", "paneTabs", "workspaceTabs",
        "editBar", "tray", "nameInput", "deleteButton", "drop",
        "menuTemplate", "layoutTemplate", "slotTemplate", "overlayTemplate", "iconTemplate", "calendarTemplate",
    ];

    /** How many calendars a workspace may show (Workspace::MAX_CALENDARS). */
    static MAX_CALENDARS = 4;

    /**
     * The panes whose place in their own pages each workspace keeps for
     * itself (#paneState): which task list, which contact or group. Each is
     * one pane in the page, shared by every workspace that shows it, so what
     * one workspace left it on is put back when that workspace is.
     */
    static STATE_KINDS = ["contacts", "tasks"];

    /** Where #paneState is kept on this device. */
    static STATE_STORE = "plmail.paneState";

    static values = {
        document: Object,
        saveUrl: String,
        token: String,
        inboxUrl: String,
        cookie: String,
        page: String,
        strings: Object,
    };

    /** The width of a divider, which a split has to leave room for. Matches app.css. */
    static DIVIDER = 8;

    /** How far an arrow key moves a divider, as a share of the two cells it divides. */
    static NUDGE = 0.02;

    /** Matches the stylesheet's lg: below it the panes are tabs. */
    static NARROW = "(max-width: 1023px)";

    /** The order a split or the tray offers new panes in, when it has to pick. */
    static OFFER = ["message", "calendar", "contacts", "tasks", "list"];

    /**
     * The Layout menu's shapes: how many slots each has and how to build its
     * tree from the panes chosen for them. The names and the button for each
     * are in the template.
     */
    static SHAPES = {
        one: { slots: 1, build: (p) => p[0] },
        side: { slots: 2, build: (p) => split("row", p, [0.4, 0.6]) },
        three: { slots: 3, build: (p) => split("row", p, [0.28, 0.44, 0.28]) },
        rows: { slots: 2, build: (p) => split("col", p, [0.5, 0.5]) },
        list_stack: { slots: 3, build: (p) => split("row", [p[0], split("col", [p[1], p[2]])], [0.36, 0.64]) },
        top_two: { slots: 3, build: (p) => split("col", [p[0], split("row", [p[1], p[2]], [0.4, 0.6])], [0.46, 0.54]) },
        list_stack_side: { slots: 4, build: (p) => split("row", [p[0], split("col", [p[1], p[2]]), p[3]], [0.27, 0.46, 0.27]) },
        four: { slots: 4, build: (p) => split("col", [split("row", [p[0], p[1]]), split("row", [p[2], p[3]])]) },
    };

    connect() {
        this.editing = null;
        this.dragging = null;
        this.popover = null;
        this.popoverAnchor = null;
        this.shapePick = null;
        this.doc = structuredClone(this.documentValue);
        this.device = this.#readCookie();
        this.slots = new Map();
        this.element.querySelectorAll("[data-layout-pane]").forEach((slot) => this.slots.set(slot.dataset.layoutPane, slot));

        this.narrow = window.matchMedia(this.constructor.NARROW);
        this._onNarrow = () => this.#arrange();
        this.narrow.addEventListener("change", this._onNarrow);

        this.maxKind = this.rootTarget.dataset.layoutMax ?? null;
        this.focusKind = this.#kinds()[0] ?? "list";
        this.tabKind = this.pageValue || (this.#kinds().includes("list") ? "list" : this.focusKind);

        // What the user is working in is the pane the keyboard acts on.
        this._onFocus = (event) => {
            const slot = event.target.closest?.("[data-layout-pane]");

            if (slot && this.rootTarget.contains(slot)) {
                this.focusKind = slot.dataset.layoutPane;
                this.rootTarget.querySelectorAll("[data-layout-focus]").forEach((el) => el.removeAttribute("data-layout-focus"));
            }
        };
        this.rootTarget.addEventListener("pointerdown", this._onFocus, true);
        this.rootTarget.addEventListener("focusin", this._onFocus);

        // And the pane under the mouse, so ⌥↩ and the rest act on what you
        // are pointing at without a click first. Only when the mouse MOVES: a
        // pane the keyboard just moved or split out from under a still mouse
        // stays the one the keys act on — ⌥⇧→ twice moves the same pane twice
        // — until the hand moves again. A browser reports a pointer event when
        // the page changes under a still pointer too, so moving is judged by
        // the coordinates, not by the event.
        this._onHover = (event) => {
            if ("mouse" !== event.pointerType || (event.clientX === this._pointerX && event.clientY === this._pointerY)) {
                return;
            }

            this._pointerX = event.clientX;
            this._pointerY = event.clientY;

            const slot = event.target.closest?.("[data-layout-pane]");

            if (slot && this.rootTarget.contains(slot) && slot.dataset.layoutPane !== this.focusKind) {
                this.focusKind = slot.dataset.layoutPane;
                this.rootTarget.querySelectorAll("[data-layout-focus]").forEach((el) => el.removeAttribute("data-layout-focus"));
            }
        };
        this.rootTarget.addEventListener("pointermove", this._onHover, { passive: true });

        this._onOutside = (event) => {
            if (this.popover && false === this.popover.contains(event.target) && false === this.popoverAnchor?.contains(event.target)) {
                this.#closePopover();
            }
        };
        document.addEventListener("pointerdown", this._onOutside, true);

        if (false === /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent)) {
            this.#altLabels(this.element);
        }

        // Anything can ask for the calendar, the address book or the tasks by
        // name — "Add to contacts" does, with an open_pane stream (panes.js).
        this._unregister = ["calendar", "contacts", "tasks"].map((kind) => registerPane(kind, {
            available: () => false === this.narrow.matches,
            isOpen: () => this.#kinds().some((key) => kindOf(key) === kind) && (null === this.maxKind || kind === kindOf(this.maxKind)),
            open: ({ src } = {}) => this.#openByName(kind, src),
            close: () => this.#close(kind),
        }));

        // A view picked in a calendar's toolbar, for frameLoaded() to tell
        // from a step to the next month: the key of the calendar it was picked
        // in. A calendar the server drew is shown already; a parked one gets
        // room for its view on its first load.
        this.slots.forEach((slot) => {
            if (slot.querySelector("[data-pane-min-width]")) {
                slot.setAttribute("data-calendar-shown", "");
            }
        });

        this._onViewPick = (event) => {
            if (event.target.closest?.("[data-calendar-views] a")) {
                this._viewPicked = event.target.closest("[data-layout-pane]")?.dataset.layoutPane ?? null;
            }
        };
        this.element.addEventListener("click", this._onViewPick, true);

        // A change still waiting to be sent goes when the page does, not with
        // it. keepalive (#post) lets the request outlive the document.
        this._onLeave = () => {
            this.#snapshotPaneStates();
            this.#flush();
        };
        window.addEventListener("pagehide", this._onLeave);

        this.#arrange();
        this.#placeThumb(false);
    }

    disconnect() {
        window.removeEventListener("pagehide", this._onLeave);
        this.element.removeEventListener("click", this._onViewPick, true);
        this.narrow.removeEventListener("change", this._onNarrow);
        this.rootTarget.removeEventListener("pointerdown", this._onFocus, true);
        this.rootTarget.removeEventListener("focusin", this._onFocus);
        this.rootTarget.removeEventListener("pointermove", this._onHover);
        document.removeEventListener("pointerdown", this._onOutside, true);
        this._unregister.forEach((unregister) => unregister());
        this.#closePopover();
        this.#endDrag();
        this.element.classList.remove("is-resizing-row", "is-resizing-col");

        // Sent, not dropped: a Turbo visit disconnects this mid-pause.
        this.#flush();
    }

    // ── The pane menu ─────────────────────────────────────────────────────

    menu(event) {
        const button = event.currentTarget;
        const slot = button.closest("[data-layout-pane]");

        if (null === slot) {
            return;
        }

        if (this.popover && this.popoverAnchor === button) {
            this.#closePopover();

            return;
        }

        const kind = slot.dataset.layoutPane;
        this.focusKind = kind;
        this.menuKind = kind;

        const menu = this.#openPopover(this.menuTemplateTarget, button);
        const present = this.#kinds();
        const onPage = "" !== this.pageValue;

        // Each pane is in a workspace once — a calendar up to MAX_CALENDARS
        // times — so a split offers only what can still be added. Nothing
        // left: say so rather than show an empty menu.
        menu.querySelectorAll("[data-split-direction]").forEach((group) => {
            const offered = [...group.querySelectorAll("[data-menu-kind]")];
            offered.forEach((item) => { item.hidden = false === this.#canAdd(item.dataset.menuKind); });
            group.querySelector("[data-menu-all-shown]").hidden = offered.some((item) => false === item.hidden);
            group.hidden = onPage;
        });

        menu.querySelectorAll("[data-menu-show]").forEach((item) => {
            const current = item.dataset.menuShow === kindOf(kind);
            item.setAttribute("aria-checked", current ? "true" : "false");
            item.classList.toggle("is-current", current);
        });

        const maxed = this.maxKind === kind;
        menu.querySelector("[data-label-max]").hidden = maxed;
        menu.querySelector("[data-label-restore]").hidden = false === maxed;
        menu.querySelector("[data-menu-move]").hidden = null !== this.maxKind || onPage;
        menu.querySelector("[data-menu-close]").disabled = present.length < 2 && false === this.#isBorrowed(kind);
        menu.querySelectorAll(".layout-menu-sub:not([data-split-direction]):not([data-menu-move])").forEach((sub) => { sub.hidden = onPage; });
        menu.querySelectorAll("hr").forEach((rule) => { rule.hidden = onPage; });

        button.setAttribute("aria-expanded", "true");

        // The menu itself takes the focus, not its first row: a submenu opens
        // on focus-within, and focusing "Split right" opened its submenu before
        // anybody pointed at it — and kept it open beside the one hovered next.
        // Tab and the arrow keys go on from here (#menuKeys).
        menu.setAttribute("tabindex", "-1");
        menu.focus({ preventScroll: true });
        menu.addEventListener("keydown", (event) => this.#menuKeys(event, menu));
    }

    /**
     * Up and down through the rows on screen, right into a submenu, left out of
     * one — what a menu's arrow keys do everywhere else.
     */
    #menuKeys(event, menu) {
        const rows = [...menu.querySelectorAll(".layout-menu-item")]
            .filter((row) => null !== row.offsetParent && false === row.disabled);
        const at = rows.indexOf(document.activeElement);

        if ("ArrowDown" === event.key || "ArrowUp" === event.key) {
            event.preventDefault();

            const inSub = document.activeElement?.closest(".layout-submenu");
            const scope = rows.filter((row) => row.closest(".layout-submenu") === inSub);
            const here = scope.indexOf(document.activeElement);
            const next = "ArrowDown" === event.key ? here + 1 : here - 1;

            scope[(next + scope.length) % scope.length]?.focus();
        } else if ("ArrowRight" === event.key && at >= 0) {
            const sub = rows[at].closest(".layout-menu-sub");

            if (sub && null === rows[at].closest(".layout-submenu")) {
                event.preventDefault();
                sub.classList.add("is-open");
                sub.querySelector(".layout-submenu .layout-menu-item:not([hidden])")?.focus();
            }
        } else if ("ArrowLeft" === event.key && document.activeElement?.closest(".layout-submenu")) {
            event.preventDefault();

            const sub = document.activeElement.closest(".layout-menu-sub");
            sub.classList.remove("is-open");
            sub.querySelector(":scope > .layout-menu-item")?.focus();
        }
    }

    /** A submenu's row pressed: for touch, where there is no hover to open it. */
    toggleSubmenu(event) {
        const sub = event.currentTarget.closest(".layout-menu-sub");
        this.popover?.querySelectorAll(".layout-menu-sub.is-open").forEach((other) => other !== sub && other.classList.remove("is-open"));
        sub.classList.toggle("is-open");
    }

    splitFromMenu({ params: { direction, kind } }) {
        this.#closePopover();
        this.#split(this.menuKind, direction, this.#newKey(kind));
    }

    showFromMenu({ params: { kind } }) {
        this.#closePopover();
        this.#show(this.menuKind, this.#keyFor(kind, this.menuKind));
    }

    moveFromMenu({ params: { direction } }) {
        this.#closePopover();
        this.#move(this.menuKind, direction);
    }

    toggleMaxFromMenu() {
        this.#closePopover();
        this.#toggleMax(this.menuKind);
    }

    closeFromMenu() {
        this.#closePopover();
        this.#close(this.menuKind);
    }

    /** The ↙ in a pane at full width. */
    restore(event) {
        event?.preventDefault();
        this.#setMax(null);
    }

    // ── Tabs, below lg ────────────────────────────────────────────────────

    tab({ params: { kind } }) {
        this.tabKind = kind;
        this.#arrange();
    }

    // ── Dividers ──────────────────────────────────────────────────────────

    startResize(event) {
        if (0 !== event.button || this.narrow.matches) {
            return;
        }

        event.preventDefault();

        const divider = event.currentTarget;
        const { split, index, before, after, row } = this.#dividerContext(divider);

        if (null === split || undefined === before || undefined === after) {
            return;
        }

        const startBefore = row ? before.getBoundingClientRect().width : before.getBoundingClientRect().height;
        const startAfter = row ? after.getBoundingClientRect().width : after.getBoundingClientRect().height;
        const total = startBefore + startAfter;
        const share = split.sizes[index] + split.sizes[index + 1];
        let [minBefore, minAfter] = [this.#minSize(split.children[index], row), this.#minSize(split.children[index + 1], row)];

        // Both minimums cannot be met — the window is smaller than this
        // arrangement wants. The divider still moves, within the middle.
        if (minBefore + minAfter > total) {
            minBefore = total * 0.15;
            minAfter = total * 0.15;
        }

        const origin = row ? event.clientX : event.clientY;
        divider.setPointerCapture(event.pointerId);
        divider.classList.add("is-dragging");
        this.element.classList.add(row ? "is-resizing-row" : "is-resizing-col");

        const move = (moved) => {
            const delta = (row ? moved.clientX : moved.clientY) - origin;
            const nextBefore = Math.min(total - minAfter, Math.max(minBefore, startBefore + delta));

            split.sizes[index] = share * nextBefore / total;
            split.sizes[index + 1] = share - split.sizes[index];
            before.style.flexGrow = split.sizes[index].toFixed(4);
            after.style.flexGrow = split.sizes[index + 1].toFixed(4);
            this.#updateEditSizes();
        };

        // Ends the drag however it ends, once. Heard on the window, not the
        // divider: a divider taken out of the page mid-drag (a render) never
        // hears its pointerup — nor its lostpointercapture, which goes to the
        // document — and the resize cursor, a class on the root, stayed on
        // everything until a reload.
        let ended = false;
        const up = () => {
            if (ended) {
                return;
            }

            ended = true;
            window.removeEventListener("pointermove", move);
            window.removeEventListener("pointerup", up);
            window.removeEventListener("pointercancel", up);
            divider.classList.remove("is-dragging");
            this.element.classList.remove("is-resizing-row", "is-resizing-col");
            this.#rememberSizes(split);
        };

        window.addEventListener("pointermove", move);
        window.addEventListener("pointerup", up);
        window.addEventListener("pointercancel", up);
    }

    /** Arrow keys on a focused divider; the arrows across its line, not along it. */
    nudge(event) {
        const { split, index, before, after, row } = this.#dividerContext(event.currentTarget);

        if (null === split || undefined === before || undefined === after) {
            return;
        }

        const keys = row ? { ArrowLeft: -1, ArrowRight: 1 } : { ArrowUp: -1, ArrowDown: 1 };
        const sign = keys[event.key];

        if (undefined === sign) {
            return;
        }

        event.preventDefault();

        const share = split.sizes[index] + split.sizes[index + 1];
        const step = share * this.constructor.NUDGE * sign;
        const min = share * 0.1;

        split.sizes[index] = Math.min(share - min, Math.max(min, split.sizes[index] + step));
        split.sizes[index + 1] = share - split.sizes[index];
        before.style.flexGrow = split.sizes[index].toFixed(4);
        after.style.flexGrow = split.sizes[index + 1].toFixed(4);
        this.#updateEditSizes();
        this.#rememberSizes(split);
    }

    /** Double-click: the two sides of this divider even again. */
    evenOut(event) {
        const { split, index, before, after } = this.#dividerContext(event.currentTarget);

        if (null === split || undefined === before || undefined === after) {
            return;
        }
        const half = (split.sizes[index] + split.sizes[index + 1]) / 2;

        split.sizes[index] = half;
        split.sizes[index + 1] = half;
        before.style.flexGrow = half.toFixed(4);
        after.style.flexGrow = half.toFixed(4);
        this.#updateEditSizes();
        this.#rememberSizes(split);
    }

    // ── Workspaces ────────────────────────────────────────────────────────

    switch({ params: { index } }) {
        this.#switchTo(Number(index));
    }

    layoutMenu(event) {
        const button = event.currentTarget;

        if (this.popover && this.popoverAnchor === button) {
            this.#closePopover();

            return;
        }

        if (this.editing) {
            return;
        }

        const menu = this.#openPopover(this.layoutTemplateTarget, button);
        this.shapePick = null;
        this.#fillLayoutMenu(menu);
        button.setAttribute("aria-expanded", "true");
        menu.setAttribute("tabindex", "-1");
        menu.focus({ preventScroll: true });
    }

    pickShape({ params: { shape } }) {
        const spec = this.constructor.SHAPES[shape];
        const current = this.#kinds();
        const rest = ["list", "message", "calendar", "contacts", "tasks"].filter((kind) => false === current.includes(kind));

        this.shapePick = { shape, kinds: [...current, ...rest].slice(0, spec.slots) };
        this.#fillLayoutMenu(this.popover);
    }

    unpickShape() {
        this.shapePick = null;
        this.#fillLayoutMenu(this.popover);
    }

    /** One slot's choice. A kind already in another slot swaps places with it: each pane once. */
    pickSlot(event) {
        const select = event.currentTarget;
        const at = Number(select.dataset.slot);
        const kind = select.value;
        const kinds = this.shapePick.kinds;
        const other = kinds.indexOf(kind);

        if (-1 !== other && other !== at) {
            kinds[other] = kinds[at];
        }

        kinds[at] = kind;
        this.#fillLayoutMenu(this.popover);
    }

    shapeApply() {
        const tree = this.#shapeTree();

        this.#workspace().layout = tree;
        this.#forgetDevice(this.#workspace().id);
        this.maxKind = null;
        this.#closePopover();
        this.#commit();
        this.#toast(this.stringsValue.applied);
    }

    shapeAsNew() {
        if (this.doc.workspaces.length >= 9) {
            return;
        }

        const workspace = { id: newId("w"), name: this.#newName(), layout: this.#shapeTree() };

        this.doc.workspaces.push(workspace);
        this.#closePopover();
        this.#switchTo(this.doc.workspaces.length - 1);
        this.#toast(this.stringsValue.saved.replace("%name%", workspace.name));
    }

    newWorkspace() {
        if (this.doc.workspaces.length >= 9) {
            return;
        }

        const copy = structuredClone(this.#workspace());
        copy.id = newId("w");
        copy.name = this.#newName();
        this.#renameSplits(copy.layout);

        this.doc.workspaces.push(copy);
        this.#closePopover();
        this.#switchTo(this.doc.workspaces.length - 1);
        this.edit();
    }

    // ── Edit mode ─────────────────────────────────────────────────────────

    edit() {
        this.#closePopover();

        if (this.narrow.matches || this.editing || "" !== this.pageValue) {
            return;
        }

        this.#setMax(null, { quiet: true });
        this.editing = {
            snapshot: structuredClone(this.#workspace()),
            device: structuredClone(this.device[this.#workspace().id] ?? null),
            selected: this.#kinds().includes(this.focusKind) ? this.focusKind : this.#kinds()[0],
        };

        this.element.setAttribute("data-layout-editing", "");
        this.editBarTarget.hidden = false;
        this.trayTarget.hidden = false;
        this.nameInputTarget.value = this.#workspace().name;
        this.deleteButtonTarget.hidden = this.doc.workspaces.length < 2;
        this.#decorate();
        this.nameInputTarget.focus({ preventScroll: true });
    }

    rename() {
        const name = this.nameInputTarget.value.trim();

        if ("" !== name) {
            this.#workspace().name = name.slice(0, 40);
            this.#drawWorkspaceTabs();
        }
    }

    saveEdit() {
        if (null === this.editing) {
            return;
        }

        // Edit mode lets a pane go below its minimum on the way to an
        // arrangement (see #roomFor). On the way out, each pane narrower than
        // its minimum takes what its row-neighbours can spare above theirs.
        this.#kinds().forEach((kind) => this.#widen(kind, this.#slotMin(kind, true)));

        // What the workspace looks like now becomes what a new device starts
        // from: this is the one place the user is deliberately arranging it.
        this.#adoptDeviceSizes(this.#workspace().layout);
        this.#leaveEdit();
        this.#commit();

        // Asked for in so many words, so sent now rather than after the pause
        // a burst of menu changes waits for: a reload straight after Save drew
        // the workspace from before it, because the save had not gone yet.
        this.#post();
        this.#toast(this.stringsValue.saved.replace("%name%", this.#workspace().name));
    }

    cancelEdit() {
        if (null === this.editing) {
            return;
        }

        this.doc.workspaces[this.doc.active] = this.editing.snapshot;

        if (null === this.editing.device) {
            delete this.device[this.#workspace().id];
        } else {
            this.device[this.#workspace().id] = this.editing.device;
        }

        this.#leaveEdit();
        this.#render();
        this.#drawWorkspaceTabs();

        // What was there before, which the server already has — unless this
        // is a new workspace (newWorkspace() starts one in edit mode), whose
        // copy is kept as it was made.
        this.#save();
    }

    async deleteWorkspace() {
        if (null === this.editing || this.doc.workspaces.length < 2) {
            return;
        }

        const name = this.#workspace().name;

        if (false === await askConfirm(this.stringsValue.deleteConfirm.replace("%name%", name))) {
            return;
        }

        this.#forgetDevice(this.#workspace().id);
        this.doc.workspaces.splice(this.doc.active, 1);
        this.doc.active = 0;
        this.#leaveEdit();
        this.maxKind = this.#deviceMax();
        this.#commit();
        this.#toast(this.stringsValue.deleted.replace("%name%", name));
    }

    selectPane(event) {
        if (event.target.closest("select, button")) {
            return;
        }

        const kind = event.currentTarget.closest("[data-layout-pane]")?.dataset.layoutPane;

        if (kind && this.editing) {
            this.editing.selected = kind;
            this.#decorate();
        }
    }

    changeKind(event) {
        const kind = event.currentTarget.closest("[data-layout-pane]")?.dataset.layoutPane;

        const key = this.#keyFor(event.currentTarget.value, kind);

        this.#show(kind, key);
        this.editing.selected = key;
        this.#decorate();
    }

    removePane(event) {
        const kind = event.currentTarget.closest("[data-layout-pane]")?.dataset.layoutPane;

        if (this.#kinds().length < 2) {
            this.#toast(this.stringsValue.onlyPane);

            return;
        }

        this.#remove(kind);
        this.editing.selected = this.#kinds()[0];
        this.#render();
    }

    edgeSplit({ params: { direction }, currentTarget }) {
        const kind = currentTarget.closest("[data-layout-pane]")?.dataset.layoutPane;
        const next = this.#offer();

        if (null === next) {
            this.#toast(this.stringsValue.allShown);

            return;
        }

        if (this.#insertBeside(kind, paneNode(next), direction, false)) {
            this.editing.selected = next;
            this.#render();
        }
    }

    addFromTray(event) {
        if (true === this._suppressClick) {
            this._suppressClick = false;

            return;
        }

        const kind = this.#newKey(event.params.kind);
        const target = this.editing?.selected ?? this.#kinds()[0];

        if (null === kind) {
            return;
        }

        if (this.#insertBeside(target, paneNode(kind), "row", false) || this.#insertBeside(target, paneNode(kind), "col", false, { quiet: true })) {
            this.editing.selected = kind;
            this.#render();
        }
    }

    dragPane(event) {
        const kind = event.currentTarget.closest("[data-layout-pane]")?.dataset.layoutPane;

        this.#startDrag(event, { kind, existing: true });
    }

    dragNew(event) {
        // A calendar dragged in beside another is a further one, with an id of
        // its own; anything else on screen is not in the tray at all.
        const kind = this.#newKey(event.params.kind);

        if (null !== kind) {
            this.#startDrag(event, { kind, existing: false });
        }
    }

    // ── Keyboard ──────────────────────────────────────────────────────────

    key(event) {
        if ("Escape" === event.key) {
            if (this.dragging) {
                this.#endDrag();
            } else if (this.popover) {
                this.#closePopover();
            }

            return;
        }

        if (false === event.altKey || event.metaKey || event.ctrlKey || this.narrow.matches) {
            return;
        }

        // ⌥ in a field is a word move or a special character; not ours.
        if (event.target.closest?.("input, textarea, select, [contenteditable]:not([contenteditable='false'])")) {
            return;
        }

        const arrows = { ArrowLeft: "left", ArrowRight: "right", ArrowUp: "up", ArrowDown: "down" };
        const focus = this.#kinds().includes(this.focusKind) ? this.focusKind : this.#kinds()[0];
        let handled = true;

        if (undefined !== arrows[event.code] && false === Boolean(this.editing)) {
            event.shiftKey ? this.#move(focus, arrows[event.code]) : this.#focusTowards(arrows[event.code]);
        } else if ("Backslash" === event.code && false === Boolean(this.editing)) {
            this.#split(focus, "row");
        } else if ("Minus" === event.code && false === Boolean(this.editing)) {
            this.#split(focus, "col");
        } else if ("Enter" === event.code && false === Boolean(this.editing)) {
            this.#toggleMax(focus);
        } else if ("KeyW" === event.code && false === Boolean(this.editing)) {
            this.#close(focus);
        } else if ("KeyE" === event.code) {
            this.editing ? this.saveEdit() : this.edit();
        } else if ("KeyL" === event.code && false === Boolean(this.editing)) {
            this.element.querySelector("[data-layout-menu-button]")?.click();
        } else if (/^Digit[1-9]$/.test(event.code)) {
            this.#switchTo(Number(event.code.slice(5)) - 1);
        } else {
            handled = false;
        }

        if (handled) {
            event.preventDefault();
        }
    }

    // ── From the panes ────────────────────────────────────────────────────

    /**
     * A frame in the shell finished loading.
     *
     * The list: somebody went to the mail — a folder in the sidebar, a search.
     * It has to be on screen, whatever covers it: full width on another pane
     * gives way, a list the workspace does not show is borrowed at full width,
     * and below lg the list becomes the tab. The old calendar switch demoted
     * itself the same way (#755 replaced it).
     *
     * The calendar: a view PICKED in its toolbar, or the view a parked
     * calendar first loads with, gets the room it needs — a week or a month
     * is seven columns (CalendarView::minimumPaneWidth(), read from INSIDE the
     * frame because Turbo keeps the frame element's own attributes from the
     * first load). The pane is widened at its row-neighbours' expense, never
     * narrowed: a pane somebody dragged wider keeps its width. Its MINIMUM
     * stays the calendar's floor (Day's width), so the divider can still take
     * a month narrower; it used to rise with the view, which left a pane
     * showing Month unable to be dragged below 720px when Day was a click
     * away. Stepping to the next month is a load too, and does not throw a
     * pane dragged narrow wide again.
     */
    frameLoaded(event) {
        const frame = event.target;

        if ("inbox-list-frame" === frame?.id) {
            this.#bringListForward();

            return;
        }

        // Any calendar: the first's frame is `calendar-pane-frame`, a further
        // one's adds its id.
        if (false === (frame?.id ?? "").startsWith("calendar-pane-frame")) {
            return;
        }

        const wanted = Number(frame.querySelector("[data-pane-min-width]")?.dataset.paneMinWidth ?? 0);
        const slot = frame.closest("[data-layout-pane]");
        const key = slot?.dataset.layoutPane;

        if (false === Number.isFinite(wanted) || 0 === wanted || null === slot || undefined === key) {
            return;
        }

        const picked = key === this._viewPicked || false === slot.hasAttribute("data-calendar-shown");

        if (key === this._viewPicked) {
            this._viewPicked = null;
        }

        slot.setAttribute("data-calendar-shown", "");

        if (picked) {
            this.#widen(key, wanted);
        }
    }

    resized() {
        this.#updateEditSizes();
    }

    // ── Private: the tree ─────────────────────────────────────────────────

    #workspace() {
        return this.doc.workspaces[this.doc.active];
    }

    /** The kinds on screen in the active workspace, in reading order. */
    #kinds() {
        return leaves(this.#workspace().layout);
    }

    /**
     * What a split adds when nothing was picked: the first kind the workspace
     * does not show yet, in OFFER order, and with every kind on screen a
     * further calendar while there is room for one. As a key.
     */
    #offer() {
        const present = this.#kinds();
        const absent = this.constructor.OFFER.find((kind) => false === present.includes(kind));

        return absent ?? this.#newKey("calendar");
    }

    /** Whether a pane of `kind` can still be added to the active workspace. */
    #canAdd(kind) {
        const present = this.#kinds();

        return false === present.includes(kind)
            || ("calendar" === kind && present.filter((key) => "calendar" === kindOf(key)).length < this.constructor.MAX_CALENDARS);
    }

    /**
     * The key a new pane of `kind` gets: the kind itself when the workspace
     * does not show it, a further calendar (`calendar~<id>`) when it is a
     * calendar and there is room for another, else null — every other kind is
     * in a workspace once.
     */
    #newKey(kind) {
        // A calendar's key is its own across workspaces (see #ownCalendars),
        // so the plain one is taken while ANY workspace shows it.
        const taken = "calendar" === kind ? this.doc.workspaces.flatMap((workspace) => leaves(workspace.layout)) : this.#kinds();

        if (false === taken.includes(kind) && false === this.#kinds().includes(kind)) {
            return kind;
        }

        return this.#canAdd(kind) ? `${kind}~${newId("c")}` : null;
    }

    /**
     * Give each calendar in `workspace` whose key another workspace uses an
     * id of its own. A calendar is one pane wherever its key appears — one
     * frame, one remembered view — so a calendar copied into a new workspace,
     * or put there by a shape, would otherwise BE the other workspace's, and
     * picking Agenda in one switched both. The server does the same for
     * documents made before this (Workspaces::ownCalendars()).
     */
    #ownCalendars(workspace) {
        const elsewhere = new Set(this.doc.workspaces.filter((other) => other !== workspace).flatMap((other) => leaves(other.layout)));
        const visit = (node) => {
            if ("pane" === node.t) {
                if ("calendar" === node.kind && elsewhere.has(keyOf(node))) {
                    const before = keyOf(node);

                    node.id = newId("c");

                    if (this.maxKind === before) {
                        this.maxKind = keyOf(node);
                    }
                }

                return;
            }

            node.children.forEach(visit);
        };

        visit(workspace.layout);
    }

    /**
     * The key to put in the place of the pane `at` when `kind` is chosen for
     * it (Show instead, an edit card's picker): a calendar picked for a pane
     * that is not one is a further calendar while there is room, so the one
     * already on screen stays; anything else is the pane of that kind, which
     * swaps places if it is on screen elsewhere.
     */
    #keyFor(kind, at) {
        if (kindOf(at) === kind) {
            return at;
        }

        return "calendar" === kind ? (this.#newKey(kind) ?? kind) : kind;
    }

    /**
     * The section of the pane of `key`. A further calendar this page did not
     * draw is made here, from calendarTemplate, and parked; its frame is lazy
     * until #arrange() places it.
     */
    #slot(key) {
        if (this.slots.has(key) || "calendar" !== kindOf(key) || false === this.hasCalendarTemplateTarget) {
            return this.slots.get(key);
        }

        const id = key.split("~")[1] ?? "";

        if (false === /^[a-z0-9]{1,16}$/.test(id)) {
            return undefined;
        }

        const html = this.calendarTemplateTarget.innerHTML.replaceAll("__KEY__", key).replaceAll("__ID__", id);
        const holder = document.createElement("template");
        holder.innerHTML = html.trim();

        const slot = holder.content.firstElementChild;
        this.parkTarget.append(slot);
        this.slots.set(key, slot);

        return slot;
    }

    /** What the pane of `key` is called: its kind's name. */
    #name(key) {
        return this.stringsValue.kinds[kindOf(key)] ?? key;
    }

    #split(kind, direction, newKind = undefined) {
        const next = undefined === newKind ? this.#offer() : newKind;

        if (null === next) {
            this.#toast(this.stringsValue.allShown);

            return;
        }

        if ("" !== this.pageValue) {
            return;
        }

        if (this.#insertBeside(kind, paneNode(next), direction, false)) {
            this.maxKind = null;
            this.focusKind = next;
            this.tabKind = next;
            this.#commit();
        }
    }

    /**
     * Put `pane` beside the pane of `kind`: after it, or before it, in a split
     * of `direction`. Into the parent split when it already runs that way —
     * one row of three, not a row holding a row — else the pane becomes a
     * split of two. The pane being split gives up half its share.
     *
     * Refused, with a toast, when the pane is too small to hold both at their
     * minimums. Returns whether it happened.
     */
    #insertBeside(kind, pane, direction, before, { quiet = false } = {}) {
        const root = this.#workspace().layout;
        const found = findPane(root, kind);

        if (null === found || false === this.#roomFor(kind, keyOf(pane), direction, quiet)) {
            return false;
        }

        const { parent, index, node } = found;

        if (null !== parent && parent.dir === direction) {
            const half = parent.sizes[index] / 2;
            const at = before ? index : index + 1;

            parent.sizes[index] = half;
            parent.children.splice(at, 0, pane);
            parent.sizes.splice(at, 0, half);
        } else {
            const pair = split(direction, before ? [pane, node] : [node, pane]);

            if (null === parent) {
                this.#workspace().layout = pair;
            } else {
                parent.children[index] = pair;
            }
        }

        this.#workspace().layout = normalise(this.#workspace().layout);

        return true;
    }

    /**
     * Whether the pane of `kind` has room for itself and a pane of `other` in
     * `direction`.
     *
     * Not asked in edit mode. There the user is arranging on purpose, sees
     * every pane's share as it changes, and a move frees the room the moved
     * pane had — which a check against the target alone cannot see, so it
     * refused moves that fit. A pane left below its minimum there is one the
     * dividers can still fix before Save.
     */
    #roomFor(kind, other, direction, quiet = false) {
        const slot = this.slots.get(kind);

        if (this.editing || this.narrow.matches || undefined === slot || 0 === slot.offsetWidth) {
            return true;
        }

        const row = "row" === direction;
        const rect = slot.getBoundingClientRect();
        const have = (row ? rect.width : rect.height) - this.constructor.DIVIDER;
        const need = this.#slotMin(kind, row) + this.#slotMin(other, row);

        if (have >= need) {
            return true;
        }

        if (false === quiet) {
            this.#toast(this.stringsValue.noRoom.replace("%pane%", this.#name(kind)));
        }

        return false;
    }

    /** Put `kind` in the place of the pane `at`. A kind already elsewhere swaps places with it. */
    #show(at, kind) {
        if (at === kind) {
            return;
        }

        const root = this.#workspace().layout;
        const target = findPane(root, at);
        const other = findPane(root, kind);

        if (null === target) {
            return;
        }

        if (null !== other) {
            setKey(other.node, at);
        }

        setKey(target.node, kind);

        if (this.maxKind === at) {
            this.maxKind = kind;
        }

        this.focusKind = kind;
        this.tabKind = this.tabKind === at ? kind : this.tabKind;
        this.#commit();
    }

    #remove(kind) {
        const root = this.#workspace().layout;
        const found = findPane(root, kind);

        if (null === found || null === found.parent) {
            return false;
        }

        found.parent.children.splice(found.index, 1);
        found.parent.sizes.splice(found.index, 1);
        this.#workspace().layout = normalise(root);

        return true;
    }

    #close(kind) {
        if (this.#isBorrowed(kind)) {
            this.#setMax(null);

            return;
        }

        if (this.#kinds().length < 2) {
            this.#toast(this.stringsValue.onlyPane);

            return;
        }

        if (false === this.#remove(kind)) {
            return;
        }

        if (this.maxKind === kind) {
            this.maxKind = null;
        }

        this.focusKind = this.#kinds()[0];
        this.tabKind = this.#kinds().includes(this.tabKind) ? this.tabKind : this.focusKind;
        this.#commit();
    }

    /**
     * Swap with the neighbouring pane that way; with none, leave the group and
     * become a full-height column (left, right) or a full-width row (up, down)
     * at that edge of the window. The second is what makes any arrangement
     * reachable from any other by moves alone.
     */
    #move(kind, direction) {
        if (null !== this.maxKind || "" !== this.pageValue) {
            return;
        }

        const neighbour = this.#neighbour(kind, direction);
        const root = this.#workspace().layout;

        if (null !== neighbour) {
            const a = findPane(root, kind);
            const b = findPane(root, neighbour);
            setKey(a.node, neighbour);
            setKey(b.node, kind);
            this.focusKind = kind;
            this.#commit();
            this.#flagFocus(kind);

            return;
        }

        const horizontal = "left" === direction || "right" === direction;
        const before = "left" === direction || "up" === direction;

        if ("pane" === root.t || (root.dir === (horizontal ? "row" : "col") && root.children.some((child) => "pane" === child.t && keyOf(child) === kind))) {
            this.#toast(this.stringsValue.atEdge);

            return;
        }

        this.#remove(kind);
        const pane = paneNode(kind);
        const rest = this.#workspace().layout;
        this.#workspace().layout = normalise(split(horizontal ? "row" : "col", before ? [pane, rest] : [rest, pane], before ? [0.3, 0.7] : [0.7, 0.3]));
        this.focusKind = kind;
        this.#commit();
        this.#flagFocus(kind);
    }

    /** ⌥ + arrow: the nearest pane that way, by what is on screen. */
    #focusTowards(direction) {
        const kind = this.#neighbour(this.focusKind, direction);

        if (null === kind) {
            return;
        }

        this.focusKind = kind;
        this.#flagFocus(kind);
    }

    /** A ring round the pane the keyboard just moved to, until the pointer is used. */
    #flagFocus(kind) {
        this.rootTarget.querySelectorAll("[data-layout-focus]").forEach((el) => el.removeAttribute("data-layout-focus"));

        const slot = this.slots.get(kind);

        if (undefined === slot) {
            return;
        }

        slot.setAttribute("data-layout-focus", "");
        slot.setAttribute("tabindex", "-1");
        slot.focus({ preventScroll: true });
    }

    /** The pane on screen next to `kind` in `direction`: overlapping across, nearest along. */
    #neighbour(kind, direction) {
        const from = this.slots.get(kind)?.getBoundingClientRect();

        if (undefined === from || 0 === from.width) {
            return null;
        }

        let best = null;
        let bestScore = Infinity;

        for (const other of this.#kinds()) {
            if (other === kind) {
                continue;
            }

            const to = this.slots.get(other)?.getBoundingClientRect();

            if (undefined === to || 0 === to.width) {
                continue;
            }

            const acrossY = Math.min(to.bottom, from.bottom) - Math.max(to.top, from.top);
            const acrossX = Math.min(to.right, from.right) - Math.max(to.left, from.left);
            const fits = {
                left: to.right <= from.left + 2 && acrossY > 0,
                right: to.left >= from.right - 2 && acrossY > 0,
                up: to.bottom <= from.top + 2 && acrossX > 0,
                down: to.top >= from.bottom - 2 && acrossX > 0,
            }[direction];

            if (false === fits) {
                continue;
            }

            const along = { left: from.left - to.right, right: to.left - from.right, up: from.top - to.bottom, down: to.top - from.bottom }[direction];
            const offset = "left" === direction || "right" === direction
                ? Math.abs((to.top + to.bottom) / 2 - (from.top + from.bottom) / 2)
                : Math.abs((to.left + to.right) / 2 - (from.left + from.right) / 2);
            const score = along * 1000 + offset;

            if (score < bestScore) {
                bestScore = score;
                best = other;
            }
        }

        return best;
    }

    #toggleMax(kind) {
        this.#setMax(this.maxKind === kind ? null : kind);
    }

    /**
     * Full width on and off. On a pane page there is no list behind the pane
     * (see the page shells), so leaving full width there is the mail, visited.
     */
    #setMax(kind, { quiet = false } = {}) {
        if (null === kind && "" !== this.pageValue) {
            Turbo.visit(this.inboxUrlValue);

            return;
        }

        // Asked again for what is already so — every folder opened in a
        // workspace without a list asks for the list at full width — it is left
        // alone: a render moves every pane, and a pane moved is a pane whose
        // controllers start over, with whatever was being typed in it.
        if (kind === this.maxKind) {
            return;
        }

        this.maxKind = kind;
        this.#rememberMax();

        if (false === quiet) {
            this.#render();
        }
    }

    #isBorrowed(kind) {
        return null !== this.maxKind && kind === this.maxKind && false === this.#kinds().includes(kind);
    }

    /**
     * Somebody asked for the mail: put the list where it can be seen. See
     * frameLoaded().
     */
    #bringListForward() {
        if (this.narrow.matches) {
            if ("list" !== this.tabKind) {
                this.tabKind = "list";
                this.#arrange();
            }

            return;
        }

        const present = this.#kinds();

        if (present.includes("list") && (null === this.maxKind || "list" === this.maxKind || "message" === this.maxKind)) {
            return;
        }

        if ("" !== this.pageValue) {
            return;
        }

        if (present.includes("list")) {
            this.#setMax(null);

            return;
        }

        // No list here: the first workspace that has one, in the order of
        // the tabs, which is where the mail was put. Only with none anywhere
        // is the list borrowed at full width over this one. Not from edit
        // mode, which keeps its workspace until it is saved or cancelled.
        const other = this.doc.workspaces.findIndex((workspace) => leaves(workspace.layout).includes("list"));

        if (-1 === other || null !== this.editing) {
            this.#setMax("list");

            return;
        }

        // No toast: the tab sliding over, with a spark, says where the mail went.
        this._switchedBy = "spark";
        this.#switchTo(other);

        // That workspace may have something else at full width on this
        // device: the list is what was asked for.
        this.#bringListForward();
    }

    /** openPane() from anywhere: on screen, beside what is there, showing `src`. */
    #openByName(kind, src) {
        // The first pane of that kind this workspace shows — for a calendar,
        // whichever calendar that is — or a new one with a key of its own.
        const shown = this.#kinds().find((key) => kindOf(key) === kind) ?? null;
        const key = shown ?? (kindOf(this.maxKind) === kind ? this.maxKind : (this.#newKey(kind) ?? kind));

        if (null === shown && key !== this.maxKind) {
            const root = this.#workspace().layout;
            const pane = paneNode(key);

            this.#workspace().layout = normalise("split" === root.t && "row" === root.dir
                ? { ...root, children: [...root.children, pane], sizes: [...root.sizes.map((size) => size * 0.7), 0.3] }
                : split("row", [root, pane], [0.7, 0.3]));
            this.#commit();
        } else if (null !== this.maxKind && key !== this.maxKind) {
            this.#setMax(null);
        }

        const frame = this.#slot(key)?.querySelector("turbo-frame");

        if (frame && src) {
            if (new URL(src, location.href).href === frame.src) {
                frame.reload();
            } else {
                frame.src = src;
            }
        }
    }

    /**
     * Where the pane of `kind` was in the active workspace when it was last
     * shown there — a path, `/tasks?view=lists&list=7` — or null for its
     * start (`data-layout-src`). Kept per workspace and per device, beside the
     * divider positions, in localStorage; not on a pane page, which shows
     * whatever its own address says.
     */
    #paneState(kind) {
        if (false === this.constructor.STATE_KINDS.includes(kind) || "" !== this.pageValue) {
            return null;
        }

        return this.#paneStates()[this.#workspace().id]?.[kind] ?? null;
    }

    /**
     * Note where the active workspace's contacts and tasks are: when it is
     * left, and when the page goes. Read from the frame's `src`, which Turbo
     * sets as a navigation starts. Not on every frame load, which was how it
     * started: a load begun for one workspace can finish after a quick switch
     * to another, and was then put down as the other's.
     */
    #snapshotPaneStates() {
        if ("" !== this.pageValue) {
            return;
        }

        const shown = [...this.#kinds(), this.maxKind];

        this.constructor.STATE_KINDS.filter((kind) => shown.includes(kind)).forEach((kind) => {
            const frame = this.slots.get(kind)?.querySelector("turbo-frame");

            if (frame && false === frame.hasAttribute("data-layout-lazy")) {
                this.#keepPaneState(kind, frame.getAttribute("src"));
            }
        });
    }

    /**
     * Send the pane's frame to `wanted`. A remembered place can have gone — a
     * contact deleted since. Its address then answers 404, which Turbo meets
     * by leaving the frame as it was, stuck on an address that is not there;
     * or with a page that has no frame for the pane, which Turbo answers by
     * visiting that page whole, taking the mail with it. Either way the place
     * is forgotten and the pane goes to its start instead.
     */
    #restore(frame, kind, wanted, start) {
        if (wanted !== start) {
            const back = () => {
                frame.removeEventListener("turbo:before-fetch-response", answered);
                frame.removeEventListener("turbo:frame-missing", missing);
                this.#forgetPaneState(kind);
                queueMicrotask(() => frame.setAttribute("src", start));
            };
            const answered = (event) => {
                frame.removeEventListener("turbo:before-fetch-response", answered);

                if (false === event.detail.fetchResponse?.succeeded) {
                    back();
                }
            };
            const missing = (event) => {
                event.preventDefault();
                back();
            };

            frame.addEventListener("turbo:before-fetch-response", answered);
            frame.addEventListener("turbo:frame-missing", missing, { once: true });
            frame.addEventListener("turbo:frame-load", () => frame.removeEventListener("turbo:frame-missing", missing), { once: true });
        }

        frame.setAttribute("src", wanted);
    }

    #forgetPaneState(kind) {
        const states = this.#paneStates();

        delete states[this.#workspace().id]?.[kind];

        try {
            localStorage.setItem(this.constructor.STATE_STORE, JSON.stringify(states));
        } catch {
            // Nothing kept, nothing to forget.
        }
    }

    /** Note where the pane of `kind` now is, for the active workspace. */
    #keepPaneState(kind, src) {
        const start = this.slots.get(kind)?.dataset.layoutSrc;

        if ("" !== this.pageValue || !src || !start) {
            return;
        }

        const place = new URL(src, location.href);

        // Only the pane's own pages: a frame that ended up elsewhere is not a
        // place to come back to.
        if (place.origin !== location.origin || false === place.pathname.startsWith(new URL(start, location.href).pathname)) {
            return;
        }

        const states = this.#paneStates();
        const ids = new Set(this.doc.workspaces.map((workspace) => workspace.id));
        const id = this.#workspace().id;

        states[id] = { ...(states[id] ?? {}), [kind]: place.pathname + place.search };
        Object.keys(states).forEach((key) => ids.has(key) || delete states[key]);

        try {
            localStorage.setItem(this.constructor.STATE_STORE, JSON.stringify(states));
        } catch {
            // Private mode, or full: the pane just starts at its start.
        }
    }

    #paneStates() {
        try {
            const states = JSON.parse(localStorage.getItem(this.constructor.STATE_STORE) ?? "{}");

            return null !== states && "object" === typeof states ? states : {};
        } catch {
            return {};
        }
    }

    /**
     * Give the pane of `kind` at least `width` pixels, out of its row
     * neighbours' room above their own minimums. Only ever wider.
     */
    #widen(kind, width) {
        const found = this.#rowCellOf(kind);

        if (null === found || this.narrow.matches || null !== this.maxKind) {
            return;
        }

        const { split: row, index, cells } = found;
        const have = cells[index].getBoundingClientRect().width;

        if (have >= width) {
            return;
        }

        const widths = cells.map((cell) => cell.getBoundingClientRect().width);
        const total = widths.reduce((sum, value) => sum + value, 0);
        let need = width - have;

        widths.forEach((cellWidth, i) => {
            if (i === index || need <= 0) {
                return;
            }

            const spare = Math.max(0, cellWidth - this.#minSize(row.children[i], true));
            const take = Math.min(spare, need);
            widths[i] -= take;
            widths[index] += take;
            need -= take;
        });

        const share = row.sizes.reduce((sum, value) => sum + value, 0);
        row.sizes = widths.map((cellWidth) => share * cellWidth / total);
        cells.forEach((cell, i) => { cell.style.flexGrow = row.sizes[i].toFixed(4); });
        this.#rememberSizes(row);
    }

    /** The nearest row split above the pane of `kind`, its cells, and which cell holds the pane. */
    #rowCellOf(kind) {
        let el = this.slots.get(kind);

        while (el && el !== this.bodyTarget) {
            const cell = el.parentElement;

            if (cell?.classList.contains("layout-cell") && "row" === cell.parentElement?.dataset.dir) {
                const splitEl = cell.parentElement;
                const node = findSplit(this.#workspace().layout, splitEl.dataset.layoutSplit);
                const cells = [...splitEl.children].filter((child) => child.classList.contains("layout-cell"));

                return null === node ? null : { split: node, index: cells.indexOf(cell), cells };
            }

            el = cell;
        }

        return null;
    }

    /** How small a subtree can get across `row` (width) or down (height), dividers included. */
    #minSize(node, row) {
        if ("pane" === node.t) {
            return this.#slotMin(keyOf(node), row);
        }

        const mins = node.children.map((child) => this.#minSize(child, row));
        const along = (node.dir === "row") === row;

        return along ? mins.reduce((sum, value) => sum + value, 0) + this.constructor.DIVIDER * (mins.length - 1) : Math.max(...mins);
    }

    #slotMin(kind, row) {
        const slot = this.#slot(kind);

        return Number(row ? slot?.dataset.minW : slot?.dataset.minH) || 200;
    }

    #switchTo(index) {
        if (this.editing) {
            this.#toast(this.stringsValue.editFirst);

            return;
        }

        if (index < 0 || index >= this.doc.workspaces.length) {
            return;
        }

        // Where the workspace being left has its panes, before it is left.
        this.#snapshotPaneStates();

        // A pane page draws one pane and no workspace around it: the choice
        // is saved and the mail visited, which draws the workspace chosen.
        if ("" !== this.pageValue) {
            this.doc.active = index;
            this.#post().finally(() => Turbo.visit(this.inboxUrlValue));

            return;
        }

        this.doc.active = index;
        this.maxKind = this.#deviceMax();
        this.focusKind = this.#kinds()[0];
        this.tabKind = this.#kinds().includes("list") ? "list" : this.focusKind;
        this.#closePopover();
        this.#commit();
    }

    #newName() {
        const taken = new Set(this.doc.workspaces.map((workspace) => workspace.name));
        let n = this.doc.workspaces.length + 1;

        while (taken.has(this.stringsValue.newName.replace("%n%", n))) {
            n++;
        }

        return this.stringsValue.newName.replace("%n%", n);
    }

    #renameSplits(node) {
        if ("split" === node.t) {
            node.id = newId("s");
            node.children.forEach((child) => this.#renameSplits(child));
        }
    }

    #shapeTree() {
        const { shape, kinds } = this.shapePick;

        return normalise(this.constructor.SHAPES[shape].build(kinds.map((kind) => ({ t: "pane", kind }))));
    }

    // ── Private: drawing ──────────────────────────────────────────────────

    /** Save, then draw. Every change ends here. */
    #commit() {
        this.#ownCalendars(this.#workspace());
        this.#render();
        this.#drawWorkspaceTabs();
        this.#save();
    }

    /**
     * Draw the active workspace: build the splits and cells, move every pane
     * into its cell (or the park), and throw away the old splits, which by
     * then hold no panes.
     */
    #render() {
        const layout = this.#workspace().layout;
        const old = [...this.bodyTarget.children];
        const built = this.#build(layout);

        // Full width on a pane the workspace does not show: borrowed for as
        // long as it is at full width, in a cell of its own.
        if (null !== this.maxKind && false === leaves(layout).includes(this.maxKind)) {
            const cell = document.createElement("div");
            cell.className = "layout-cell";
            cell.setAttribute("data-layout-borrowed", "");
            cell.append(this.#slot(this.maxKind));
            this.bodyTarget.append(built, cell);
        } else {
            this.bodyTarget.append(built);
        }

        const shown = new Set(leaves(layout));

        if (null !== this.maxKind) {
            shown.add(this.maxKind);
        }

        // Parked before the old splits go, or a pane that left the workspace
        // would go with the split it was still in.
        this.slots.forEach((slot, kind) => {
            if (false === shown.has(kind) && slot.parentElement !== this.parkTarget) {
                this.parkTarget.append(slot);
            }
        });

        // What is left of the old tree holds no panes now. A pane that WAS the
        // old tree — a workspace of one — has been moved into a new cell, and
        // must not be taken with it.
        old.forEach((el) => {
            if (el !== built && false === el.hasAttribute("data-layout-pane")) {
                el.remove();
            }
        });

        this.#arrange();

        if (this.editing) {
            this.#decorate();
        }
    }

    #build(node) {
        if ("pane" === node.t) {
            return this.#slot(keyOf(node));
        }

        const sizes = this.#sizesFor(node);
        const el = document.createElement("div");
        el.className = "layout-split";
        el.dataset.layoutSplit = node.id;
        el.dataset.dir = node.dir;

        node.children.forEach((child, i) => {
            if (i > 0) {
                el.append(this.#divider(node));
            }

            const cell = document.createElement("div");
            cell.className = "layout-cell";
            cell.style.flexGrow = sizes[i].toFixed(4);
            cell.append(this.#build(child));
            el.append(cell);
        });

        return el;
    }

    #divider(node) {
        const divider = document.createElement("div");
        divider.className = "layout-divider";
        divider.setAttribute("role", "separator");
        divider.setAttribute("tabindex", "0");
        divider.setAttribute("aria-orientation", "row" === node.dir ? "vertical" : "horizontal");
        divider.setAttribute("aria-label", this.stringsValue.divider);
        divider.setAttribute("title", this.stringsValue.dividerHint);
        divider.dataset.action = "pointerdown->layout--workspace#startResize keydown->layout--workspace#nudge dblclick->layout--workspace#evenOut";

        return divider;
    }

    /**
     * Everything that follows from the layout without rebuilding it: which pane
     * is at full width, where the open message lives, the tab strip, the lazy
     * frames of panes that just came on screen. Run on connect too, where the
     * server drew the tree and this only settles what the server could not
     * know — the window's width.
     */
    #arrange() {
        const present = this.#kinds();
        const narrow = this.narrow.matches;

        this.slots.forEach((slot, kind) => slot.toggleAttribute("data-maxed", kind === this.maxKind));
        this.rootTarget.toggleAttribute("data-layout-max", null !== this.maxKind);

        if (null === this.maxKind) {
            delete this.rootTarget.dataset.layoutMax;
        } else {
            this.rootTarget.dataset.layoutMax = this.maxKind;
        }

        // Below lg: one pane at a time. The message is never a tab.
        const tabs = present.filter((kind) => "message" !== kind);

        if (false === tabs.includes(this.tabKind)) {
            this.tabKind = tabs[0] ?? present[0];
        }

        const current = this.pageValue || (null !== this.maxKind && false === present.includes(this.maxKind) ? this.maxKind : this.tabKind);
        this.slots.forEach((slot, kind) => slot.toggleAttribute("data-tab-current", kind === current));
        this.#drawPaneTabs(tabs, current);

        this.#placeReading(present.includes("message") && false === narrow && "list" !== this.maxKind);

        // A pane that has just come on screen fetches what it shows: the
        // server parks an empty lazy frame for every pane it did not draw. A
        // pane this workspace left somewhere (#paneState) goes back there.
        for (const kind of [...present, this.maxKind]) {
            const slot = this.slots.get(kind);
            const frame = slot?.querySelector("turbo-frame");
            const wanted = this.#paneState(kind) ?? slot?.dataset.layoutSrc;

            if (!frame || !wanted) {
                continue;
            }

            if (frame.hasAttribute("data-layout-lazy")) {
                frame.removeAttribute("data-layout-lazy");
                this.#restore(frame, kind, wanted, slot.dataset.layoutSrc);
            } else if (this.constructor.STATE_KINDS.includes(kind) && samePlace(frame.getAttribute("src") ?? slot.dataset.layoutSrc, wanted) === false) {
                this.#restore(frame, kind, wanted, slot.dataset.layoutSrc);
            }
        }

        document.dispatchEvent(new CustomEvent("layout:changed"));
        window.dispatchEvent(new CustomEvent("side-pane:changed"));
    }

    /**
     * The open message's element goes where the message is read: its own pane
     * beside the list, or the list's card, where it takes the list's place.
     * `data-reading-open` follows it, because mail--mail-pane keeps it on
     * whichever card holds the message (its _setOpen()).
     */
    #placeReading(beside) {
        const reading = this.element.querySelector("[data-mail--mail-pane-target='reading']");
        const card = (beside ? this.slots.get("message") : this.slots.get("list"))?.querySelector(":scope > .layout-card");

        this.rootTarget.toggleAttribute("data-message-beside", beside);

        if (null === reading || undefined === card || null === card || reading.parentElement === card) {
            return;
        }

        const open = reading.parentElement.hasAttribute("data-reading-open");
        reading.parentElement.removeAttribute("data-reading-open");

        if (beside) {
            card.prepend(reading);
        } else {
            card.append(reading);
        }

        card.toggleAttribute("data-reading-open", open);
    }

    #drawPaneTabs(tabs, current) {
        const nav = this.paneTabsTarget;
        nav.hidden = tabs.length < 2;
        nav.replaceChildren(...tabs.map((kind) => {
            const button = document.createElement("button");
            button.type = "button";
            button.className = "layout-tab";
            button.dataset.action = "layout--workspace#tab";
            button.setAttribute("data-layout--workspace-kind-param", kind);
            button.setAttribute("aria-pressed", kind === current ? "true" : "false");
            button.append(this.#icon(kind), document.createTextNode(this.#name(kind)));

            return button;
        }));
    }

    #drawWorkspaceTabs() {
        if (false === this.hasWorkspaceTabsTarget) {
            return;
        }

        const thumb = this.workspaceTabsTarget.querySelector("[data-workspace-thumb]");
        // getAttribute, not dataset: the double dash in the Stimulus param's
        // name has no camelCase dataset key, so it read undefined and no
        // switch ever slid.
        const before = this.workspaceTabsTarget.querySelector("[aria-pressed='true']")?.getAttribute("data-layout--workspace-index-param") ?? undefined;

        this.workspaceTabsTarget.replaceChildren(...(thumb ? [thumb] : []), ...this.doc.workspaces.map((workspace, index) => {
            const button = document.createElement("button");
            const name = document.createElement("span");
            button.type = "button";
            button.className = "workspace-tab";
            button.dataset.action = "layout--workspace#switch";
            button.setAttribute("data-layout--workspace-index-param", String(index));
            button.setAttribute("aria-pressed", index === this.doc.active ? "true" : "false");
            name.className = "truncate";
            name.textContent = workspace.name;
            button.append(name);

            return button;
        }));

        // Slides only when the active tab changed; a rename or a new tab
        // just puts it where it belongs.
        const switched = undefined !== before && String(this.doc.active) !== before;

        this.#placeThumb(switched);

        if (switched) {
            this.#glow(this._switchedBy ?? "bloom");
        }

        this._switchedBy = null;
    }

    /**
     * The switch's glow (app.css, .workspace-glow): `spark` when plMail
     * switched by itself, `bloom` when the user did. Full motion only, which
     * the stylesheet decides; the -0/-1 ending alternates so the same effect
     * twice in a row starts again.
     */
    #glow(effect) {
        const nav = this.hasWorkspaceTabsTarget ? this.workspaceTabsTarget : null;

        if (null === nav) {
            return;
        }

        this._glowTurn = 1 - (this._glowTurn ?? 1);
        nav.dataset.glow = `${effect}-${this._glowTurn}`;
    }

    /**
     * The highlight under the active workspace tab. Its own element, so a
     * switch can slide it from one tab to the other rather than the pressed
     * background jumping (see app.css, .workspace-tab-thumb).
     */
    #placeThumb(slide) {
        const nav = this.hasWorkspaceTabsTarget ? this.workspaceTabsTarget : null;
        const thumb = nav?.querySelector("[data-workspace-thumb]");
        const active = nav?.querySelector("[aria-pressed='true']");

        if (!thumb || !active || 0 === active.offsetWidth) {
            return;
        }

        nav.toggleAttribute("data-thumb-sliding", slide);
        thumb.style.transform = `translateX(${active.offsetLeft}px)`;
        thumb.style.width = `${active.offsetWidth}px`;
        nav.setAttribute("data-thumb-ready", "");
    }

    #icon(key) {
        return this.iconTemplateTarget.content.querySelector(`[data-icon-kind="${kindOf(key)}"]`)?.cloneNode(true) ?? document.createTextNode("");
    }

    #fillLayoutMenu(menu) {
        const list = menu.querySelector("[data-preset-workspaces]");
        const alt = /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent) ? "⌥" : "Alt+";

        list.replaceChildren(...this.doc.workspaces.map((workspace, index) => {
            const button = document.createElement("button");
            const name = document.createElement("span");
            const key = document.createElement("kbd");
            button.type = "button";
            button.className = "layout-preset-workspace";
            button.dataset.action = "layout--workspace#switch";
            button.setAttribute("data-layout--workspace-index-param", String(index));
            button.setAttribute("aria-pressed", index === this.doc.active ? "true" : "false");
            name.className = "truncate flex-1 text-left";
            name.textContent = workspace.name;
            key.className = "kbd";
            key.textContent = `${alt}${index + 1}`;
            const outline = document.createElement("span");
            outline.className = "layout-thumb is-small";
            outline.setAttribute("aria-hidden", "true");
            outline.append(thumb(workspace.layout));
            button.append(outline, name, key);

            return button;
        }));

        menu.querySelectorAll("[data-shape]").forEach((button) => {
            const spec = this.constructor.SHAPES[button.dataset.shape];
            const holder = button.querySelector("[data-shape-thumb]");
            const sample = spec.build(Array.from({ length: spec.slots }, () => ({ t: "pane", kind: "list" })));
            holder.replaceChildren(thumb(normalise(sample)));
            button.setAttribute("aria-pressed", this.shapePick?.shape === button.dataset.shape ? "true" : "false");
        });

        const slots = menu.querySelector("[data-preset-slots]");
        slots.hidden = null === this.shapePick;
        menu.querySelectorAll("[data-preset-new]").forEach((button) => { button.disabled = this.doc.workspaces.length >= 9; });

        if (null === this.shapePick) {
            return;
        }

        // The chosen shape, drawn with a picker in each slot.
        const spec = this.constructor.SHAPES[this.shapePick.shape];
        const markers = this.shapePick.kinds.map((kind, i) => ({ t: "pane", kind, slot: i }));
        const preview = menu.querySelector("[data-preset-preview]");
        preview.replaceChildren(this.#slotPreview(spec.build(markers)));
    }

    #slotPreview(node) {
        if ("pane" === node.t) {
            const label = this.slotTemplateTarget.content.firstElementChild.cloneNode(true);
            const select = label.querySelector("select");
            select.dataset.slot = String(node.slot);
            select.value = node.kind;
            label.querySelector("[data-slot-label]").textContent = String(node.slot + 1);

            return label;
        }

        const el = document.createElement("div");
        el.className = `layout-slot-split is-${node.dir}`;
        node.children.forEach((child, i) => {
            const cell = document.createElement("div");
            cell.style.flexGrow = String(node.sizes?.[i] ?? 1);
            cell.append(this.#slotPreview(child));
            el.append(cell);
        });

        return el;
    }

    // ── Private: popovers ─────────────────────────────────────────────────

    #openPopover(template, anchor) {
        this.#closePopover();

        const popover = template.content.firstElementChild.cloneNode(true);
        this.element.append(popover);

        if (false === /Mac|iPhone|iPad/.test(navigator.platform || navigator.userAgent)) {
            this.#altLabels(popover);
        }

        popover.showPopover?.();
        this.popover = popover;
        this.popoverAnchor = anchor;

        // Right edge under the anchor, kept inside the window either way; above
        // the anchor when there is no room below. Submenus open to the left
        // when the menu is near the right edge.
        const box = anchor.getBoundingClientRect();
        const menu = popover.getBoundingClientRect();
        const left = Math.max(8, Math.min(box.right - menu.width, window.innerWidth - menu.width - 8));
        const below = box.bottom + 6;
        const top = below + menu.height > window.innerHeight - 8 ? Math.max(8, box.top - menu.height - 6) : below;

        popover.style.left = `${left}px`;
        popover.style.top = `${top}px`;
        popover.classList.toggle("opens-left", left + menu.width + 240 > window.innerWidth);

        return popover;
    }

    #closePopover() {
        this.popoverAnchor?.setAttribute("aria-expanded", "false");
        this.popover?.remove();
        this.popover = null;
        this.popoverAnchor = null;
    }

    /** ⌥ written as Alt for a keyboard that says Alt. */
    #altLabels(root) {
        root.querySelectorAll("[data-alt-key]").forEach((key) => {
            key.textContent = key.textContent.replace("⌥⇧", "Alt+Shift+").replace("⌥", "Alt+");
        });
    }

    #toast(message) {
        if (message) {
            showToast(message);
        }
    }

    // ── Private: edit mode ────────────────────────────────────────────────

    /** Edit mode's card on every pane on screen, and the tray offering the rest. */
    #decorate() {
        const present = this.#kinds();

        this.slots.forEach((slot, kind) => {
            let card = slot.querySelector(":scope > .layout-edit-card");

            if (false === present.includes(kind)) {
                card?.remove();
                slot.removeAttribute("data-edit-selected");

                return;
            }

            if (null === card) {
                card = this.overlayTemplateTarget.content.firstElementChild.cloneNode(true);
                slot.append(card);
            }

            card.querySelector("select").value = kindOf(kind);
            const icon = this.#icon(kind);
            icon.setAttribute?.("data-edit-icon", "");
            card.querySelector("[data-edit-icon]")?.replaceWith(icon);
            card.querySelector("[data-edit-name]").textContent = this.#name(kind);
            slot.toggleAttribute("data-edit-selected", kind === this.editing.selected);
        });

        const chips = [...this.trayTarget.querySelectorAll("[data-tray-kind]")];

        chips.forEach((chip) => { chip.hidden = false === this.#canAdd(chip.dataset.trayKind); });
        this.trayTarget.querySelector("[data-tray-empty]").hidden = chips.some((chip) => false === chip.hidden);
        this.#updateEditSizes();
    }

    /** Each card's share of the workspace, live while a divider moves. */
    #updateEditSizes() {
        if (!this.editing) {
            return;
        }

        const box = this.bodyTarget.getBoundingClientRect();

        this.slots.forEach((slot) => {
            const label = slot.querySelector(":scope > .layout-edit-card [data-edit-size]");

            if (null === label || 0 === box.width) {
                return;
            }

            const rect = slot.getBoundingClientRect();
            const wide = Math.round(rect.width / box.width * 100);
            const high = Math.round(rect.height / box.height * 100);

            label.textContent = high < 99
                ? `${this.stringsValue.widePercent.replace("%n%", wide)} · ${this.stringsValue.highPercent.replace("%n%", high)}`
                : this.stringsValue.widePercent.replace("%n%", wide);
        });
    }

    #leaveEdit() {
        this.editing = null;
        this.element.removeAttribute("data-layout-editing");
        this.editBarTarget.hidden = true;
        this.trayTarget.hidden = true;
        this.slots.forEach((slot) => {
            slot.querySelector(":scope > .layout-edit-card")?.remove();
            slot.removeAttribute("data-edit-selected");
        });
    }

    // ── Private: dragging in edit mode ────────────────────────────────────

    #startDrag(event, source) {
        if (0 !== event.button || null === this.editing) {
            return;
        }

        const origin = { x: event.clientX, y: event.clientY };
        this.dragging = { source, started: false, target: null, zone: null };

        const move = (moved) => {
            const drag = this.dragging;

            if (null === drag) {
                return;
            }

            if (false === drag.started) {
                if (Math.hypot(moved.clientX - origin.x, moved.clientY - origin.y) < 5) {
                    return;
                }

                drag.started = true;
                document.body.classList.add("is-dragging-pane");
                drag.ghost = document.createElement("div");
                drag.ghost.className = "layout-ghost";
                drag.ghost.append(this.#icon(source.kind), document.createTextNode(this.#name(source.kind)));
                document.body.append(drag.ghost);
            }

            drag.ghost.style.left = `${moved.clientX}px`;
            drag.ghost.style.top = `${moved.clientY}px`;
            this.#trackDrop(moved.clientX, moved.clientY);
        };

        const up = () => {
            document.removeEventListener("pointermove", move);
            document.removeEventListener("pointerup", up);
            document.removeEventListener("pointercancel", up);

            if (this.dragging?.started) {
                this._suppressClick = true;
                window.setTimeout(() => { this._suppressClick = false; }, 0);
                this.#drop();
            }

            this.#endDrag();
        };

        document.addEventListener("pointermove", move);
        document.addEventListener("pointerup", up);
        document.addEventListener("pointercancel", up);
    }

    /** Which pane the pointer is over, and which part: an edge within a quarter of it, or the middle. */
    #trackDrop(x, y) {
        const drag = this.dragging;
        const slot = document.elementFromPoint(x, y)?.closest?.("[data-layout-pane]");
        const kind = slot?.dataset.layoutPane;

        if (!slot || false === this.#kinds().includes(kind) || (drag.source.existing && kind === drag.source.kind)) {
            drag.target = null;
            this.dropTarget.hidden = true;

            return;
        }

        const rect = slot.getBoundingClientRect();
        const edges = {
            left: (x - rect.left) / rect.width,
            right: (rect.right - x) / rect.width,
            top: (y - rect.top) / rect.height,
            bottom: (rect.bottom - y) / rect.height,
        };
        const [edge, distance] = Object.entries(edges).sort((a, b) => a[1] - b[1])[0];
        const zone = distance < 0.25 ? edge : "middle";
        const box = { left: rect.left, top: rect.top, width: rect.width, height: rect.height };

        if ("left" === zone || "right" === zone) {
            box.width /= 2;
            box.left += "right" === zone ? rect.width / 2 : 0;
        }

        if ("top" === zone || "bottom" === zone) {
            box.height /= 2;
            box.top += "bottom" === zone ? rect.height / 2 : 0;
        }

        drag.target = kind;
        drag.zone = zone;

        Object.assign(this.dropTarget.style, {
            left: `${box.left + 4}px`,
            top: `${box.top + 4}px`,
            width: `${box.width - 8}px`,
            height: `${box.height - 8}px`,
        });

        const label = "middle" === zone
            ? (drag.source.existing ? this.stringsValue.dropSwap : this.stringsValue.dropReplace)
            : this.stringsValue[`drop_${zone}`];
        this.dropTarget.querySelector("[data-drop-label]").textContent = (label ?? "").replace("%pane%", this.#name(kind));
        this.dropTarget.hidden = false;
    }

    #drop() {
        const { source, target, zone } = this.dragging;

        if (null === target) {
            return;
        }

        if ("middle" === zone) {
            this.#show(target, source.kind);
            this.editing.selected = source.kind;
            this.#decorate();

            return;
        }

        const direction = "left" === zone || "right" === zone ? "row" : "col";
        const before = "left" === zone || "top" === zone;

        if (false === this.#roomFor(target, source.kind, direction)) {
            return;
        }

        if (source.existing) {
            this.#remove(source.kind);
        }

        this.#insertBeside(target, paneNode(source.kind), direction, before, { quiet: true });
        this.editing.selected = source.kind;
        this.#render();
    }

    #endDrag() {
        this.dragging?.ghost?.remove();
        this.dragging = null;
        document.body.classList.remove("is-dragging-pane");

        if (this.hasDropTarget) {
            this.dropTarget.hidden = true;
        }
    }

    // ── Private: what is remembered ───────────────────────────────────────

    /** This device's shares for a split, when it has a usable set; else the workspace's. */
    #sizesFor(node) {
        const stored = this.device[this.#workspace().id]?.s?.[node.id];

        if (Array.isArray(stored) && stored.length === node.sizes.length && stored.every((size) => size > 0)) {
            const total = stored.reduce((sum, size) => sum + size, 0);

            return stored.map((size) => size / total);
        }

        return node.sizes;
    }

    /**
     * A divider was let go. The device remembers where; in edit mode the
     * workspace's own proportions follow, saved with the workspace.
     */
    #rememberSizes(node) {
        const id = this.#workspace().id;
        const entry = this.device[id] ?? (this.device[id] = {});
        entry.s = entry.s ?? {};
        entry.s[node.id] = node.sizes.map((size) => Math.round(size * 1000) / 1000);
        this.#writeCookie();
    }

    #rememberMax() {
        const id = this.#workspace().id;
        const entry = this.device[id] ?? (this.device[id] = {});

        if (null === this.maxKind || "" !== this.pageValue) {
            delete entry.m;
        } else {
            entry.m = this.maxKind;
        }

        this.#writeCookie();
    }

    #deviceMax() {
        const kind = this.device[this.#workspace().id]?.m ?? null;

        return this.#kinds().includes(kind) ? kind : null;
    }

    #forgetDevice(id) {
        delete this.device[id];
        this.#writeCookie();
    }

    /** Copy the device's shares into the workspace, for edit mode's Save. */
    #adoptDeviceSizes(node) {
        if ("split" !== node.t) {
            return;
        }

        node.sizes = this.#sizesFor(node);
        node.children.forEach((child) => this.#adoptDeviceSizes(child));
    }

    #readCookie() {
        const raw = document.cookie.split("; ").find((part) => part.startsWith(`${this.cookieValue}=`));

        if (undefined === raw) {
            return {};
        }

        try {
            const value = JSON.parse(decodeURIComponent(raw.slice(this.cookieValue.length + 1)));

            return value && "object" === typeof value ? value : {};
        } catch {
            return {};
        }
    }

    /**
     * Pruned to the workspaces and splits that still exist before writing, so
     * the cookie stays well under its 4 KiB however long somebody keeps
     * rearranging. DeviceLayout refuses anything over 3,500 bytes.
     */
    #writeCookie() {
        const kept = {};

        this.doc.workspaces.forEach((workspace) => {
            const entry = this.device[workspace.id];

            if (undefined === entry) {
                return;
            }

            const splits = splitIds(workspace.layout);
            const sizes = Object.fromEntries(Object.entries(entry.s ?? {}).filter(([id]) => splits.has(id)));
            kept[workspace.id] = { ...(Object.keys(sizes).length ? { s: sizes } : {}), ...(entry.m ? { m: entry.m } : {}) };
        });

        this.device = kept;

        const value = encodeURIComponent(JSON.stringify(kept));
        const secure = "https:" === location.protocol ? "; Secure" : "";
        document.cookie = `${this.cookieValue}=${value}; Path=/; Max-Age=31536000; SameSite=Lax${secure}`;
    }

    /**
     * Post the document, coalesced: a burst of changes is one write. Whole,
     * so a write that is lost is repaired by the next. On a refusal the page
     * keeps what it has and says so; the server's copy is the last good one.
     */
    #save() {
        clearTimeout(this._saveTimer);
        this._saveTimer = window.setTimeout(() => this.#post(), 250);
    }

    /** A save still waiting out its pause, sent now. Nothing waiting, nothing sent. */
    #flush() {
        if (null != this._saveTimer) {
            this.#post();
        }
    }

    async #post() {
        clearTimeout(this._saveTimer);
        this._saveTimer = null;

        // Edit mode is a draft: Save workspace sends it (after leaving edit
        // mode), Cancel and leaving the page throw it away. Sent as it went,
        // a Cancel put the old arrangement back on screen while the server
        // kept the edited one, which the next load then drew.
        if (null !== this.editing) {
            return;
        }

        try {
            const response = await fetch(this.saveUrlValue, {
                method: "POST",
                headers: { "Content-Type": "application/json", "X-CSRF-Token": this.tokenValue, "X-Requested-With": "fetch" },
                body: JSON.stringify(this.doc),
                keepalive: true,
            });

            if (false === response.ok) {
                this.#toast(this.stringsValue.saveFailed);
            }
        } catch {
            this.#toast(this.stringsValue.saveFailed);
        }
    }

    #dividerContext(divider) {
        const el = divider.parentElement;
        const split = findSplit(this.#workspace().layout, el.dataset.layoutSplit);
        const cells = [...el.children].filter((child) => child.classList.contains("layout-cell"));
        const index = [...el.children].filter((child) => child.classList.contains("layout-divider")).indexOf(divider);

        return { split, index, before: cells[index], after: cells[index + 1], row: "row" === el.dataset.dir };
    }
}

// ── The tree, as plain functions over the stored shape ────────────────────────

function newId(prefix) {
    return `${prefix}${Math.random().toString(36).slice(2, 10)}`;
}

function split(dir, children, sizes = null) {
    return { t: "split", id: newId("s"), dir, children, sizes: sizes ?? children.map(() => 1 / children.length) };
}

/**
 * A pane's key: its kind, or `calendar~<id>` for a calendar after the first
 * (LayoutPane::key()). Everything here that names a pane — the slots, the
 * focus, the full-width pane, the tree searches — names it by key, because a
 * workspace can show several calendars; only drawing and naming one asks
 * what it shows (kindOf()).
 */
function keyOf(node) {
    return node.id ? `${node.kind}~${node.id}` : node.kind;
}

/** Whether two addresses are the same page of a pane (path and query). */
function samePlace(a, b) {
    if (!a || !b) {
        return a === b;
    }

    const [x, y] = [new URL(a, location.href), new URL(b, location.href)];

    return x.pathname === y.pathname && x.search === y.search;
}

/** What the pane of a key shows. */
function kindOf(key) {
    return String(key ?? "").split("~")[0];
}

/** A pane node for a key. */
function paneNode(key) {
    const [kind, id] = String(key).split("~");

    return id ? { t: "pane", kind, id } : { t: "pane", kind };
}

/** Make `node` the pane of `key` (a swap moves panes by rewriting their leaves). */
function setKey(node, key) {
    const next = paneNode(key);

    node.kind = next.kind;

    if (next.id) {
        node.id = next.id;
    } else {
        delete node.id;
    }
}

/** The pane keys in a tree, in reading order. */
function leaves(node) {
    return "pane" === node.t ? [keyOf(node)] : node.children.flatMap(leaves);
}

function splitIds(node, ids = new Set()) {
    if ("split" === node.t) {
        ids.add(node.id);
        node.children.forEach((child) => splitIds(child, ids));
    }

    return ids;
}

/** The pane of `key`, with its parent split and its index there (parent null at the root). */
function findPane(node, key, parent = null, index = -1) {
    if ("pane" === node.t) {
        return keyOf(node) === key ? { node, parent, index } : null;
    }

    for (let i = 0; i < node.children.length; i++) {
        const found = findPane(node.children[i], key, node, i);

        if (null !== found) {
            return found;
        }
    }

    return null;
}

function findSplit(node, id) {
    if ("split" !== node.t) {
        return null;
    }

    if (node.id === id) {
        return node;
    }

    for (const child of node.children) {
        const found = findSplit(child, id);

        if (null !== found) {
            return found;
        }
    }

    return null;
}

/**
 * The tree in the form the server stores (LayoutSplit::parse() does the same):
 * a split of one is that one, a split inside a split of the same direction is
 * folded into it, and the shares sum to one.
 */
function normalise(node) {
    if ("pane" === node.t) {
        return node;
    }

    const children = [];
    const sizes = [];

    node.children.forEach((child, i) => {
        const inner = normalise(child);
        const share = node.sizes[i] ?? 1;

        if ("split" === inner.t && inner.dir === node.dir) {
            const total = inner.sizes.reduce((sum, size) => sum + size, 0);
            inner.children.forEach((grandchild, j) => {
                children.push(grandchild);
                sizes.push(share * inner.sizes[j] / total);
            });
        } else {
            children.push(inner);
            sizes.push(share);
        }
    });

    if (1 === children.length) {
        return children[0];
    }

    const total = sizes.reduce((sum, size) => sum + size, 0);

    return { ...node, children, sizes: sizes.map((size) => size / total) };
}

/** A small outline of a tree, for the Layout menu. */
function thumb(node) {
    const el = document.createElement("span");

    if ("pane" === node.t) {
        el.className = `layout-thumb-pane is-${node.kind}`;

        return el;
    }

    el.className = `layout-thumb-split is-${node.dir}`;
    node.children.forEach((child, i) => {
        const cell = document.createElement("span");
        cell.style.flexGrow = String(node.sizes[i]);
        cell.append(thumb(child));
        el.append(cell);
    });

    return el;
}
