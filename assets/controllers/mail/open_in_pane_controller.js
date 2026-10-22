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
 * were — on the full contacts page too, which is the mailbox with its mail
 * column put away (contacts/_page_shell.html.twig). A page with no mail pane
 * at all gets a visit to the message instead.
 *
 * Not `data-turbo-frame="_top"` on the links for that last case: ui--split
 * reads a `_top` mail link as a full visit to come, and leaves a calendar
 * position behind for it (its handoff). The visit then never came, and the
 * next page opened on the calendar. A middle- or modified click is left to the
 * browser.
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
