import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
    static targets = ["tab", "panel", "submitBtn"];

    connect() {
        console.log('panels:', this.panelTargets.length);
        console.log('tabs:', this.tabTargets.length);
        console.log('submitBtn:', this.hasSubmitBtnTarget);
        this._activate("imap");
    }

    switchTab(event) {
        const panel = event.currentTarget.dataset.panel;
        this._activate(panel);
    }

    _activate(panelName) {
        this.tabTargets.forEach(tab => {
            const isActive = tab.dataset.panel === panelName;
            tab.classList.toggle("border-blue-500", isActive);
            tab.classList.toggle("text-blue-600", isActive);
            tab.classList.toggle("dark:text-blue-400", isActive);
            tab.classList.toggle("border-transparent", !isActive);
            tab.classList.toggle("text-zinc-500", !isActive);
            tab.classList.toggle("dark:text-zinc-400", !isActive);
            tab.classList.toggle("hover:text-zinc-700", !isActive);
            tab.classList.toggle("dark:hover:text-zinc-200", !isActive);
        });

        this.panelTargets.forEach(panel => {
            panel.classList.toggle("hidden", panel.dataset.panel !== panelName);
        });

        if (this.hasSubmitBtnTarget) {
            this.submitBtnTarget.classList.toggle("hidden", panelName === "oauth");
        }
    }
}
