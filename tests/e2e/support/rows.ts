import { expect, type Locator, type Page } from "@playwright/test";

/**
 * Click one of a row's hover actions.
 *
 * The actions are `invisible group-hover:visible`, so they occupy space at all
 * times and are only painted while the pointer is on the row. That makes them
 * unusually easy to lose: anything that re-lays-out the list between the hover
 * and the click — a live count arriving, a background refresh landing, a
 * sibling menu leaving the top layer — takes the hover with it, and Playwright
 * reports the button as "not stable" and then "not visible", or clicks nothing
 * at all and leaves the test waiting for a request that was never sent.
 *
 * Under four parallel workers that happened often enough to look like the app
 * being broken, on a different spec each run.
 *
 * `force` because visibility is exactly the property that is legitimately
 * flickering here, and the button's box is correct throughout. The assertion
 * before it is what keeps this honest: an action that is genuinely absent —
 * Archive on already-archived mail, say — still fails, and fails saying so.
 */
export async function rowAction(row: Locator, name: string | RegExp): Promise<void> {
    const button = row.getByRole("button", { name });

    // Hover FIRST, and re-hover on every attempt rather than hovering once and
    // trusting it to stick.
    //
    // The actions are `visibility: hidden` until the row is hovered, and a
    // hidden element is not in the accessibility tree — so getByRole finds
    // nothing at all, and a plain assertion would report a missing action for
    // one that is merely unhovered. Retrying does not help by itself either:
    // the mouse has not moved, so a row replaced by a refresh under a stationary
    // pointer never receives :hover, and every retry re-reads the same nothing
    // until it times out.
    await expect(async () => {
        await row.scrollIntoViewIfNeeded();
        await row.hover();

        await expect(button, `the row has no "${name}" action`).toBeVisible({ timeout: 1_000 });
    }).toPass({ timeout: 10_000 });

    // NOT force. `force` skips the hit-target check, and skipping it is how this
    // failed silently rather than loudly: the snooze button was clicked while
    // invisible, the event went to the coordinates and landed on the ROW, and
    // the assertion that followed reported a menu that "did not open" — which
    // was true, and said nothing about why. An honest click hovers the button
    // itself (keeping the row hovered, since it is inside it), waits for it to
    // be hittable, and says so if something is covering it.
    await button.click();
}

/**
 * Click a row action and wait for the write it makes to land.
 *
 * Navigating straight after the click reads the next list from before the
 * write, which fails as "the conversation is not there" — indistinguishable
 * from the feature being broken. The status is asserted rather than assumed,
 * because a refused write answers just as promptly as a successful one.
 */
export async function rowActionAwaited(
    page: Page,
    row: Locator,
    name: string | RegExp,
    endpoint: string,
): Promise<void> {
    const landed = page.waitForResponse(
        (response) => response.url().includes(endpoint) && response.request().method() === "POST",
    );

    await rowAction(row, name);

    expect((await landed).status(), `${endpoint} was refused`).toBe(200);
}
