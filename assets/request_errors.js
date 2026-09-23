/**
 * A request that fails says so in a toast, and leaves the page alone.
 *
 * WHY THIS EXISTS
 * ───────────────
 * Turbo's answer to a failed response is to RENDER it. For a page visit or a
 * form that is the error page drawn as the new page — and when the page Turbo
 * was on and the error page it drew got merged, the settings screen came back
 * with plMail's 500 page hanging off the bottom of it, which is how a picked
 * PDF avatar was reported. A frame whose response is an error gets Turbo's
 * "Content missing" written into it instead. Neither says what happened, and
 * both break the screen the user was using.
 *
 * So every Turbo fetch passes through here first. A response that failed is
 * not rendered: the page stays exactly as it was, and a toast says what went
 * wrong, with the request's reference (RequestIdSubscriber) so the entry can
 * be found in the admin log browser.
 *
 * WHAT IS LEFT ALONE
 * ──────────────────
 * - 422 — "here is the form again, with what is wrong on it". Turbo renders it
 *   and should; it is how every form in the app answers a refusal.
 * - 401 and 403 — the session guard's (core--session-guard), and the
 *   controllers that asked have something more specific to say.
 * - anything inside `[data-turbo-errors="self"]` — an element whose own
 *   controller explains its failures in place (the compose dock does). The
 *   attribute is the opt-out, and it is spelled out on the element rather than
 *   decided here, so the next one is a line of markup.
 *
 * `fetch()` calls a controller makes itself do not pass through Turbo; those
 * report failures with requestFailed() below.
 */
import { showToast } from "./toast.js";

const LEFT_TO_RENDER = new Set([401, 403, 422]);

function words() {
    return document.getElementById("toast-template-error")?.dataset ?? {};
}

function ownsItsErrors(target) {
    return target instanceof Element && null !== target.closest('[data-turbo-errors="self"]');
}

/** The sentence for a status, or for no response at all (status 0). */
function messageFor(status) {
    const w = words();

    if (0 === status) {
        return w.requestErrorOffline ?? "";
    }
    if (404 === status || 410 === status) {
        return w.requestErrorNotFound ?? "";
    }
    if (status >= 500) {
        return w.requestErrorServer ?? "";
    }
    return w.requestErrorRefused ?? "";
}

/**
 * Tell the user a request failed.
 *
 * For a controller's own `fetch()`: pass the Response (or nothing, when the
 * network never answered). Answers true when there was a failure to report.
 */
export function requestFailed(response = null) {
    if (null !== response && true === response.ok) {
        return false;
    }

    const status = null === response ? 0 : response.status;
    const id = response?.headers?.get("X-Request-Id") ?? null;

    showToast(messageFor(status), {
        type: "error",
        reference: null === id ? null : {
            id,
            label: words().requestErrorReference ?? "%id%",
            copyLabel: words().requestErrorCopy ?? "",
        },
    });

    return true;
}

document.addEventListener("turbo:before-fetch-response", (event) => {
    const fetchResponse = event.detail?.fetchResponse;

    if (!fetchResponse || true === fetchResponse.succeeded || true === event.defaultPrevented) {
        return;
    }
    if (LEFT_TO_RENDER.has(fetchResponse.statusCode) || ownsItsErrors(event.target)) {
        return;
    }

    event.preventDefault();
    requestFailed(fetchResponse.response);
});

// The network never answered: no response to render, and Turbo's own reaction
// is a console error and a page that simply did not change.
document.addEventListener("turbo:fetch-request-error", (event) => {
    if (ownsItsErrors(event.target)) {
        return;
    }

    requestFailed(null);
});

// A frame whose response did not carry it. Turbo would write "Content
// missing" into the frame and throw. After the guard above, a response that
// arrives here and succeeded is a page of the wrong shape — a redirect to
// somewhere else — and is visited as the page it is, which is Turbo's own
// advice; one that failed is reported like any other.
document.addEventListener("turbo:frame-missing", (event) => {
    if (ownsItsErrors(event.target)) {
        return;
    }

    event.preventDefault();

    const response = event.detail?.response ?? null;

    if (null !== response && true === response.ok && "function" === typeof event.detail?.visit) {
        event.detail.visit(response);

        return;
    }

    requestFailed(response);
});
