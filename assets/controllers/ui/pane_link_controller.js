import { Controller } from "@hotwired/stimulus";
import { openPane } from "../../panes.js";

/**
 * A link that opens in a side pane when there is one, and is a plain link
 * when there is not.
 *
 *     <a href="/contacts/new/full"
 *        data-controller="ui--pane-link"
 *        data-ui--pane-link-pane-value="contacts"
 *        data-action="click->ui--pane-link#open">More details</a>
 *
 * The pane is asked through assets/panes.js, so it opens if it was closed
 * and goes to the link if it was open, as every other opener's does. Below lg,
 * or on a page with no such pane, openPane() says no and the click is left
 * alone: the link goes where it says, as a page.
 *
 * A modifier or middle click is always the link — somebody asking for a new
 * tab gets one.
 *
 * Dispatches `ui--pane-link:opened` when the pane took it, for whatever the
 * link sits in to react to: "More details" in the add-to-contacts dialog
 * closes the dialog on it (`ui--pane-link:opened->ui--modal#close`). Not a
 * close in this controller, which has no business knowing about dialogs, and
 * not a second click action, which would close the dialog — and cancel the
 * navigation with it — on the fallback too.
 */
export default class extends Controller {
    static values = { pane: String };

    open(event) {
        if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey || 1 === event.button) {
            return;
        }

        if (false === openPane(this.paneValue, { src: this.element.getAttribute("href") ?? undefined })) {
            return;
        }

        event.preventDefault();
        this.dispatch("opened");
    }
}
