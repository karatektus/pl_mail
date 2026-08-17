import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
    static targets = ["tab", "panel", "submitBtn"];

    connect() {
        this._activate("imap");
    }

    switchTab(event) {
        const panel = event.currentTarget.dataset.panel;
        this._activate(panel);
    }

    _activate(panelName) {
        this.tabTargets.forEach(tab => {
            const isActive = tab.dataset.panel === panelName;
            tab.classList.toggle("border-accent", isActive);
            tab.classList.toggle("text-accent", isActive);

            tab.classList.toggle("text-ink-muted", !isActive);
            tab.classList.toggle("hover:text-ink-soft", !isActive);
        });

        this.panelTargets.forEach(panel => {
            panel.classList.toggle("hidden", panel.dataset.panel !== panelName);
        });

        if (this.hasSubmitBtnTarget) {
            this.submitBtnTarget.classList.toggle("hidden", panelName === "oauth");
        }
    }
}
