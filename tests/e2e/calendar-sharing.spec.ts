import { expect } from "@playwright/test";
import { test } from "./support/test";
import { TEST_USER, consoleCommand } from "./support/config";

/**
 * Sharing a calendar with somebody who has no account, from the two ends.
 *
 * The whole feature is a URL and what it does or does not say, so a browser
 * spec is the only place the claim can actually be made: the owner creates the
 * link in settings, the address it hands back is copied off the screen, and the
 * page is then opened in a context with no session at all. If any of the wiring
 * were wrong — the access_control rule missing, the token pattern disagreeing
 * with the mint, the layout starting a session — that navigation is where it
 * shows.
 *
 * **The signed-out context is the point of the file.** `browser.newContext()`
 * gives a fresh cookie jar, so what the recruiter sees is what this asserts.
 * Reusing the signed-in page would test the same markup while proving nothing
 * about whether it needs a login.
 *
 * Regression guard: the busy/free assertions below are what stop a well-meant
 * "add a tooltip with the title" from shipping. SharedCalendarLeakTest asserts
 * the same thing over the raw response body; this asserts it on the rendered
 * page, which is where somebody would add the tooltip.
 *
 * The event is seeded through the console rather than through the calendar UI:
 * the subject here is the link, and driving the event editor first would make
 * every failure in this file ambiguous between the two features.
 */

const LINK_NAME = `E2E share ${Date.now()}`;
const EVENT_TITLE = "E2E Secret Standup";
const EVENT_LOCATION = "E2E Secret Room 42";

/**
 * The per-worker user arrives with no calendars — `app:test:seed-mail` writes
 * its account row by hand rather than through AccountCreator, so nothing has
 * provisioned Personal yet. The backfill find-or-creates and is idempotent.
 */
function provisionCalendars(): void {
    consoleCommand("app:backfill calendars");
}

/**
 * The event the redaction assertions are about, on THIS worker's user.
 *
 * `--email` is not optional here: without it the command seeds the default
 * fixture user, the link the spec makes covers a different person's calendar,
 * and the page comes back empty — which passes every "must not contain"
 * assertion for entirely the wrong reason.
 */
function seedSecretEvent(): void {
    consoleCommand(
        `app:test:seed-share-event --email=${TEST_USER.email}` +
            ` --title="${EVENT_TITLE}" --location="${EVENT_LOCATION}"`,
    );
}

