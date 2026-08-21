import { Controller } from "@hotwired/stimulus";
import { jsonCsrfHeaders } from "../../csrf.js";
import { announceWrite } from "../../mail_writes.js";

export default class extends Controller {
    static values = {
        id: Number,
        delay: { type: Number, default: 2000 },
        markUrl: String,
        /**
         * Whether this conversation was still unread when the page rendered.
         *
         * Defaults TRUE, so a caller that does not say assumes the read moved
         * a number and announces it. A missing announcement is the bug this
         * whole change is about; a spare one costs a small JSON fetch.
         */
        wasUnread: { type: Boolean, default: true },
    };

    #timer = null;

    connect() {
        this.#timer = setTimeout(() => this.#markRead(), this.delayValue);
    }

    disconnect() {
        // User navigated away before the delay elapsed — cancel.
        clearTimeout(this.#timer);
    }

    async #markRead() {
        const response = await fetch(this.markUrlValue, {
            method: "POST",
            headers: jsonCsrfHeaders(),
            body: JSON.stringify({ read: true }),
        });

        if (!response.ok) return;

        const html = await response.text();
        Turbo.renderStreamMessage(html);

        // The stream above re-renders the list ROW. The sidebar badge this
        // mail was just counted in is somewhere else entirely and hears
        // nothing from a stream, so it is told. This is the single biggest
        // source of the stale counter: opening a mail is how mail gets read.
        //
        // Only when the read actually moved a number. This controller marks
        // read on every open, including mail that was already read, and that
        // is by far the commoner case once an inbox is under control — those
        // posts change nothing, so announcing them would put a counts request
        // behind every click on a mail for no number that could differ.
        if (true === this.wasUnreadValue) {
            announceWrite();
        }
    }
}
