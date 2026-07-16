// assets/controllers/sidebar_drawer_controller.js
//
// Manages the slide-in sidebar on small screens.
// The sidebar is always rendered in the DOM; on mobile it is translated
// off-screen and brought back via a CSS transition when open.

import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
    static targets = ["drawer", "backdrop"];
    static values  = { open: { type: Boolean, default: false } };

    connect() {
        this._onKeydown = this._handleKeydown.bind(this);
    }

    disconnect() {
        document.removeEventListener("keydown", this._onKeydown);
        document.body.style.overflow = "";
    }

    toggle() {
        this.openValue = !this.openValue;
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
