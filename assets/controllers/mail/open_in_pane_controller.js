import { Controller } from "@hotwired/stimulus";
import * as Turbo from "@hotwired/turbo";

/**
 * mail--open-in-pane: a message link outside the mail pane that opens the
 * message IN it — the contacts page's "Reference emails".
 *
 * The list's rows use mail--mail-pane#open, which a link elsewhere cannot: a
 * Stimulus action only reaches controllers around the clicked element, and the
 * contacts pane sits beside the mail pane, not inside it. Left to Turbo, the
 * click was a visit inside the contacts frame, which found no frame of that
 * name in the mailbox page it got back and blanked the mail pane.
 *
 * So the click is offered to the mail pane as a `mail-pane:open` event; the
 * pane cancels it to say it opened the message, beside the contacts as they
 * were. On the full contacts page, which has no list behind it, the mail pane
 * visits the conversation instead (mail--mail-pane#_openUrl), and a page with
 * no mail pane at all gets a visit too. A middle- or modified click is left
 * to the browser.
 */
export default class extends Controller {
    open(event) {
        if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
            return;
        }

        const link = event.target.closest("a[href]");

        if (null === link) {
            return;
        }

        event.preventDefault();

        const request = new CustomEvent("mail-pane:open", { cancelable: true, detail: { url: link.href } });

        if (true === document.dispatchEvent(request)) {
            Turbo.visit(link.href);
        }
    }
}