test.describe("calendar sharing", () => {
    test.beforeAll(provisionCalendars);

    test.afterEach(async ({ page }) => {
        await page.goto("/settings?section=sharing");

        const remove = () => page.getByRole("button", { name: `Delete the link "${LINK_NAME}"` });

        // Bounded rather than "while there are any": a failed run can leave
        // more than one row, but an unbounded wait inside a hook shows up as a
        // 30-second timeout attributed to the test that has just passed, which
        // is the worst possible place for it.
        for (let attempt = 0; attempt < 4; attempt++) {
            const before = await remove().count();

            if (before === 0) {
                break;
            }

            page.once("dialog", (dialog) => dialog.accept());
            await remove().first().click();
            await expect(remove()).toHaveCount(before - 1);
        }
    });

    test("a busy/free link shows the hours and none of the details", async ({ page, browser }) => {
        seedSecretEvent();

        await page.goto("/settings?section=sharing");

        await page.getByRole("button", { name: "New shared link" }).click();

        const modal = page.locator("#modal-backdrop");
        await expect(modal).toBeVisible();

        // Waiting on the field's VALUE rather than on the dialog being visible:
        // the backdrop keeps the previous form until Turbo swaps the frame, so
        // a fill that only waited for "visible" types into the last dialog.
        await expect(modal.getByLabel("What you call it")).toHaveValue("");

        await modal.getByLabel("What you call it").fill(LINK_NAME);
        await modal.getByRole("button", { name: "Save" }).click();

        // The address is shown once, here, and never again — there is nothing
        // stored that could reproduce it. That is also why the spec has to read
        // it off the screen rather than build it from an id.
        const address = page.getByLabel("The address for this link");
        await expect(address).toBeVisible();

        const url = await address.inputValue();
        expect(url).toContain("/share/");

        // A context of its own: no cookies, no session, nothing this browser
        // learned by being logged in.
        const stranger = await browser.newContext();
        const shared = await stranger.newPage();

        await shared.goto(url);

        await expect(shared.getByRole("heading", { name: "Shared calendar" })).toBeVisible();
        await expect(shared.getByText("Busy").first()).toBeVisible();

        // The claim. Not "the title is not visible" — the whole page must not
        // contain it, so a hidden attribute or an inline payload fails too.
        expect(await shared.content()).not.toContain(EVENT_TITLE);
        expect(await shared.content()).not.toContain(EVENT_LOCATION);

        await stranger.close();
    });

    test("ticking a box reveals that field and only that field", async ({ page, browser }) => {
        seedSecretEvent();

        await page.goto("/settings?section=sharing");
        await page.getByRole("button", { name: "New shared link" }).click();

        const modal = page.locator("#modal-backdrop");
        await expect(modal.getByLabel("What you call it")).toHaveValue("");

        await modal.getByLabel("What you call it").fill(LINK_NAME);
        await modal.getByLabel("Titles").check();
        await modal.getByRole("button", { name: "Save" }).click();

        const url = await page.getByLabel("The address for this link").inputValue();

        const stranger = await browser.newContext();
        const shared = await stranger.newPage();

        await shared.goto(url);

        await expect(shared.getByText(EVENT_TITLE)).toBeVisible();

        // Titles was the only box ticked, so the location must still be absent
        // — the failure this guards is a redaction that works per link rather
        // than per field.
        expect(await shared.content()).not.toContain(EVENT_LOCATION);

        await stranger.close();
    });

    test("a revoked link stops answering", async ({ page, browser }) => {
        await page.goto("/settings?section=sharing");
        await page.getByRole("button", { name: "New shared link" }).click();

        const modal = page.locator("#modal-backdrop");
        await expect(modal.getByLabel("What you call it")).toHaveValue("");

        await modal.getByLabel("What you call it").fill(LINK_NAME);
        await modal.getByRole("button", { name: "Save" }).click();

        const url = await page.getByLabel("The address for this link").inputValue();

        const stranger = await browser.newContext();
        const shared = await stranger.newPage();

        const before = await shared.goto(url);
        expect(before?.status()).toBe(200);

        await page.getByRole("button", { name: `Revoke the link "${LINK_NAME}"` }).click();
        await expect(page.getByText("Revoked", { exact: true })).toBeVisible();

        const after = await shared.goto(url);
        expect(after?.status()).toBe(404);

        await stranger.close();
    });
});

/**
 * Booking an hour of somebody's day without an account.
 *
 * The same shape as the sharing describe above and for the same reason: the
 * feature is a URL and what a stranger can do with it, so the only place the
 * claim can be made is a context that has never logged in. What this adds over
 * BookingEndpointTest is the two halves meeting — the owner's form writes the
 * hours, the public page generates slots from them, and the instant the browser
 * posts back is one the page itself produced. A slot key the template spelled
 * differently from the reader would make every booking "no longer being
 * offered", and no unit test on either side would notice.
 *
 * The page is open every day on purpose. A Monday-to-Friday fixture has no
 * slots inside the notice period at weekends, so the spec would fail on
 * Saturdays and Sundays — the worst kind of flake, because it looks like a real
 * bug two days out of seven.
 */
const PAGE_NAME = `E2E booking ${Date.now()}`;

