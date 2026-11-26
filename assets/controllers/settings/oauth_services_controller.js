/* stimulusFetch: 'lazy' */
import { Controller } from "@hotwired/stimulus";

/**
 * Carries the "use this account for" ticks of the add-account panel onto the
 * Google and Microsoft links (#575), so the consent screen asks only for what
 * was ticked.
 *
 * Links rather than a form of their own: the panel sits inside the IMAP form,
 * HTML has no nested forms, and submit buttons pointing at a second form made
 * the modal's own submit button ambiguous to everything that looks for it.
 * Without this controller the links carry no services, which the connect
 * route reads as all three — what every connection asked for before.
 *
 * Targets:
 *   service  — the checkboxes, value mail | calendar | contacts
 *   link     — the provider links; data-base-href holds the bare route
 */
export default class extends Controller {
    static targets = ["service", "link"];

    connect() {
        this.update();
    }

    update() {
        const ticked = this.serviceTargets.filter((box) => box.checked).map((box) => box.value);

        this.linkTargets.forEach((link) => {
            const url = new URL(link.dataset.baseHref, window.location.origin);

            ticked.forEach((service) => url.searchParams.append("services[]", service));

            link.href = url.pathname + url.search;
            link.toggleAttribute("aria-disabled", 0 === ticked.length);
            link.classList.toggle("pointer-events-none", 0 === ticked.length);
            link.classList.toggle("opacity-50", 0 === ticked.length);
        });
    }
}
