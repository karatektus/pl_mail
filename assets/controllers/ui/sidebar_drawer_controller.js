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

import { Controller } from "@hotwired/stimulus";

const DESKTOP  = "(min-width: 768px)";
const RAIL_KEY = "plmail:sidebarRail";

export default class extends Controller {
    static targets = ["drawer", "backdrop"];
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
    }

    disconnect() {
        document.removeEventListener("keydown", this._onKeydown);
        this._desktop.removeEventListener("change", this._onBreakpoint);
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
    }

    _handleKeydown(event) {
        if (event.key === "Escape") {
            this.close();
        }
    }
}
