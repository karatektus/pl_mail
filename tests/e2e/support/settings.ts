import type { Page } from "@playwright/test";

/**
 * Does `act` — a click, a select — on a Settings control and waits until the
 * save it sets off has landed.
 *
 * The settings forms post through Turbo, which redraws the page in place (see
 * the turbo_refresh block in settings/index.html.twig). A Turbo submission is a
 * fetch, not a navigation, so Playwright's click no longer waits for it the way
 * it waited for a full form post — and a spec that goes straight on with
 * `page.goto()` cancels the save it has just made, quietly, some of the time.
 * The radio or the box looks right meanwhile: the browser ticks it before the
 * request leaves.
 *
 * What it waits for is the GET the POST was redirected to, which is the
 * server's answer that the write is done; every settings action redirects back
 * to its page. The same idiom as setDeleteNow() and setSwipeAction().
 */
export async function saveSetting(page: Page, act: () => Promise<unknown>): Promise<void> {
    const saved = page.waitForResponse(
        (response) =>
            "GET" === response.request().method() &&
            "POST" === response.request().redirectedFrom()?.method(),
    );

    await act();
    await saved;
}
