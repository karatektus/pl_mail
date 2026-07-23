import { Controller } from "@hotwired/stimulus";

const ACTIVE_CLASSES   = ["bg-accent-soft", "text-accent", "font-medium"];
const INACTIVE_CLASSES = ["text-ink-muted", "hover:bg-hover"];
export default class extends Controller {
    static targets = ["link"];

    connect() {
        this._updateActive();
        this._onTurboLoad = () => this._updateActive();
        document.addEventListener("turbo:load", this._onTurboLoad);
    }

    disconnect() {
        document.removeEventListener("turbo:load", this._onTurboLoad);
    }

    _updateActive() {
        const current = window.location.pathname;

        this.linkTargets.forEach((link) => {
            const href = link.getAttribute("href");
            const isActive = current === href || current.startsWith(href + "/");

            if (isActive) {
                link.classList.add(...ACTIVE_CLASSES);
                link.classList.remove(...INACTIVE_CLASSES);
            } else {
                link.classList.remove(...ACTIVE_CLASSES);
                link.classList.add(...INACTIVE_CLASSES);
            }
        });
    }
}
