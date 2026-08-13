import { expect } from "@playwright/test";
import { test } from "./support/test";
import { consoleCommand, TEST_USER } from "./support/config";

/**
 * What a repair control does the instant it is pressed — in a browser, which is
 * the only place any of it exists.
 *
 * The report this fixes: "the try syncing now button has no userfacing
 * immediate effect. it should tell the user immediately whats happening and be
 * disabled until its finished doing that", and then "that counts for all those
 * controls".
 *
 * The server-side contract — every control carrying a translated pending label,
 * the awaiting state being derived from stored columns — is pinned in
 * tests/Controller/Settings/AccountHealthRepairFeedbackTest.php. What can only
 * be seen here is whether any of that actually reaches the user: whether the
 * button really goes disabled, whether it comes BACK after a failure, whether
 * the back button leaves a dead control behind, and whether the card stops
 * claiming a problem once the worker says it is fixed.
 *
 * ── The one thing simulated ──────────────────────────────────────────────────
 * The Mercure round trip is not driven end to end. Making a real sync succeed
 * would mean a live Google calendar; making the hub deliver on demand would
 * mean a worker running inside the spec. Both would test the transport rather
 * than the behaviour, and the transport is pinned in
 * CalendarSyncFinishedNotificationTest. So the event mercure_controller fans
 * out is dispatched directly, which is exactly what the health controller
 * listens for — the seam is one `this.dispatch()` call away in either
 * direction.
 */

/** A calendar the health page will report as broken, owned by this worker. */
const CALENDAR = "E2E Broken Calendar";

function breakACalendar(): void {
    // The worker's own user, found by address. NOT seeded here — the workerAuth
    // fixture has already run seedUser(), and calling it again re-hashes the
    // password, invalidates the session it established, and sends every test
    // below to the login page looking like a rendering bug.
    // Only the columns without defaults are named; the rest of the row is what
    // the schema says a calendar is, which is the point — a hand-built row that
    // disagreed with the entity would be testing a shape the app never makes.
    // `user` is quoted because Postgres reserves the word, and that quoting has
    // to survive a template literal, a shell and psql in turn.
    consoleCommand(
        `dbal:run-sql "INSERT INTO calendar (usr_id, name, role, remote_id, created_at, updated_at) ` +
            `SELECT id, '${CALENDAR}', 'remote', 'e2e-remote', NOW(), NOW() FROM \\"user\\" WHERE email = '${TEST_USER.email}'"`,
    );

    resetTheCalendar();
}

/**
 * Put the calendar back to "broken, and nobody has pressed anything".
 *
 * Run before EVERY test, not once, and that is not tidiness — it is the shape
 * of the thing under test. Pressing the repair CLEARS the backoff, and a
 * cleared backoff beside a stored error is exactly what the awaiting state is
 * derived from. So the first test to press the button leaves the calendar
 * waiting for a worker that will never come, and every test after it finds a
 * card with no button on it and fails as "element is not visible" — which
 * looks like a rendering bug and is not one.
 *
 * A stored error WITH a backoff already accrued is the state that offers the
 * button, so that is what is restored.
 */
function resetTheCalendar(): void {
    consoleCommand(
        `dbal:run-sql "UPDATE calendar SET last_sync_error = 'The calendar service refused the request.', ` +
            `sync_failure_count = 2, sync_backoff_until = NOW() + INTERVAL '1 hour', last_synced_at = NULL ` +
            `WHERE name = '${CALENDAR}'"`,
    );
}

function removeTheCalendar(): void {
    consoleCommand(`dbal:run-sql "DELETE FROM calendar WHERE name = '${CALENDAR}'"`);
}

/** The resync button on the broken calendar's card. */
function resyncButton(page) {
    return page.locator(
        '[data-health-kind="calendar_sync_failing"] form[action*="/resync"] button[type=submit]',
    );
}

