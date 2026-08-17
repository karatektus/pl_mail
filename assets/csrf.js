/**
 * The `ajax` CSRF token, for the fetch() calls that are not form submissions.
 *
 * Turbo-driven forms carry their own token in the body and need nothing from
 * here. This is for the other half of the app: the Stimulus controllers that
 * POST JSON — status buttons, bulk actions, the label menu, sidebar and pane
 * preferences, sync-now — which have no form and so send the token in the
 * `X-CSRF-Token` header instead. `templates/_layout/app.html.twig` stamps it
 * into a `csrf-token` meta tag once per render; this reads it back.
 *
 * Its own module because nine controllers had grown the same line:
 *
 *     document.querySelector('meta[name="csrf-token"]')?.content ?? ""
 *
 * All nine were still byte-identical, which is the moment to collapse them
 * rather than the moment after — the server side of this had already drifted
 * into six spellings before it was consolidated into the ChecksCsrf trait, and
 * this is the same duplication seen from the browser.
 *
 * A tenth caller, settings/account_order_controller, takes its token as a
 * Stimulus value from the template instead. That is left alone deliberately:
 * it is a per-action token rather than the shared `ajax` one, so it is a
 * different token, not a different way of reading the same one.
 */

/**
 * The token, or an empty string when the page has no meta tag.
 *
 * The empty string is deliberate rather than a throw: a caller that sends
 * nothing gets a clean 403 from the server, which is the correct answer and
 * the same one a genuinely forged request gets. What it also used to be was
 * indistinguishable from a real CSRF failure while debugging, so the missing
 * tag now says so once in the console — this is a layout bug, not a user one,
 * and it means every JSON POST on the page is about to fail.
 *
 * @param {Document} [doc]
 * @returns {string}
 */
export function csrfToken(doc = document) {
    const token = doc?.querySelector('meta[name="csrf-token"]')?.content;

    if (!token) {
        console.warn(
            '[csrf] No <meta name="csrf-token"> on this page — every JSON POST from it will be refused. ' +
            'The tag is rendered by templates/_layout/app.html.twig; a page that does not extend it needs its own.',
        );

        return "";
    }

    return token;
}

/**
 * Headers for a JSON POST that has to prove it came from our own page.
 *
 * The pair travels together at every call site, so it is offered as a pair.
 *
 * @param {Record<string, string>} [extra] merged in, and able to override
 * @returns {Record<string, string>}
 */
export function jsonCsrfHeaders(extra = {}) {
    return {
        "Content-Type": "application/json",
        "X-CSRF-Token": csrfToken(),
        ...extra,
    };
}