test.describe("appointment booking", () => {
    test.beforeAll(provisionCalendars);

    test.afterEach(async ({ page }) => {
        // The booked MEETING outlives the page: calendar_booking cascades from
        // booking_page, but the event it created does not — the owner's diary is
        // not a thing a deleted booking page should empty. Left alone it
        // accumulates one event per run on the fixture calendar, and each one
        // takes an hour out of the next run's availability. `--clear` deletes by
        // exact title, which is why the booker's name is spelled here.
        consoleCommand(
            `app:test:seed-share-event --email=${TEST_USER.email}` +
                ` --title="${PAGE_NAME} — Ada Lovelace" --clear`,
        );

        await page.goto("/settings?section=sharing");

        const remove = () => page.getByRole("button", { name: `Delete the booking page "${PAGE_NAME}"` });

        for (let attempt = 0; attempt < 4; attempt++) {
            const before = await remove().count();

            if (before === 0) {
                break;
            }

            page.once("dialog", (dialog) => dialog.accept());
            await remove().first().click();
            await expect(remove()).toHaveCount(before - 1);
        }
    });

    test("a stranger picks a free time and books it", async ({ page, browser }) => {
        await page.goto("/settings?section=sharing");

        await page.getByRole("button", { name: "New booking page" }).click();

        const modal = page.locator("#modal-backdrop");
        await expect(modal).toBeVisible();
        await expect(modal.getByLabel("What the appointment is called")).toHaveValue("");

        await modal.getByLabel("What the appointment is called").fill(PAGE_NAME);

        // Every day, so the page has slots inside its horizon whichever day the
        // suite runs on — see the describe's docblock.
        // `force` because the checkbox is `sr-only` and its own <label> is what
        // receives the pointer — Playwright refuses an ordinary check() when the
        // thing it would click is covered by the element that styles it.
        for (const day of ["Sat", "Sun"]) {
            await modal.getByLabel(day, { exact: true }).check({ force: true });
        }

        await modal.getByRole("button", { name: "Save" }).click();

        const url = await page.getByLabel("The address for this link").inputValue();
        expect(url).toContain("/book/");

        const stranger = await browser.newContext();
        const booking = await stranger.newPage();

        await booking.goto(url);

        await expect(booking.getByRole("heading", { name: PAGE_NAME })).toBeVisible();

        // Counted before anything is booked, so the assertion at the end is
        // about this booking rather than about how many hours the page happens
        // to offer on the day the suite runs.
        const slotsBefore = await booking.locator('input[name="slot"]').count();
        expect(slotsBefore).toBeGreaterThan(0);

        // The first radio the page rendered, whatever hour that turns out to be.
        // Naming an hour here would be this spec deciding what the availability
        // rules mean, which is the reader's job and is tested there. `force` for
        // the reason the weekday boxes need it: the input is `sr-only`.
        await booking.locator('input[name="slot"]').first().check({ force: true });

        await booking.getByLabel("Your name").fill("Ada Lovelace");
        await booking.getByLabel("Your email").fill("ada@example.test");
        await booking.getByLabel("Anything else? (optional)").fill("About the engine");

        await booking.getByRole("button", { name: "Confirm the booking" }).click();

        await expect(booking.getByRole("heading", { name: "That is booked" })).toBeVisible();

        // And the hour is gone from the page, which is the read-side half of
        // the double-booking guard doing its job in front of a person.
        await booking.goto(url);
        expect(await booking.locator('input[name="slot"]').count()).toBe(slotsBefore - 1);

        await stranger.close();

        // And the owner's own calendar draws it, marked as a booking. This is
        // the only place the badge is actually rendered — BookingServiceTest
        // asserts that EventSource::Booking carries an icon, and this asserts
        // that a chip draws it, which is a different claim and the one the
        // requirement was about.
        await page.goto("/calendar/week");

        const chip = page.getByRole("button", { name: new RegExp(PAGE_NAME) }).first();
        await expect(chip).toBeVisible();
        await expect(chip.locator("i.fa-calendar-check")).toBeVisible();
    });

    test("a booking page that is switched off stops answering", async ({ page, browser }) => {
        await page.goto("/settings?section=sharing");
        await page.getByRole("button", { name: "New booking page" }).click();

        const modal = page.locator("#modal-backdrop");
        await expect(modal.getByLabel("What the appointment is called")).toHaveValue("");

        await modal.getByLabel("What the appointment is called").fill(PAGE_NAME);
        await modal.getByRole("button", { name: "Save" }).click();

        const url = await page.getByLabel("The address for this link").inputValue();

        const stranger = await browser.newContext();
        const booking = await stranger.newPage();

        expect((await booking.goto(url))?.status()).toBe(200);

        await page.getByRole("button", { name: `Switch off the booking page "${PAGE_NAME}"` }).click();
        await expect(page.getByText("Off", { exact: true })).toBeVisible();

        expect((await booking.goto(url))?.status()).toBe(404);

        await stranger.close();
    });
});
