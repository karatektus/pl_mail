import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
    static targets = ["body", "snippet", "toggleBtn", "chevron", "shadowHost"];
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

    shadowHostTargetConnected(host) {
        const html = host.dataset.html;
        if (!html) {
            return;
        }

        const shadow = host.attachShadow({ mode: "open" });

        // Styles are scoped entirely inside the shadow root — zero bleed into
        // the parent page, and the parent page's Tailwind cannot leak in either.
        const style = document.createElement("style");
        style.textContent = [
            "*, *::before, *::after { box-sizing: border-box; }",
            "body { margin: 0; padding: 0; font-family: sans-serif; font-size: 14px; line-height: 1.5; color: #111; word-wrap: break-word; overflow-wrap: break-word; }",
            "img { max-width: 100%; height: auto; display: block; }",
            "a { color: #2563eb; }",
            "pre, blockquote { overflow-x: auto; }",
            "table { max-width: 100%; border-collapse: collapse; }",
        ].join(" ");

        shadow.appendChild(style);

        const wrapper = document.createElement("div");
        wrapper.innerHTML = html;
        shadow.appendChild(wrapper);
    }
}
