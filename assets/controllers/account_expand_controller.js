import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
    static targets = ["frame", "chevron", "link"]; // add "link" target
    static values = { foldersUrl: String, active: Boolean };

    connect() {
        this._onOtherExpanded = this._handleOtherExpanded.bind(this);
        this._onTurboLoad = this.highlightActive.bind(this);

        window.addEventListener("account-expand:opened", this._onOtherExpanded);
        document.addEventListener("turbo:load", this._onTurboLoad);
        this.element.addEventListener('turbo:frame-load', () => this.highlightActive());

        if (this.activeValue && !this.frameTarget.src) {
            this.frameTarget.src = this.foldersUrlValue;
        }

        this.highlightActive();
    }

    disconnect() {
        window.removeEventListener("account-expand:opened", this._onOtherExpanded);
        document.removeEventListener("turbo:load", this._onTurboLoad);
    }

    toggle(event) {
        const frame    = this.frameTarget;
        const chevron  = this.chevronTarget;
        const isHidden = frame.classList.contains("hidden");

        if (isHidden) {
            window.dispatchEvent(new CustomEvent("account-expand:opened", {
                detail: { element: this.element }
            }));

            frame.classList.remove("hidden");
            chevron.classList.add("rotate-90");

            if (!frame.src) {
                frame.src = this.foldersUrlValue;
            }
        } else {
            frame.classList.add("hidden");
            chevron.classList.remove("rotate-90");
        }
    }

    stop(event) {
        event.stopPropagation();
    }

    _handleOtherExpanded(event) {
        if (event.detail.element !== this.element) {
            this.frameTarget.classList.add("hidden");
            this.chevronTarget.classList.remove("rotate-90");
        }
    }

    highlightActive() {
        this.linkTargets.forEach((link) => {
            const isActive = window.location.pathname === new URL(link.href).pathname;
            link.classList.toggle('bg-blue-50', isActive);
            link.classList.toggle('dark:bg-blue-950/40', isActive);
            link.classList.toggle('text-blue-700', isActive);
            link.classList.toggle('dark:text-blue-400', isActive);
            link.classList.toggle('font-medium', isActive);
            link.classList.toggle('text-gray-500', !isActive);
            link.classList.toggle('dark:text-gray-400', !isActive);
        });
    }
}
