import { Controller } from "@hotwired/stimulus";

/**
 * The booking page visitor's own zone, put in the URL once so the server can
 * print the hours on the reader's clock.
 *
 * A reload rather than client-side rewriting: the slot instants are the
 * server's, and re-rendering them in JavaScript would be a second
 * implementation of the same formatting to disagree with. The guard is what
 * stops it looping — with `tz` already set, this does nothing.
 *
 * A controller rather than the inline <script> it used to be: the enforced
 * Content-Security-Policy refused that one, so every visitor saw the owner's
 * zone without a word of warning.
 */
export default class extends Controller {
    connect() {
        const url = new URL(window.location.href);

        if (url.searchParams.has("tz")) {
            return;
        }

        const zone = Intl.DateTimeFormat().resolvedOptions().timeZone;

        if (zone) {
            url.searchParams.set("tz", zone);
            window.location.replace(url.toString());
        }
    }
}
