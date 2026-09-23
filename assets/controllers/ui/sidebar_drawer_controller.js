// assets/controllers/sidebar_drawer_controller.js
//
// Manages the two ways of hiding the sidebar:
//   • below md — a slide-in drawer over a backdrop. The sidebar is always
//     rendered in the DOM, translated off-screen and brought back via a CSS
//     transition when open.
//   • md and up — an icon rail. A `sidebar-rail` class on <html> narrows the
//     inline sidebar and hides its text (see app.css); the state is kept in
//     localStorage and re-applied before paint by the script in app.html.twig.
//
// One burger drives both — which one depends on the viewport.
//
// A closed drawer is `inert`, not merely off-screen. Translated out of view it
// was still in the tab order: a keyboard user on a phone tabbed through the
// whole sidebar, invisibly, before reaching the page — and a screen reader
// read it out as though it were open. Opening moves focus into it, closing puts
// it back on the burger, and the burger's aria-expanded says which it is.

import { Controller } from "@hotwired/stimulus";

const DESKTOP  = "(min-width: 768px)";
const RAIL_KEY = "plmail:sidebarRail";

export default class extends Controller {
    static targets = ["drawer", "backdrop", "burger"];
    static values  = { open: { type: Boolean, default: false } };

    connect() {
        this._onKeydown = this._handleKeydown.bind(this);

        // A drawer left open while rotating an iPad to landscape would otherwise
        // stay stuck over the desktop layout, backdrop and all.
        this._desktop = window.matchMedia(DESKTOP);
        this._onBreakpoint = (event) => {
            if (event.matches === true) {
                this.close();
            }
        };
        this._desktop.addEventListener("change", this._onBreakpoint);
        this._desktop.addEventListener("change", this._onExpandedChange = () => this._syncExpanded());

        this._syncExpanded();
    }

    disconnect() {
        document.removeEventListener("keydown", this._onKeydown);
        this._desktop.removeEventListener("change", this._onBreakpoint);
        this._desktop.removeEventListener("change", this._onExpandedChange);
        document.body.style.overflow = "";
    }

    toggle() {
        if (this._desktop.matches === true) {
            this.toggleRail();

            return;
        }

        this.openValue = !this.openValue;
    }

    toggleRail() {
        const railed = document.documentElement.classList.toggle("sidebar-rail");

        try {
            localStorage.setItem(RAIL_KEY, railed ? "1" : "0");
        } catch (error) {
            // Safari in private mode throws on write; the rail still works,
            // it just won't survive a reload.
        }

        this._syncExpanded();
    }

    open() {
        this.openValue = true;
    }

    close() {
        this.openValue = false;
    }

    backdropClick(event) {
        if (event.target === event.currentTarget) {
            this.close();
        }
    }

    openValueChanged() {
        const open = this.openValue;

        if (this.hasDrawerTarget) {
            // Slide in/out
            this.drawerTarget.classList.toggle("-translate-x-full", !open);
            this.drawerTarget.classList.toggle("translate-x-0", open);
            this.drawerTarget.inert = !open;
        }

        if (this.hasBackdropTarget) {
            this.backdropTarget.classList.toggle("opacity-0", !open);
            this.backdropTarget.classList.toggle("pointer-events-none", !open);
            this.backdropTarget.classList.toggle("opacity-100", open);
            this.backdropTarget.classList.toggle("pointer-events-auto", open);
        }

        // Lock body scroll while drawer is open
        document.body.style.overflow = open ? "hidden" : "";

        if (open) {
            document.addEventListener("keydown", this._onKeydown);
        } else {
            document.removeEventListener("keydown", this._onKeydown);
        }

        this._syncExpanded();

        // Focus follows the drawer, but only on a real change — this callback
        // also runs once on connect, where nothing was opened or closed.
        if (this._wasOpen === undefined) {
            this._wasOpen = open;

            return;
        }

        if (open && !this._wasOpen && this.hasDrawerTarget) {
            this.drawerTarget.querySelector("a[href], button:not([disabled]), input:not([disabled]), [tabindex]:not([tabindex='-1'])")?.focus();
        } else if (!open && this._wasOpen && this.hasBurgerTarget && this._focusWasInDrawer()) {
            this.burgerTarget.focus();
        }

        this._wasOpen = open;
    }

    /**
     * Focus inside the drawer, or already dropped to <body> — `inert` blurs
     * whatever it contains, so by the time this runs the keyboard may already
     * have fallen out of it.
     */
    _focusWasInDrawer() {
        const active = document.activeElement;

        return active === null || active === document.body || this.drawerTarget.contains(active);
    }

    /** The drawer's state below md; the sidebar's (not railed) above it. */
    _syncExpanded() {
        // Stimulus runs openValueChanged before connect(), so the first call
        // can arrive with no media query yet; connect() makes the real one.
        if (!this.hasBurgerTarget || this._desktop === undefined) {
            return;
        }

        const expanded = this._desktop.matches === true
            ? !document.documentElement.classList.contains("sidebar-rail")
            : this.openValue;

        this.burgerTarget.setAttribute("aria-expanded", String(expanded));
    }

    _handleKeydown(event) {
        if (event.key === "Escape") {
            this.close();
        }
    }
}
