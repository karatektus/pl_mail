import { expect } from "@playwright/test";
import { test } from "./support/test";
import { TEST_USER, consoleCommand, seed } from "./support/config";

/**
 * Managing calendars from settings.
 *
 * A browser spec rather than a controller test because the rules worth holding
 * are rendered ones: which buttons a row offers. The default calendar has no
 * delete and no hide button, and a provisioned one has no delete at all — those
 * are enforced server-side too, but a user only ever meets them as an absence,
 * and an absence is exactly what a unit test does not notice going missing.
 *
 * Creates and removes its own calendar. The per-worker user arrives with the
 * provisioned ones (Personal, plus one per mail account), so the assertions
 * below are written against "the row named X" rather than against counts.
 */

const NAME = `E2E calendar ${Date.now()}`;
const RENAMED = `${NAME} renamed`;

/**
 * The premise this file asserts on, made rather than assumed.
 *
 * Personal and the per-account calendars are provisioned lazily, and
 * `app:test:seed-mail` writes its account row by hand rather than through
 * AccountCreator — so a freshly seeded worker owns no calendars at all. Both
 * describes below name "Personal" and expect a second, hideable calendar
 * beside it; they used to get them only because another file had opened the
 * calendar first on the same worker, which is an ordering nobody declared and
 * Playwright is free to change the moment a file's duration does.
 *
 * The backfill task is idempotent and find-or-creates, so running it per file
 * costs one console round trip and fixes nothing that was not missing.
 */
function provisionCalendars(): void {
    consoleCommand("app:backfill calendars");
}

