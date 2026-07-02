
import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
    static targets = ["body", "snippet", "toggleBtn", "chevron", "iframe"];
    static values  = { expanded: Boolean };

    connect() {
        // expanded value is set from Twig via data-message-bubble-expanded-value
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

    iframeTargetConnected(iframe) {
        const setup = () => {
            const doc = iframe.contentDocument;
            if (!doc) {
                return;
            }
            const style = doc.createElement("style");
            style.textContent = "body { margin: 0; padding: 8px; overflow: visible; word-wrap: break-word; } img { max-width: 100%; }";
            doc.head.appendChild(style);

            const resize = () => {
                const h = doc.body.scrollHeight;
                iframe.style.height = h + "px";
            };

            new ResizeObserver(resize).observe(doc.body);
            resize();
        };
        if (iframe.contentDocument && iframe.contentDocument.readyState === "complete") {
            setup();
        } else {
            iframe.addEventListener("load", setup, { once: true });
        }
    }
}
