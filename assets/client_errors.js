/**
 * Report our own uncaught errors to the admin panel, and nobody else's.
 *
 * WHY THE FILTER IS MOST OF THIS FILE. `window.onerror` fires for every script
 * running in the page, and on a real browser most of them are not ours:
 * extensions inject analytics, password managers inject form scrapers, and
 * those scripts throw. The console screenshot that prompted this feature was an
 * ad-attribution metrics script failing inside `requestIdleCallback` — nothing
 * to do with plMail, and indistinguishable from a real bug in a list that
 * accepted everything. A panel full of other people's faults is a panel nobody
 * reads, which is the same as not having one.
 *
 * Two things separate ours from theirs, and the browser hands both over:
 *
 *   - `filename` is the script's URL. Ours is on this origin; an extension's is
 *     `chrome-extension://…`, and an injected or eval'd script has none at all
 *     — that is what Chrome's `VM947` means.
 *   - a cross-origin script is reported as the literal `Script error.` with no
 *     file, no line and no message, because the browser will not describe
 *     scripts it did not serve to a page. There is nothing there to keep.
 *
 * The server applies the same rule again (see ClientErrorRecorder). Not
 * belt-and-braces: this file is part of the thing being reported on, and a
 * filter that lives only in the code that is broken is a filter that stops
 * working exactly when it is needed.
 *
 * WHAT IS SENT: message, script, line, column, stack, page. No DOM, no
 * breadcrumbs, nothing gathered. The panel groups by message and position, so
 * what makes a report useful is precision about where, not volume.
 */
import { csrfToken } from "./csrf.js";

/**
 * Reports per page load.
 *
 * A fault inside an animation frame or a Turbo navigation fires thousands of
 * times a minute; the first few say everything the rest do.
 *
 * Together with the de-duplication below, this makes the panel's count mean
 * "how many page loads hit this", not "how many times it fired" — the same
 * fault thrown twice on one page is reported once. That is the deliberate
 * trade: the alternative is a page that posts a thousand requests describing
 * one broken line, and a count of page loads is the more useful number anyway,
 * because it says how many people are running into it.
 */
const MAX_PER_PAGE = 5;

/** The browser's word for "a script I will not describe to you". */
const OPAQUE = "Script error.";

const seen = new Set();
let sent = 0;

/** A script this server sent, as opposed to one somebody injected. */
function ours(filename) {
    return "string" === typeof filename && filename.startsWith(location.origin + "/");
}

function send(report) {
    // Deduplicated on the same key the server groups by, so one fault in a
    // loop is one request rather than five identical ones.
    const key = [report.message, report.source, report.line, report.column].join(" ");

    if (MAX_PER_PAGE <= sent || seen.has(key)) {
        return;
    }

    seen.add(key);
    sent += 1;

    // keepalive, because the most interesting errors are the ones thrown on the
    // way out of a page — a plain fetch is cancelled by the navigation that
    // follows it, and the report never arrives.
    fetch("/client-error", {
        method: "POST",
        keepalive: true,
        headers: { "Content-Type": "application/json", "X-CSRF-Token": csrfToken() },
        body: JSON.stringify(report),
    }).catch(() => {
        // Reporting that the error reporter failed would need the error
        // reporter. Nothing useful to do here, and a rejected promise thrown
        // from this handler would be caught below and reported, which is a loop.
    });
}

window.addEventListener("error", (event) => {
    // `error` also fires for a failed <img> or <script> load, where `message`
    // is empty and the target is the element. Those are not exceptions and have
    // nothing to report; a CSP violation covers the interesting half of them.
    if ("string" !== typeof event.message || "" === event.message || OPAQUE === event.message) {
        return;
    }

    if (false === ours(event.filename)) {
        return;
    }

    send({
        kind: "error",
        message: event.message,
        source: event.filename,
        line: event.lineno,
        column: event.colno,
        stack: event.error?.stack ?? null,
        url: location.href,
    });
});

window.addEventListener("unhandledrejection", (event) => {
    const reason = event.reason;
    const stack = reason?.stack ?? null;

    // No filename on a rejection — it carries a reason, not a file — so the
    // stack is the only handle on where it came from. Without one there is
    // nothing to attribute and nothing anybody could act on.
    if ("string" !== typeof stack || false === stack.includes(location.origin + "/")) {
        return;
    }

    send({
        kind: "unhandledrejection",
        message: String(reason?.message ?? reason),
        source: null,
        line: null,
        column: null,
        stack,
        url: location.href,
    });
});
