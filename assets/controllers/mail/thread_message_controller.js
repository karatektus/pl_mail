import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
    static targets = ["body", "snippet", "toggleBtn", "chevron", "recipients"];
    static values  = {
        expanded: Boolean,
        /** The toggle's translated names, one per state it would switch to. */
        expandLabel: String,
        collapseLabel: String,
    };

    connect() {
        // expanded value is set from Twig via data-mail--thread-message-expanded-value
    }

    toggle(event) {
        this.expandedValue = !this.expandedValue;
    }

    expandedValueChanged() {
        this.bodyTarget.classList.toggle("hidden", !this.expandedValue);
        this.snippetTarget.classList.toggle("hidden", this.expandedValue);

        if (this.hasRecipientsTarget) {
            this.recipientsTarget.classList.toggle("hidden", !this.expandedValue);
        }

        if (this.hasChevronTarget) {
            this.chevronTarget.classList.toggle("rotate-180", this.expandedValue);
        }

        // The chevron's rotation was the only sign of state, and a screen
        // reader cannot see it: the button said "expand" whichever way it was.
        if (this.hasToggleBtnTarget) {
            this.toggleBtnTarget.setAttribute("aria-expanded", String(this.expandedValue));

            const label = this.expandedValue ? this.collapseLabelValue : this.expandLabelValue;

            if (label !== "") {
                this.toggleBtnTarget.setAttribute("aria-label", label);
            }
        }
    }

}