test.describe("calendar settings", () => {
    test.beforeAll(provisionCalendars);

    test.beforeEach(async ({ page }) => {
        await page.goto("/settings?section=calendars");
    });

    test.afterEach(async ({ page }) => {
        // Named on the row it belongs to: the aria-label carries the calendar's
        // name, so this cannot delete the wrong one if a previous run left
        // something behind.
        for (const name of [NAME, RENAMED]) {
            await page.goto("/settings?section=calendars");

            // The default calendar has no delete button, so a test that failed
            // between the two halves of "moves the default" would otherwise
            // leave a calendar nothing can ever remove — and, because
            // CalendarProvisioner only creates Personal when no default
            // exists, a worker that never gets Personal back either.
            const restore = page.getByRole("button", {
                name: 'Make "Personal" the calendar new events land on',
            });

            if ((await restore.count()) > 0) {
                await restore.click();
                await expect(restore).toHaveCount(0);
            }

            const remove = page.getByRole("button", { name: `Delete calendar "${name}"` });

            if ((await remove.count()) > 0) {
                page.once("dialog", (dialog) => dialog.accept());
                await remove.first().click();
                await expect(page.getByText(name, { exact: true })).toHaveCount(0);
            }
        }
    });

    test("lists the calendars the user already has", async ({ page }) => {
        await expect(page.getByRole("heading", { name: "Calendars" })).toBeVisible();

        // Provisioned with the user, so it is there before this spec does
        // anything — and it is the default, which is what the badge says.
        //
        // Read inside the list, not anywhere on the page. The settings nav
        // groups its links under headings now, and one of those headings is
        // also the word "Personal" — so an unscoped match finds two elements
        // and fails as ambiguous rather than as absent. The nav heading is
        // `aria-hidden` and the list is what the assertion was ever about.
        const list = page.locator("#settings-calendar-list");

        await expect(list.getByText("Personal", { exact: true })).toBeVisible();
        await expect(list.getByText("Default", { exact: true })).toBeVisible();
    });

    test("creates a calendar, then renames it", async ({ page }) => {
        await page.getByRole("button", { name: "New calendar" }).click();

        const modal = page.locator("#modal-backdrop");
        await expect(modal).toBeVisible();

        // Waiting on the field's VALUE, not on the dialog being visible. The
        // backdrop keeps the previous form until Turbo swaps the frame, so a
        // fill that only waited for "visible" typed into the last dialog and
        // then submitted the new one — which reads as a save that did nothing.
        await expect(modal.getByLabel("Name")).toHaveValue("");

        await modal.getByLabel("Name").fill(NAME);
        await modal.getByRole("button", { name: "Save" }).click();

        await expect(page.getByText(NAME, { exact: true })).toBeVisible();

        await page.getByRole("button", { name: `Edit calendar "${NAME}"` }).click();
        await expect(modal).toBeVisible();

        // The edit form arrives filled in, which is both the wait and a claim.
        await expect(modal.getByLabel("Name")).toHaveValue(NAME);

        await modal.getByLabel("Name").fill(RENAMED);
        await modal.getByRole("button", { name: "Save" }).click();

        await expect(page.getByText(RENAMED, { exact: true })).toBeVisible();
        await expect(page.getByText(NAME, { exact: true })).toHaveCount(0);
    });

    /**
     * A name is what tells one calendar from another, so an empty one comes
     * back as an error on the field. The modal must stay open — it closes on
     * any successful submit, so a 200 here would look like a silent save.
     */
    test("refuses a calendar with no name", async ({ page }) => {
        await page.getByRole("button", { name: "New calendar" }).click();

        const modal = page.locator("#modal-backdrop");
        await modal.getByLabel("Name").fill("");
        await modal.getByRole("button", { name: "Save" }).click();

        await expect(modal).toBeVisible();
    });

    /**
     * Hiding takes a calendar out of every view at once, so the one new events
     * land on cannot be hidden — they would vanish on save and read as a
     * failure. The button is simply not rendered for it.
     */
    test("the default calendar cannot be hidden or deleted", async ({ page }) => {
        await expect(page.getByRole("button", { name: "Delete calendar \"Personal\"" })).toHaveCount(0);
        await expect(page.getByRole("button", { name: "Hide calendar" }).first()).toBeVisible();

        const personalRow = page.locator("li", { hasText: "Personal" }).first();
        await expect(personalRow.getByRole("button", { name: "Hide calendar" })).toHaveCount(0);
    });

    test("moves the default to another calendar", async ({ page }) => {
        await page.getByRole("button", { name: "New calendar" }).click();

        const modal = page.locator("#modal-backdrop");
        await expect(modal.getByLabel("Name")).toHaveValue("");
        await modal.getByLabel("Name").fill(NAME);
        await modal.getByRole("button", { name: "Save" }).click();
        await expect(page.getByText(NAME, { exact: true })).toBeVisible();

        await page.getByRole("button", { name: `Make "${NAME}" the calendar new events land on` }).click();

        // The badge moves with it, and exactly one row carries it: two defaults
        // means a new event lands wherever row order puts it.
        await expect(page.getByText("Default", { exact: true })).toHaveCount(1);

        const row = page.locator("li", { hasText: NAME }).first();
        await expect(row.getByText("Default", { exact: true })).toBeVisible();

        // Put it back, so the delete in afterEach is allowed to run.
        await page.getByRole("button", { name: 'Make "Personal" the calendar new events land on' }).click();
        await expect(page.getByRole("button", { name: `Make "${NAME}" the calendar new events land on` })).toBeVisible();
    });
});

/**
 * Subscribing to the calendars a connection offers.
 *
 * The whole of this is rendered state, which is why it is here and not only in
 * CalendarSubscriberTest: whether a calendar is read-only, where it came from,
 * whether it has ever synced and why it stopped are facts a user meets as marks
 * on a row, and a row that quietly stops carrying one of them is exactly what a
 * service test cannot notice.
 *
 * `app:test:seed-calendar-source` makes two connections whose answers come out
 * of the database rather than off a network — see
 * App\Tests\Support\Calendar\ScriptedCalendarSyncDriver for why that seam
 * exists and why pointing a spec at a real CalDAV server was rejected. One
 * lists two calendars, the other refuses to list anything, which is the pair of
 * cases this screen has to survive.
 *
 * The seed is also the cleanup: re-running it removes the connections it made
 * last time and every calendar mirrored from them, so a run that failed halfway
 * leaves nothing for the next one to trip on.
 */
