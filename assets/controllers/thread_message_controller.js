import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
    static targets = ["body", "snippet", "toggleBtn", "chevron"];
    static values  = { expanded: Boolean };

    connect() {
        // expanded value is set from Twig via data-thread-message-expanded-value
    }

    toggle(event) {
        this.expandedValue = !this.expandedValue;
    }

    expandedValueChanged() {
        this.bodyTarget.classList.toggle("hidden", !this.expandedValue);
        this.snippetTarget.classList.toggle("hidden", this.expandedValue);

        if (this.hasChevronTarget) {
            this.chevronTarget.classList.toggle("rotate-180", this.expandedValue);
        }
    }

}