test.describe("health repair feedback", () => {
    test.beforeAll(breakACalendar);
    test.afterAll(removeTheCalendar);

    test.beforeEach(async ({ page }) => {
        resetTheCalendar();
        await page.goto("/settings?section=health");
    });

    /**
     * The defect itself. Before this, pressing the button did nothing visible
     * until the redirect landed — on a slow instance, seconds of a page that
     * looks like it ignored the click.
     */
    test("pressing a repair disables it and says what is happening", async ({
        page,
    }) => {
        const button = resyncButton(page);

        await expect(button).toBeVisible();
        await expect(button).toHaveText("Try syncing now");
        await expect(button).toBeEnabled();

        // Held open so the pending state can be observed rather than raced —
        // the real request is fast enough that the redirect would otherwise
        // land before the first assertion.
        let release: () => void = () => {};
        const held = new Promise<void>((resolve) => {
            release = resolve;
        });

        await page.route("**/settings/health/calendar/*/resync", async (route) => {
            await held;
            await route.continue();
        });

        await button.click();

        await expect(button).toBeDisabled();
        await expect(button).toHaveText("Starting sync…");

        release();

        // And the page it lands on is honest about what happened: started, not
        // finished. The button is not offered again, because the message is
        // already on the queue.
        await expect(page.locator("[data-health-awaiting]")).toBeVisible();
        await expect(page.locator("[data-health-awaiting]")).toContainText(
            "Sync started",
        );
        await expect(resyncButton(page)).toBeHidden();

        // The flash reaches the page as a toast. It never used to: addFlash was
        // called and nothing in the app rendered the flash bag.
        await expect(
            page.locator('#toast-region [data-flash-toast="success"]'),
        ).toContainText("has been asked to sync");
    });

    /**
     * The stuck-control case, tested explicitly because it is the one a
     * hand-written pending state gets wrong. A repair that errors must hand the
     * button back — a permanently dead button is worse than no pending state.
     */
    test("a request that fails re-enables the control", async ({ page }) => {
        const button = resyncButton(page);

        await expect(button).toBeEnabled();

        await page.route("**/settings/health/calendar/*/resync", (route) =>
            route.fulfill({ status: 500, body: "nope" }),
        );

        await button.click();

        // Turbo restores the submitter on turbo:submit-end, which fires for a
        // failed submission too.
        await expect(button).toBeEnabled({ timeout: 10000 });
        await expect(button).toHaveText("Try syncing now");
    });

    /**
     * Turbo caches a snapshot of the page it is leaving. Taken mid-submission,
     * that snapshot holds a disabled button, and the back button restores it —
     * a control that is dead until the user thinks to reload.
     */
    test("coming back with the browser's back button leaves nothing disabled", async ({
        page,
    }) => {
        await resyncButton(page).click();

        await expect(page.locator("[data-health-awaiting]")).toBeVisible();

        await page.goBack();
        await page.goForward();
        await page.goBack();

        // Whatever the cache handed back, nothing on the page is a disabled
        // repair. Asserted over the section rather than one button so a control
        // added later is covered by the same rule.
        await expect(
            page.locator("#settings-health button[type=submit][disabled]"),
        ).toHaveCount(0);
        await expect(
            page.locator("#settings-health a[aria-disabled=true]"),
        ).toHaveCount(0);
    });

    /**
     * The reconnect leaves the app, so it has no submission for Turbo to
     * instrument and gets its state from ui--health-repair instead. The
     * navigation is stubbed rather than followed: the destination is Google.
     */
    test("the reconnect link names where it is taking you and refuses a second click", async ({
        page,
    }) => {
        // A dead grant on this worker's account, so the reconnect card renders.
        consoleCommand(
            `dbal:run-sql "UPDATE account SET auth_type = 'oauth2', oauth_provider = 'google', ` +
                `oauth_access_token = 'stale', oauth_refresh_token = 'stale', ` +
                `oauth_last_refresh_error = 'invalid_grant' WHERE email = 'E2E Mailbox'"`,
        );

        // 204, so the browser keeps the current document and the pending state
        // stays on screen to be looked at. Leaving the request hanging instead
        // tears the document down mid-navigation, and every assertion below
        // then reads an empty string off a page that no longer exists — which
        // is what this looked like before, and looked like a broken controller.
        await page.route("**/settings/health/reconnect/*", (route) =>
            route.fulfill({ status: 204 }),
        );

        await page.goto("/settings?section=health");

        const link = page.locator(
            '[data-health-kind="account_reconnect"] a[href*="/reconnect/"]',
        );

        await expect(link).toBeVisible();
        await expect(link).toHaveText(/Reconnect this account/);

        await link.click();

        await expect(link).toContainText("Taking you to Gmail…");
        await expect(link).toHaveAttribute("aria-disabled", "true");

        // The guard: a second click must not start a second consent round trip,
        // which can land the user back with a state parameter the first one has
        // already spent.
        const classes = (await link.getAttribute("class")) ?? "";

        expect(classes).toContain("pointer-events-none");

        consoleCommand(
            `dbal:run-sql "UPDATE account SET auth_type = 'password', oauth_provider = NULL, ` +
                `oauth_access_token = NULL, oauth_refresh_token = NULL, ` +
                `oauth_last_refresh_error = NULL WHERE email = 'E2E Mailbox'"`,
        );
    });

    // ── the loop closes on what actually happened ────────────────────────────

    /**
     * The honest ending. The press only dispatched; this is the worker coming
     * back and saying it worked, and the card stops claiming a problem that has
     * just been fixed.
     */
    test("the card reports success once the sync actually lands", async ({
        page,
    }) => {
        await resyncButton(page).click();

        const awaiting = page.locator("[data-health-awaiting]");

        await expect(awaiting).toBeVisible();

        const calendarId = await awaiting.getAttribute("data-health-calendar-id");

        // Exactly what core--mercure fans out for {type: 'calendar.sync-finished'}.
        await page.evaluate(
            (id) =>
                document.dispatchEvent(
                    new CustomEvent("core--mercure:calendar-sync-finished", {
                        detail: { calendarId: Number(id), ok: true, error: null },
                    }),
                ),
            calendarId,
        );

        await expect(awaiting).toContainText("up to date again");
        await expect(awaiting).toHaveAttribute("data-health-outcome", "ok");

        // The spinner goes, because the thing it stood for has stopped.
        await expect(page.locator("[data-health-spinner]")).toHaveCount(0);
    });

    /**
     * The failure that must not be silent. Reverting to the sentence the card
     * showed before would be indistinguishable from the press never having
     * happened — which is how somebody presses it four more times.
     */
    test("a repeat failure says so, explains itself, and offers the button back", async ({
        page,
    }) => {
        await resyncButton(page).click();

        const awaiting = page.locator("[data-health-awaiting]");

        await expect(awaiting).toBeVisible();

        const calendarId = await awaiting.getAttribute("data-health-calendar-id");

        await page.evaluate(
            (id) =>
                document.dispatchEvent(
                    new CustomEvent("core--mercure:calendar-sync-finished", {
                        detail: {
                            calendarId: Number(id),
                            ok: false,
                            error: "The calendar no longer exists at the remote.",
                        },
                    }),
                ),
            calendarId,
        );

        await expect(awaiting).toContainText("It failed again");
        await expect(awaiting).toHaveAttribute("data-health-outcome", "failed");

        // The way back out, with a real token behind it.
        await expect(resyncButton(page)).toBeVisible();
        await expect(resyncButton(page)).toBeEnabled();

        // And explained by THIS failure, not the one the user already pressed a
        // button about. Scoped to the calendar's own card: the disclosure is a
        // sibling of the repairs block rather than a child of it, which is
        // exactly the mistake the controller made first time round — it
        // searched inside the waiting line, found nothing, and left the stale
        // reason on screen.
        await expect(
            page.locator(
                '[data-health-kind="calendar_sync_failing"] [data-health-detail]',
            ),
        ).toContainText("The calendar no longer exists at the remote.");
    });

    // ── it has to look right ─────────────────────────────────────────────────

    for (const theme of ["light", "dark"] as const) {
        for (const [name, width] of [
            ["desktop", 1280],
            ["phone", 393],
        ] as const) {
            test(`the pending state reads at ${name} in the ${theme} theme`, async ({
                page,
            }, testInfo) => {
                await page.setViewportSize({ width, height: 900 });
                await page.goto("/settings?section=health");

                // emulateMedia does nothing in this app — the theme is a
                // data-theme attribute on <html>, which is also how a real
                // user's choice arrives.
                await page.evaluate(
                    (t) => document.documentElement.setAttribute("data-theme", t),
                    theme,
                );

                let release: () => void = () => {};
                const held = new Promise<void>((resolve) => {
                    release = resolve;
                });

                await page.route(
                    "**/settings/health/calendar/*/resync",
                    async (route) => {
                        await held;
                        await route.continue();
                    },
                );

                const button = resyncButton(page);

                await button.click();
                await expect(button).toBeDisabled();

                await testInfo.attach(`pending-${name}-${theme}.png`, {
                    body: await page.screenshot({ fullPage: true }),
                    contentType: "image/png",
                });

                // A disabled button must still be legible rather than washed
                // out to nothing — it is carrying the only message on screen.
                const opacity = await button.evaluate(
                    (el) => Number(getComputedStyle(el).opacity),
                );

                expect(opacity).toBeGreaterThan(0.5);

                release();

                await expect(page.locator("[data-health-awaiting]")).toBeVisible();

                // The toast fades in over ~300ms, and a capture taken during
                // that reads as a stacking bug — half-transparent text with the
                // settings nav showing through it. It is not one: the region is
                // z-60 and elementFromPoint at its corner returns the toast. So
                // wait for it to settle rather than photograph the animation.
                await expect(
                    page.locator("#toast-region [data-flash-toast]"),
                ).toHaveCSS("opacity", "1");

                await testInfo.attach(`awaiting-${name}-${theme}.png`, {
                    body: await page.screenshot({ fullPage: true }),
                    contentType: "image/png",
                });

                // The card must not push the page sideways, the rule the whole
                // settings pane follows.
                const overflow = await page.evaluate(
                    () =>
                        document.documentElement.scrollWidth >
                        document.documentElement.clientWidth + 1,
                );

                expect(overflow).toBe(false);
            });
        }
    }
});