const SERVER = "E2E calendar server";
const BROKEN_SERVER = "E2E broken calendar server";
const TEAM = "E2E Team calendar";
const HOLIDAYS = "E2E Public holidays";

test.describe("subscribing to remote calendars", () => {
    test.beforeAll(provisionCalendars);

    // Reseeding here and not also in an afterEach, deliberately: the command
    // removes what it made last time before making it again, so one call per
    // test is a full reset. Doing it twice doubled the number of
    // `docker compose exec` round trips this file costs, which is the most
    // expensive thing a spec can do and is measurable in the whole suite's
    // wall clock.
    test.beforeEach(async ({ page }) => {
        seed("seed-calendar-source");
        await page.goto("/settings?section=calendars");
    });

    // Not just between tests: a fixture connection left on this worker's user
    // outlives the file, and the compose picker spec establishes "nothing is
    // connected" as its own premise before asserting on it. Files run
    // sequentially within a worker, so this is the last thing that happens
    // before another one starts.
    test.afterAll(() => {
        consoleCommand(`app:test:seed-calendar-source --clear --email=${TEST_USER.email}`);
    });

    test("lists what a connection offers, and marks the read-only ones", async ({ page }) => {
        await page.getByRole("button", { name: `Find calendars on ${SERVER}` }).click();

        const modal = page.locator("#modal-backdrop");

        // Waiting on this dialog's own CONTENT, not on the backdrop being
        // visible. The frame keeps the previous dialog until Turbo swaps it,
        // so anything asserted on "visible" alone is asserted against whatever
        // was open last — which for this file is a calendar form.
        const team = modal.getByRole("checkbox", { name: new RegExp(TEAM) });
        await expect(team).toBeVisible();

        await expect(modal.getByRole("checkbox", { name: new RegExp(HOLIDAYS) })).toBeVisible();

        // Nothing is mirrored yet, so nothing is ticked. A list that arrived
        // pre-ticked would subscribe the user to everything on their first Save.
        await expect(team).not.toBeChecked();

        // The badge, not the entity flag: the user cannot see isReadOnly.
        const holidayRow = modal.locator("li", { hasText: HOLIDAYS });
        await expect(holidayRow.getByText("Read-only")).toBeVisible();
        await expect(modal.locator("li", { hasText: TEAM }).getByText("Main")).toBeVisible();
    });

    test("ticking a calendar mirrors it, and unticking it stops", async ({ page }) => {
        const modal = page.locator("#modal-backdrop");

        await page.getByRole("button", { name: `Find calendars on ${SERVER}` }).click();
        await expect(modal.getByRole("checkbox", { name: new RegExp(TEAM) })).toBeVisible();

        await modal.getByRole("checkbox", { name: new RegExp(TEAM) }).check();
        await modal.getByRole("button", { name: "Save subscriptions" }).click();

        const row = page.locator("li", { hasText: TEAM }).first();

        await expect(page.getByText(TEAM, { exact: true })).toBeVisible();
        await expect(row.getByText("Mirrored")).toBeVisible();
        await expect(row.getByText(`From ${SERVER}`)).toBeVisible();
        await expect(row.getByText("Not synced yet")).toBeVisible();
        await expect(page.getByRole("button", { name: `Sync "${TEAM}" now` })).toBeVisible();

        // Re-opening is the claim that the list knows what it already mirrors —
        // a screen that forgot would offer to subscribe a second time and
        // silently do nothing.
        await page.getByRole("button", { name: `Find calendars on ${SERVER}` }).click();
        await expect(modal.getByRole("checkbox", { name: new RegExp(TEAM) })).toBeChecked();

        await modal.getByRole("checkbox", { name: new RegExp(TEAM) }).uncheck();
        await modal.getByRole("button", { name: "Save subscriptions" }).click();

        // Also the guard on the stream: `settings-calendar-list` is replaced
        // for a second time here, which only works because the replacement
        // re-emits the target id inside itself.
        await expect(page.getByText(TEAM, { exact: true })).toHaveCount(0);
    });

    test("a calendar the remote will not accept writes to says so on the list", async ({ page }) => {
        const modal = page.locator("#modal-backdrop");

        await page.getByRole("button", { name: `Find calendars on ${SERVER}` }).click();
        await expect(modal.getByRole("checkbox", { name: new RegExp(HOLIDAYS) })).toBeVisible();

        await modal.getByRole("checkbox", { name: new RegExp(HOLIDAYS) }).check();
        await modal.getByRole("button", { name: "Save subscriptions" }).click();

        const row = page.locator("li", { hasText: HOLIDAYS }).first();

        await expect(row.getByText("Read-only")).toBeVisible();
    });

    /**
     * The case a Google account whose consent screen had the calendar scope
     * unticked lands in. The message is written to be read by a person and is
     * the only thing on screen that explains an empty calendar list, so a 500
     * here is worse than the missing calendars.
     */
    test("a connection that cannot list its calendars says why instead of failing", async ({ page }) => {
        await page.getByRole("button", { name: `Find calendars on ${BROKEN_SERVER}` }).click();

        const modal = page.locator("#modal-backdrop");
        const message = modal.getByText(/Reconnect the account and allow calendar access/);

        await expect(message).toBeVisible();

        // Not an error page, and no Save button offered for a list that does
        // not exist — pressing one would re-run the same failing discovery.
        await expect(page).toHaveURL(/\/settings/);
        await expect(modal.getByRole("button", { name: "Save subscriptions" })).toHaveCount(0);
    });

    /**
     * The credential decision, which is the one part of connecting a CalDAV
     * server that is not the server's business: reusing a stored mail password
     * has to be something a person turns on, never a default.
     */
    test("the CalDAV form never offers to reuse a mail password by default", async ({ page }) => {
        await page.getByRole("button", { name: "Connect a CalDAV server" }).click();

        const modal = page.locator("#modal-backdrop");
        const reuse = modal.getByRole("checkbox", { name: /Use the password from one of my mail accounts/ });

        await expect(reuse).toBeVisible();
        await expect(reuse).not.toBeChecked();

        // The suggestion, not a probe: plMail opens on the domain of the user's
        // own mailbox and lets RFC 6764 bootstrapping do the rest.
        await expect(modal.getByLabel("Server address")).not.toHaveValue("");
    });

    test("a CalDAV server that cannot be reached is reported, not saved silently", async ({ page }) => {
        await page.getByRole("button", { name: "Connect a CalDAV server" }).click();

        const modal = page.locator("#modal-backdrop");
        const address = modal.getByLabel("Server address");

        await expect(address).not.toHaveValue("");

        await address.fill("https://caldav.e2e-nowhere.invalid");
        // By role and accessible name rather than by label, and both halves
        // matter. getByLabel matches the label's TEXT, which for a required
        // field ends in the theme's "*" — so { exact: true } never matches and
        // an unanchored "Name" also claims "Username". The accessible name has
        // the asterisk hidden, so it is exactly "Name".
        await modal.getByRole("textbox", { name: "Name", exact: true }).fill("E2E unreachable server");
        await modal.getByRole("textbox", { name: "Username" }).fill("e2e");
        await modal.getByLabel("App password").fill("not-a-real-password");
        await modal.getByRole("button", { name: "Connect and find calendars" }).click();

        // The dialog stays open carrying the reason. It is marked
        // data-ui--modal-keep-open precisely so a successful connect can hand
        // straight over to the subscribe list; a failure has to stay put too.
        await expect(modal.getByRole("alert")).toBeVisible();

        // The connection is stored carrying its failure, the way every other
        // connect path stores one — so it has to be disconnectable from here.
        await page.goto("/settings?section=calendars");
        page.once("dialog", (dialog) => dialog.accept());
        await page.getByRole("button", { name: 'Disconnect "E2E unreachable server"' }).click();
        await expect(page.getByText("E2E unreachable server", { exact: true })).toHaveCount(0);
    });
});
